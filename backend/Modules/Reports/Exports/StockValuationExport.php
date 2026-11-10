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

final readonly class StockValuationExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data,
    ) {}

    public function title(): string
    {
        return 'Stock Valuation';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'SKU',
            'Product Name',
            'Category',
            'Qty on Hand',
            'Moving Avg Cost',
            'Selling Price',
            'Total Valuation',
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function array(): array
    {
        $rows = [];
        $items = $this->data['items'] ?? [];

        foreach ($items as $item) {
            $rows[] = [
                $item['sku'] ?? '',
                $item['name'] ?? '',
                $item['category_name'] ?? '—',
                $item['total_quantity_on_hand'] ?? 0,
                round(($item['moving_average_cost_cents'] ?? 0) / 100, 2),
                round(($item['selling_price_cents'] ?? 0) / 100, 2),
                round(($item['total_valuation_cents'] ?? 0) / 100, 2),
            ];
        }

        $summary = $this->data['summary'] ?? [];

        // Summary row
        $rows[] = [
            'TOTAL',
            'Total Inventory Valuation',
            '',
            $summary['total_units_on_hand'] ?? 0,
            '',
            '',
            round(($summary['total_valuation_cents'] ?? 0) / 100, 2),
        ];

        // GL Reconciliation Row
        $glBalance = (int) ($summary['gl_control_account_cents'] ?? 0);
        $diff = (int) ($summary['reconciliation_difference_cents'] ?? 0);

        $rows[] = [
            'GL RECONCILIATION',
            'Control Account 1150 (Inventory Asset)',
            'GL: '.round($glBalance / 100, 2),
            'Diff: '.round($diff / 100, 2),
            ! empty($summary['is_reconciled']) ? 'RECONCILED' : 'DISCREPANCY',
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
