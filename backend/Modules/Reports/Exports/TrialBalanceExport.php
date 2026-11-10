<?php

declare(strict_types=1);

namespace Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Accounting\DTOs\TrialBalanceReportData;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class TrialBalanceExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private TrialBalanceReportData $report,
    ) {}

    public function title(): string
    {
        return 'Trial Balance';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Account Code',
            'Account Name',
            'Type',
            'Debit Balance',
            'Credit Balance',
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function array(): array
    {
        $rows = [];

        foreach ($this->report->accounts as $acc) {
            $rows[] = [
                $acc['code'],
                $acc['name'],
                ucfirst($acc['type']),
                $acc['debit_balance_cents'] > 0 ? round($acc['debit_balance_cents'] / 100, 2) : 0.0,
                $acc['credit_balance_cents'] > 0 ? round($acc['credit_balance_cents'] / 100, 2) : 0.0,
            ];
        }

        // Totals Row
        $rows[] = [
            'TOTAL',
            'Total Debits & Credits',
            $this->report->isBalanced ? 'Balanced' : 'Imbalanced',
            round($this->report->totalDebitsCents / 100, 2),
            round($this->report->totalCreditsCents / 100, 2),
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $totalRowIndex = count($this->report->accounts) + 2;

        return [
            1 => ['font' => ['bold' => true]],
            $totalRowIndex => ['font' => ['bold' => true]],
        ];
    }
}
