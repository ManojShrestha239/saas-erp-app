<?php

declare(strict_types=1);

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Accounting\DTOs\BalanceSheetReportData;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class BalanceSheetExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private BalanceSheetReportData $report,
    ) {}

    public function title(): string
    {
        return 'Balance Sheet';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Account Code',
            'Account / Category Description',
            'Amount',
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function array(): array
    {
        $rows = [];

        // 1. Assets
        $rows[] = ['1. ASSETS', 'Total Assets', round($this->report->assets['total_cents'] / 100, 2)];
        $rows[] = ['', 'Current Assets', ''];
        foreach ($this->report->assets['current'] as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }
        $rows[] = ['', 'Non-Current Assets', ''];
        foreach ($this->report->assets['non_current'] as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }

        // 2. Liabilities
        $rows[] = ['2. LIABILITIES', 'Total Liabilities', round($this->report->liabilities['total_cents'] / 100, 2)];
        $rows[] = ['', 'Current Liabilities', ''];
        foreach ($this->report->liabilities['current'] as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }
        $rows[] = ['', 'Long-Term Liabilities', ''];
        foreach ($this->report->liabilities['non_current'] as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }

        // 3. Equity
        $rows[] = ['3. EQUITY', 'Total Equity', round($this->report->equity['total_cents'] / 100, 2)];
        foreach ($this->report->equity['accounts'] as $acc) {
            $rows[] = [$acc['code'], '  '.$acc['name'], round($acc['amount_cents'] / 100, 2)];
        }
        $rows[] = ['EARNINGS', '  Current Period Net Earnings', round($this->report->equity['current_period_earnings_cents'] / 100, 2)];

        // Grand Total
        $rows[] = ['TOTAL LIAB & EQUITY', 'Total Liabilities plus Equity', round($this->report->totalLiabilitiesAndEquityCents / 100, 2)];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
