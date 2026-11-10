<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

final readonly class ProfitAndLossReportData
{
    /**
     * @param list<array{
     *     account_id: string,
     *     code: string,
     *     name: string,
     *     amount_cents: int
     * }> $revenueAccounts
     * @param list<array{
     *     account_id: string,
     *     code: string,
     *     name: string,
     *     amount_cents: int
     * }> $cogsAccounts
     * @param list<array{
     *     account_id: string,
     *     code: string,
     *     name: string,
     *     amount_cents: int
     * }> $expenseAccounts
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $revenueAccounts,
        public int $totalRevenueCents,
        public array $cogsAccounts,
        public int $totalCogsCents,
        public int $grossProfitCents,
        public array $expenseAccounts,
        public int $totalOperatingExpensesCents,
        public int $netIncomeCents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'revenue_accounts' => $this->revenueAccounts,
            'total_revenue_cents' => $this->totalRevenueCents,
            'cogs_accounts' => $this->cogsAccounts,
            'total_cogs_cents' => $this->totalCogsCents,
            'gross_profit_cents' => $this->grossProfitCents,
            'expense_accounts' => $this->expenseAccounts,
            'total_operating_expenses_cents' => $this->totalOperatingExpensesCents,
            'net_income_cents' => $this->netIncomeCents,
        ];
    }
}
