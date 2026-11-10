<?php

declare(strict_types=1);

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Accounting\DTOs\ProfitAndLossReportData;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class ProfitAndLossExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private ProfitAndLossReportData $report,
    ) {}

    public function title(): string
    {
        return 'Profit and Loss';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Account Code',
            'Category / Account Description',
            'Amount',
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function array(): array
    {
        $rows = [];

        // 1. Revenue
        $rows[] = ['1. REVENUE', 'Operating Revenue', round($this->report->totalRevenueCents / 100, 2)];
        foreach ($this->report->revenueAccounts as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }

        // 2. COGS
        $rows[] = ['2. COGS', 'Cost of Goods Sold', round($this->report->totalCogsCents / 100, 2)];
        foreach ($this->report->cogsAccounts as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }

        // Gross Profit
        $rows[] = ['GROSS PROFIT', 'Revenue minus Cost of Goods Sold', round($this->report->grossProfitCents / 100, 2)];

        // 3. Operating Expenses
        $rows[] = ['3. EXPENSES', 'Operating Expenses', round($this->report->totalOperatingExpensesCents / 100, 2)];
        foreach ($this->report->expenseAccounts as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }

        // Net Income
        $rows[] = ['NET INCOME / (LOSS)', 'Gross Profit minus Operating Expenses', round($this->report->netIncomeCents / 100, 2)];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
