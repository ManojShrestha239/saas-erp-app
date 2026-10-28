<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Enums\TaxCategory;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\UnitOfMeasure;
use Shared\Enums\CsvDuplicateAction;
use Shared\Enums\CsvImportStatus;
use Shared\Models\CsvImport;
use Shared\Money\MoneyParser;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

final class ProductCsvImportService
{
    public function __construct(
        private readonly TenantManager $tenantManager,
    ) {}

    /**
     * Process product CSV import within tenant context.
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
        if (! isset($headerMap['sku']) || ! isset($headerMap['name'])) {
            fclose($handle);
            $import->update([
                'status' => CsvImportStatus::Failed,
                'errors' => [['row' => 0, 'message' => 'CSV must contain at least "sku" and "name" columns.']],
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
                $this->processProductRow($rowData, $import->duplicate_action, $rowNumber);
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
    private function processProductRow(array $row, CsvDuplicateAction $duplicateAction, int $rowNumber): void
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku === '') {
            throw new \InvalidArgumentException("Row {$rowNumber}: SKU cannot be empty.");
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException("Row {$rowNumber}: Product name cannot be empty.");
        }

        $costPrice = isset($row['cost_price']) ? MoneyParser::toCents((string) $row['cost_price']) : 0;
        if ($costPrice < 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: Cost price cannot be negative.");
        }

        $sellingPrice = isset($row['selling_price']) ? MoneyParser::toCents((string) $row['selling_price']) : 0;
        if ($sellingPrice < 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: Selling price cannot be negative.");
        }

        $taxCategory = TaxCategory::Standard;
        if (isset($row['tax_category'])) {
            $taxCategoryVal = strtolower(trim((string) $row['tax_category']));
            $taxCategory = match ($taxCategoryVal) {
                'zero_rated', 'zero' => TaxCategory::ZeroRated,
                'exempt' => TaxCategory::Exempt,
                default => TaxCategory::Standard,
            };
        }

        $lowStockThreshold = max(0, (int) ($row['low_stock_threshold'] ?? 0));
        $barcode = ! empty($row['barcode']) ? trim((string) $row['barcode']) : null;
        $description = ! empty($row['description']) ? trim((string) $row['description']) : null;
        $isActive = true;
        if (isset($row['is_active'])) {
            $activeVal = strtolower(trim((string) $row['is_active']));
            $isActive = ! in_array($activeVal, ['0', 'false', 'no', 'inactive'], true);
        }

        DB::connection('tenant')->transaction(function () use (
            $sku,
            $name,
            $costPrice,
            $sellingPrice,
            $taxCategory,
            $lowStockThreshold,
            $barcode,
            $description,
            $isActive,
            $row,
            $duplicateAction
        ): void {
            $categoryId = null;
            if (! empty($row['category'])) {
                $categoryName = trim((string) $row['category']);
                /** @var Category $category */
                $category = Category::query()->firstOrCreate(
                    ['name' => $categoryName],
                    [
                        'slug' => Str::slug($categoryName),
                        'description' => null,
                        'is_active' => true,
                    ]
                );
                $categoryId = $category->id;
            }

            $uomId = null;
            if (! empty($row['unit_of_measure'])) {
                $uomSymbol = strtoupper(trim((string) $row['unit_of_measure']));
                /** @var UnitOfMeasure|null $uom */
                $uom = UnitOfMeasure::query()->where('symbol', $uomSymbol)->first();
                if ($uom === null) {
                    $uom = UnitOfMeasure::query()->create([
                        'symbol' => substr($uomSymbol, 0, 15),
                        'name' => trim((string) $row['unit_of_measure']),
                        'is_active' => true,
                    ]);
                }
                $uomId = $uom->id;
            }

            /** @var Product|null $existing */
            $existing = Product::query()->where('sku', $sku)->lockForUpdate()->first();

            if ($existing !== null) {
                if ($duplicateAction === CsvDuplicateAction::Skip) {
                    return;
                }

                $existing->update([
                    'name' => $name,
                    'cost_price_cents' => $costPrice,
                    'selling_price_cents' => $sellingPrice,
                    'category_id' => $categoryId ?? $existing->category_id,
                    'unit_of_measure_id' => $uomId ?? $existing->unit_of_measure_id,
                    'tax_category' => $taxCategory,
                    'low_stock_threshold' => $lowStockThreshold,
                    'barcode' => $barcode ?? $existing->barcode,
                    'description' => $description ?? $existing->description,
                    'is_active' => $isActive,
                ]);

                return;
            }

            Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'cost_price_cents' => $costPrice,
                'selling_price_cents' => $sellingPrice,
                'category_id' => $categoryId,
                'unit_of_measure_id' => $uomId,
                'tax_category' => $taxCategory,
                'low_stock_threshold' => $lowStockThreshold,
                'barcode' => $barcode,
                'description' => $description,
                'is_active' => $isActive,
            ]);
        }, 3);
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
                'sku', 'item_code', 'product_code' => 'sku',
                'name', 'product_name', 'item_name', 'title' => 'name',
                'cost', 'cost_price', 'cost_price_cents', 'buying_price' => 'cost_price',
                'price', 'selling_price', 'sell_price', 'unit_price', 'selling_price_cents' => 'selling_price',
                'category', 'category_name' => 'category',
                'uom', 'unit', 'unit_of_measure', 'uom_code' => 'unit_of_measure',
                'barcode', 'upc', 'ean' => 'barcode',
                'description', 'desc', 'notes' => 'description',
                'tax', 'tax_category', 'tax_rate' => 'tax_category',
                'threshold', 'low_stock_threshold', 'min_stock', 'safety_stock' => 'low_stock_threshold',
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
            'sku',
            'name',
            'cost_price',
            'selling_price',
            'category',
            'unit_of_measure',
            'barcode',
            'description',
            'tax_category',
            'low_stock_threshold',
            'is_active',
        ];

        $sample1 = [
            'SKU-WIDGET-01',
            'Standard Widget Pro',
            '12.50',
            '25.00',
            'Hardware',
            'PCS',
            '123456789012',
            'High quality professional widget',
            'standard',
            '10',
            'true',
        ];

        $sample2 = [
            'SKU-GADGET-02',
            'Compact Gadget Mini',
            '5.00',
            '9.99',
            'Electronics',
            'PCS',
            '987654321098',
            'Portable mini electronic gadget',
            'standard',
            '5',
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
