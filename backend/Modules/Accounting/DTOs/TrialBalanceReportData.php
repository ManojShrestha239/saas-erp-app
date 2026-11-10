<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

final readonly class TrialBalanceReportData
{
    /**
     * @param list<array{
     *     account_id: string,
     *     code: string,
     *     name: string,
     *     type: string,
     *     debit_balance_cents: int,
     *     credit_balance_cents: int,
     *     net_delta_cents: int
     * }> $accounts
     */
    public function __construct(
        public string $asOfDate,
        public array $accounts,
        public int $totalDebitsCents,
        public int $totalCreditsCents,
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
            'accounts' => $this->accounts,
            'total_debits_cents' => $this->totalDebitsCents,
            'total_credits_cents' => $this->totalCreditsCents,
            'is_balanced' => $this->isBalanced,
            'net_difference_cents' => $this->netDifferenceCents,
        ];
    }
}
