<?php

declare(strict_types=1);

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class AgingExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data,
        private string $type = 'ar',
    ) {}

    public function title(): string
    {
        return $this->type === 'ar' ? 'AR Aging' : 'AP Aging';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $contactHeader = $this->type === 'ar' ? 'Customer' : 'Supplier';

        return [
            $contactHeader.' Code',
            $contactHeader.' Name',
            'Current',
            '1–30 Days',
            '31–60 Days',
            '61–90 Days',
            '90+ Days',
            'Total Due',
            'Doc Count',
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function array(): array
    {
        $rows = [];
        $records = $this->type === 'ar' ? ($this->data['customers'] ?? []) : ($this->data['suppliers'] ?? []);

        foreach ($records as $item) {
            $code = $this->type === 'ar' ? ($item['customer_code'] ?? '') : ($item['supplier_code'] ?? '');
            $name = $this->type === 'ar' ? ($item['customer_name'] ?? '') : ($item['supplier_name'] ?? '');
            $count = $this->type === 'ar' ? ($item['invoice_count'] ?? 0) : ($item['bill_count'] ?? 0);

            $rows[] = [
                $code,
                $name,
                round(($item['current_cents'] ?? 0) / 100, 2),
                round(($item['days_1_30_cents'] ?? 0) / 100, 2),
                round(($item['days_31_60_cents'] ?? 0) / 100, 2),
                round(($item['days_61_90_cents'] ?? 0) / 100, 2),
                round(($item['days_over_90_cents'] ?? 0) / 100, 2),
                round(($item['total_due_cents'] ?? 0) / 100, 2),
                $count,
            ];
        }

        $summary = $this->data['summary'] ?? [];

        // Summary total row
        $rows[] = [
            'TOTAL',
            'Summary Totals',
            round(($summary['current_cents'] ?? 0) / 100, 2),
            round(($summary['days_1_30_cents'] ?? 0) / 100, 2),
            round(($summary['days_31_60_cents'] ?? 0) / 100, 2),
            round(($summary['days_61_90_cents'] ?? 0) / 100, 2),
            round(($summary['days_over_90_cents'] ?? 0) / 100, 2),
            round(($summary['total_due_cents'] ?? 0) / 100, 2),
            '',
        ];

        // GL Reconciliation Row
        $glBalance = (int) ($this->data['gl_control_account_cents'] ?? 0);
        $diff = (int) ($this->data['reconciliation_difference_cents'] ?? 0);
        $accountCode = $this->type === 'ar' ? '1130 (AR)' : '2110 (AP)';

        $rows[] = [
            'GL RECONCILIATION',
            'Control Account '.$accountCode,
            'GL Balance: '.round($glBalance / 100, 2),
            'Diff: '.round($diff / 100, 2),
            ! empty($this->data['is_reconciled']) ? 'RECONCILED' : 'DISCREPANCY',
            '',
            '',
            '',
            '',
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
