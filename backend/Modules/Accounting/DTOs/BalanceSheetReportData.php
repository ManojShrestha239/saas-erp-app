<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

final readonly class BalanceSheetReportData
{
    /**
     * @param array{
     *     current: list<array{account_id: string, code: string, name: string, amount_cents: int}>,
     *     non_current: list<array{account_id: string, code: string, name: string, amount_cents: int}>,
     *     total_cents: int
     * } $assets
     * @param array{
     *     current: list<array{account_id: string, code: string, name: string, amount_cents: int}>,
     *     non_current: list<array{account_id: string, code: string, name: string, amount_cents: int}>,
     *     total_cents: int
     * } $liabilities
     * @param array{
     *     accounts: list<array{account_id: string, code: string, name: string, amount_cents: int}>,
     *     current_period_earnings_cents: int,
     *     total_cents: int
     * } $equity
     */
    public function __construct(
        public string $asOfDate,
        public array $assets,
        public array $liabilities,
        public array $equity,
        public int $totalLiabilitiesAndEquityCents,
        public bool $isBalanced,
        public int $netDifferenceCents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'as_of_date' => $this->asOfDate,
            'assets' => $this->assets,
            'liabilities' => $this->liabilities,
            'equity' => $this->equity,
            'total_liabilities_and_equity_cents' => $this->totalLiabilitiesAndEquityCents,
            'is_balanced' => $this->isBalanced,
            'net_difference_cents' => $this->netDifferenceCents,
        ];
    }
}
