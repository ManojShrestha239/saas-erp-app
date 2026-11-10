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

final readonly class StockMovementExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        private array $items,
    ) {}

    public function title(): string
    {
        return 'Stock Movements';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Timestamp',
            'Product Name',
            'SKU',
            'Warehouse',
            'Movement Type',
            'Quantity',
            'Unit Cost',
            'Notes',
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function array(): array
    {
        $rows = [];

        foreach ($this->items as $item) {
            $product = $item['product'] ?? null;
            $warehouse = $item['warehouse'] ?? null;

            $rows[] = [
                $item['created_at'] ?? '',
                $product['name'] ?? $item['product_id'] ?? '',
                $product['sku'] ?? '',
                $warehouse['name'] ?? $item['warehouse_id'] ?? '',
                $item['type_label'] ?? $item['type'] ?? '',
                $item['quantity'] ?? 0,
                round(($item['unit_cost_cents'] ?? 0) / 100, 2),
                $item['notes'] ?? '',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
