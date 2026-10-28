<?php

declare(strict_types=1);

namespace Modules\Contacts\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Shared\Enums\CsvDuplicateAction;
use Shared\Enums\CsvImportStatus;
use Shared\Models\CsvImport;
use Shared\Money\MoneyParser;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

final class ContactCsvImportService
{
    public function __construct(
        private readonly TenantManager $tenantManager,
    ) {}

    /**
     * Process contact CSV import within tenant context.
     */
    public function process(CsvImport $import, string $tenantId, string $databaseName): void
    {
        $context = new TenantContext(
            id: $tenantId,
            slug: '',
            databaseName: $databaseName,
        );

        $this->tenantManager->switchTo($context);

        $import->update([
            'status' => CsvImportStatus::Processing,
            'started_at' => now(),
        ]);

        $filePath = Storage::disk('local')->path($import->file_path);

        if (! file_exists($filePath)) {
            $import->update([
                'status' => CsvImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => 'Uploaded import file not found on disk.']],
                'completed_at' => now(),
            ]);

            return;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $import->update([
                'status' => CsvImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => 'Unable to open CSV file for reading.']],
                'completed_at' => now(),
            ]);

            return;
        }

        // Handle possible UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);
            $import->update([
                'status' => CsvImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => 'CSV file is empty or missing headers.']],
                'completed_at' => now(),
            ]);

            return;
        }

        $headerMap = $this->mapHeaders($headerRow);
        if (! isset($headerMap['name'])) {
            fclose($handle);
            $import->update([
                'status' => CsvImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => 'CSV must contain at least a "name" column.']],
                'completed_at' => now(),
            ]);

            return;
        }

        $rowNumber = 1; // header is row 1
        $totalRows = 0;
        $processedRows = 0;
        $successfulRows = 0;
        $failedRows = 0;
        /** @var list<array{row: int, field?: string, message: string, raw_data?: array<string, mixed>}> $errors */
        $errors = [];

        // Pre-count data rows
        while (! feof($handle)) {
            $line = fgetcsv($handle);
            if ($line !== false && array_filter($line) !== []) {
                $totalRows++;
            }
        }
        rewind($handle);
        // Skip BOM and header
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        fgetcsv($handle);

        $import->update(['total_rows' => $totalRows]);

        while (($rawRow = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (array_filter($rawRow) === []) {
                continue;
            }

            $processedRows++;
            $rowData = $this->extractRowData($rawRow, $headerMap);

            try {
                $this->processContactRow($rowData, $import->duplicate_action, $rowNumber);
                $successfulRows++;
            } catch (Throwable $e) {
                $failedRows++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                    'raw_data' => $rowData,
                ];
            }
        }

        fclose($handle);

        $finalStatus = ($successfulRows === 0 && $failedRows > 0)
            ? CsvImportStatus::Failed
            : CsvImportStatus::Completed;

        $import->update([
            'status' => $finalStatus,
            'processed_rows' => $processedRows,
            'successful_rows' => $successfulRows,
            'failed_rows' => $failedRows,
            'errors' => $errors !== [] ? $errors : null,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processContactRow(array $row, CsvDuplicateAction $duplicateAction, int $rowNumber): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException("Row {$rowNumber}: Contact name cannot be empty.");
        }

        $rawType = strtolower(trim((string) ($row['type'] ?? 'customer')));
        $type = match ($rawType) {
            'supplier', 'vendor' => ContactType::Supplier,
            'both' => ContactType::Both,
            default => ContactType::Customer,
        };

        $code = ! empty($row['code']) ? trim((string) $row['code']) : null;
        $taxId = ! empty($row['tax_id']) ? trim((string) $row['tax_id']) : null;
        $email = ! empty($row['email']) ? trim((string) $row['email']) : null;
        $phone = ! empty($row['phone']) ? trim((string) $row['phone']) : null;
        $paymentTerms = max(0, (int) ($row['payment_terms_days'] ?? 30));

        $creditLimit = isset($row['credit_limit']) ? MoneyParser::toCents((string) $row['credit_limit']) : 0;
        if ($creditLimit < 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: Credit limit cannot be negative.");
        }

        $openingBalance = isset($row['opening_balance']) ? MoneyParser::toCents((string) $row['opening_balance']) : 0;
        $notes = ! empty($row['notes']) ? trim((string) $row['notes']) : null;

        $isActive = true;
        if (isset($row['is_active'])) {
            $activeVal = strtolower(trim((string) $row['is_active']));
            $isActive = ! in_array($activeVal, ['0', 'false', 'no', 'inactive'], true);
        }

        $billingAddress = null;
        if (! empty($row['address_line1']) || ! empty($row['city']) || ! empty($row['country'])) {
            $billingAddress = [
                'line1' => trim((string) ($row['address_line1'] ?? '')),
                'city' => trim((string) ($row['city'] ?? '')),
                'state' => trim((string) ($row['state'] ?? '')),
                'postal_code' => trim((string) ($row['postal_code'] ?? '')),
                'country' => strtoupper(trim((string) ($row['country'] ?? 'US'))),
            ];
        }

        DB::connection('tenant')->transaction(function () use (
            $type,
            $code,
            $name,
            $taxId,
            $email,
            $phone,
            $paymentTerms,
            $creditLimit,
            $openingBalance,
            $notes,
            $isActive,
            $billingAddress,
            $duplicateAction
        ): void {
            // Find existing by code first, then by email if code was not explicitly provided
            /** @var Contact|null $existing */
            $existing = null;
            if ($code !== null) {
                $existing = Contact::query()->where('code', $code)->lockForUpdate()->first();
            } elseif ($email !== null) {
                $existing = Contact::query()->where('email', $email)->lockForUpdate()->first();
            }

            if ($existing !== null) {
                if ($duplicateAction === CsvDuplicateAction::Skip) {
                    return;
                }

                $existing->update([
                    'name' => $name,
                    'type' => $type,
                    'tax_id' => $taxId ?? $existing->tax_id,
                    'email' => $email ?? $existing->email,
                    'phone' => $phone ?? $existing->phone,
                    'payment_terms_days' => $paymentTerms,
                    'credit_limit_cents' => $creditLimit,
                    'opening_balance_cents' => $openingBalance !== 0 ? $openingBalance : $existing->opening_balance_cents,
                    'is_active' => $isActive,
                    'notes' => $notes ?? $existing->notes,
                    'billing_address' => $billingAddress ?? $existing->billing_address,
                ]);

                return;
            }

            $finalCode = $code ?? $this->generateNextCode($type);

            Contact::query()->create([
                'type' => $type,
                'code' => $finalCode,
                'name' => $name,
                'tax_id' => $taxId,
                'email' => $email,
                'phone' => $phone,
                'payment_terms_days' => $paymentTerms,
                'credit_limit_cents' => $creditLimit,
                'opening_balance_cents' => $openingBalance,
                'is_active' => $isActive,
                'notes' => $notes,
                'billing_address' => $billingAddress,
            ]);
        }, 3);
    }

    private function generateNextCode(ContactType $type): string
    {
        $prefix = match ($type) {
            ContactType::Customer => 'CUST',
            ContactType::Supplier => 'SUPP',
            ContactType::Both => 'CONT',
        };

        $count = Contact::query()->where('code', 'like', "{$prefix}-%")->count() + 1;
        $code = sprintf('%s-%06d', $prefix, $count);

        while (Contact::query()->where('code', $code)->exists()) {
            $count++;
            $code = sprintf('%s-%06d', $prefix, $count);
        }

        return $code;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array<string, int>
     */
    private function mapHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $header) {
            if ($header === null) {
                continue;
            }

            $normalized = strtolower(trim($header));
            $normalized = str_replace([' ', '-', '.'], '_', $normalized);

            $key = match ($normalized) {
                'name', 'contact_name', 'company_name', 'legal_name' => 'name',
                'type', 'contact_type', 'role' => 'type',
                'code', 'contact_code', 'account_number' => 'code',
                'tax_id', 'vat', 'vat_number', 'ein', 'tax_number' => 'tax_id',
                'email', 'email_address' => 'email',
                'phone', 'phone_number', 'mobile', 'telephone' => 'phone',
                'payment_terms', 'payment_terms_days', 'terms' => 'payment_terms_days',
                'credit_limit', 'credit_limit_cents', 'limit' => 'credit_limit',
                'opening_balance', 'opening_balance_cents', 'balance' => 'opening_balance',
                'notes', 'remarks', 'comments' => 'notes',
                'address', 'address_line1', 'street', 'billing_address', 'billing_address_line1' => 'address_line1',
                'city', 'billing_city' => 'city',
                'state', 'province', 'billing_state' => 'state',
                'zip', 'postal_code', 'zip_code', 'billing_postal_code' => 'postal_code',
                'country', 'billing_country' => 'country',
                'active', 'is_active', 'status' => 'is_active',
                default => $normalized,
            };

            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $headerMap
     * @return array<string, mixed>
     */
    private function extractRowData(array $row, array $headerMap): array
    {
        $data = [];
        foreach ($headerMap as $key => $index) {
            $data[$key] = $row[$index] ?? null;
        }

        return $data;
    }

    public function generateTemplateCsv(): string
    {
        $headers = [
            'type',
            'name',
            'code',
            'tax_id',
            'email',
            'phone',
            'payment_terms_days',
            'credit_limit',
            'opening_balance',
            'address_line1',
            'city',
            'country',
            'notes',
            'is_active',
        ];

        $sample1 = [
            'customer',
            'Acme Industrial Supplies LLC',
            'CUST-000001',
            'US123456789',
            'billing@acmeindustrial.com',
            '+1-555-0199',
            '30',
            '5000.00',
            '0.00',
            '100 Main Street Suite 400',
            'New York',
            'US',
            'Key commercial client',
            'true',
        ];

        $sample2 = [
            'supplier',
            'Global Metals & Parts Inc',
            'SUPP-000001',
            'US987654321',
            'accounts@globalmetals.com',
            '+1-555-0244',
            '45',
            '0.00',
            '0.00',
            '75 Industrial Parkway',
            'Chicago',
            'US',
            'Primary raw material vendor',
            'true',
        ];

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers);
        fputcsv($handle, $sample1);
        fputcsv($handle, $sample2);

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}
