<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Accounting\DTOs\BalanceSheetReportData;
use Modules\Accounting\DTOs\ProfitAndLossReportData;
use Modules\Accounting\DTOs\TrialBalanceReportData;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntryLine;

final class FinancialReportService
{
    /**
     * Compute Trial Balance as of a specific date.
     */
    public function getTrialBalance(?string $asOfDate = null, bool $includeZeroBalances = false): TrialBalanceReportData
    {
        $date = $asOfDate !== null ? Carbon::parse($asOfDate)->toDateString() : Carbon::now()->toDateString();

        $accounts = Account::query()->orderBy('code')->get();

        /** @var Collection<string, object{account_id: string, total_debit: int|string|null, total_credit: int|string|null}> $lineTotals */
        $lineTotals = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($query) use ($date): void {
                $query->where('status', JournalEntryStatus::Posted->value)
                    ->where('entry_date', '<=', $date);
            })
            ->selectRaw('account_id, SUM(debit_cents) as total_debit, SUM(credit_cents) as total_credit')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $reportAccounts = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $record = $lineTotals->get($account->id);
            $debit = $record !== null ? (int) $record->total_debit : 0;
            $credit = $record !== null ? (int) $record->total_credit : 0;

            if ($account->type->normalBalance() === 'debit') {
                $net = $debit - $credit;
                $debitBal = max(0, $net);
                $creditBal = $net < 0 ? abs($net) : 0;
            } else {
                $net = $credit - $debit;
                $debitBal = $net < 0 ? abs($net) : 0;
                $creditBal = max(0, $net);
            }

            if (! $includeZeroBalances && $debitBal === 0 && $creditBal === 0) {
                continue;
            }

            $totalDebits += $debitBal;
            $totalCredits += $creditBal;

            $reportAccounts[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'debit_balance_cents' => $debitBal,
                'credit_balance_cents' => $creditBal,
                'net_delta_cents' => $net,
            ];
        }

        $netDiff = $totalDebits - $totalCredits;

        return new TrialBalanceReportData(
            asOfDate: $date,
            accounts: $reportAccounts,
            totalDebitsCents: $totalDebits,
            totalCreditsCents: $totalCredits,
            isBalanced: $netDiff === 0,
            netDifferenceCents: $netDiff,
        );
    }

    /**
     * Compute Profit and Loss (Income Statement) for a period.
     */
    public function getProfitAndLoss(string $startDate, string $endDate): ProfitAndLossReportData
    {
        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate)->toDateString();

        $accounts = Account::query()
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        /** @var Collection<string, object{account_id: string, total_debit: int|string|null, total_credit: int|string|null}> $lineTotals */
        $lineTotals = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($query) use ($start, $end): void {
                $query->where('status', JournalEntryStatus::Posted->value)
                    ->whereBetween('entry_date', [$start, $end]);
            })
            ->selectRaw('account_id, SUM(debit_cents) as total_debit, SUM(credit_cents) as total_credit')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $revenueAccounts = [];
        $totalRevenue = 0;

        $cogsAccounts = [];
        $totalCogs = 0;

        $expenseAccounts = [];
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $record = $lineTotals->get($account->id);
            $debit = $record !== null ? (int) $record->total_debit : 0;
            $credit = $record !== null ? (int) $record->total_credit : 0;

            if ($account->type === AccountType::Income) {
                $netRevenue = $credit - $debit;
                if ($netRevenue !== 0) {
                    $revenueAccounts[] = [
                        'account_id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'amount_cents' => $netRevenue,
                    ];
                    $totalRevenue += $netRevenue;
                }
            } elseif ($account->type === AccountType::Expense) {
                $netExpense = $debit - $credit;
                if ($netExpense !== 0) {
                    // Check if COGS (code starts with 5 or system cogs)
                    if (str_starts_with($account->code, '5') || $account->system_name === 'cogs') {
                        $cogsAccounts[] = [
                            'account_id' => $account->id,
                            'code' => $account->code,
                            'name' => $account->name,
                            'amount_cents' => $netExpense,
                        ];
                        $totalCogs += $netExpense;
                    } else {
                        $expenseAccounts[] = [
                            'account_id' => $account->id,
                            'code' => $account->code,
                            'name' => $account->name,
                            'amount_cents' => $netExpense,
                        ];
                        $totalExpenses += $netExpense;
                    }
                }
            }
        }

        $grossProfit = $totalRevenue - $totalCogs;
        $netIncome = $grossProfit - $totalExpenses;

        return new ProfitAndLossReportData(
            startDate: $start,
            endDate: $end,
            revenueAccounts: $revenueAccounts,
            totalRevenueCents: $totalRevenue,
            cogsAccounts: $cogsAccounts,
            totalCogsCents: $totalCogs,
            grossProfitCents: $grossProfit,
            expenseAccounts: $expenseAccounts,
            totalOperatingExpensesCents: $totalExpenses,
            netIncomeCents: $netIncome,
        );
    }

    /**
     * Compute Balance Sheet as of a specific date.
     */
    public function getBalanceSheet(?string $asOfDate = null): BalanceSheetReportData
    {
        $date = $asOfDate !== null ? Carbon::parse($asOfDate)->toDateString() : Carbon::now()->toDateString();

        $accounts = Account::query()->orderBy('code')->get();

        /** @var Collection<string, object{account_id: string, total_debit: int|string|null, total_credit: int|string|null}> $lineTotals */
        $lineTotals = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($query) use ($date): void {
                $query->where('status', JournalEntryStatus::Posted->value)
                    ->where('entry_date', '<=', $date);
            })
            ->selectRaw('account_id, SUM(debit_cents) as total_debit, SUM(credit_cents) as total_credit')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $currentAssets = [];
        $nonCurrentAssets = [];
        $totalAssets = 0;

        $currentLiabilities = [];
        $nonCurrentLiabilities = [];
        $totalLiabilities = 0;

        $equityAccounts = [];
        $totalEquityWithoutIncome = 0;

        $lifetimeIncome = 0;
        $lifetimeExpense = 0;

        foreach ($accounts as $account) {
            $record = $lineTotals->get($account->id);
            $debit = $record !== null ? (int) $record->total_debit : 0;
            $credit = $record !== null ? (int) $record->total_credit : 0;

            if ($account->type === AccountType::Asset) {
                $netAsset = $debit - $credit;
                if ($netAsset !== 0) {
                    $item = [
                        'account_id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'amount_cents' => $netAsset,
                    ];
                    if (str_starts_with($account->code, '11')) {
                        $currentAssets[] = $item;
                    } else {
                        $nonCurrentAssets[] = $item;
                    }
                    $totalAssets += $netAsset;
                }
            } elseif ($account->type === AccountType::Liability) {
                $netLiability = $credit - $debit;
                if ($netLiability !== 0) {
                    $item = [
                        'account_id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'amount_cents' => $netLiability,
                    ];
                    if (str_starts_with($account->code, '21')) {
                        $currentLiabilities[] = $item;
                    } else {
                        $nonCurrentLiabilities[] = $item;
                    }
                    $totalLiabilities += $netLiability;
                }
            } elseif ($account->type === AccountType::Equity) {
                $netEquity = $credit - $debit;
                if ($netEquity !== 0) {
                    $equityAccounts[] = [
                        'account_id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'amount_cents' => $netEquity,
                    ];
                    $totalEquityWithoutIncome += $netEquity;
                }
            } elseif ($account->type === AccountType::Income) {
                $lifetimeIncome += ($credit - $debit);
            } elseif ($account->type === AccountType::Expense) {
                $lifetimeExpense += ($debit - $credit);
            }
        }

        // Retained / Current Period Earnings = Total Lifetime Income - Total Lifetime Expense
        $currentPeriodEarnings = $lifetimeIncome - $lifetimeExpense;
        $totalEquity = $totalEquityWithoutIncome + $currentPeriodEarnings;

        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;
        $netDiff = $totalAssets - $totalLiabilitiesAndEquity;

        return new BalanceSheetReportData(
            asOfDate: $date,
            assets: [
                'current' => $currentAssets,
                'non_current' => $nonCurrentAssets,
                'total_cents' => $totalAssets,
            ],
            liabilities: [
                'current' => $currentLiabilities,
                'non_current' => $nonCurrentLiabilities,
                'total_cents' => $totalLiabilities,
            ],
            equity: [
                'accounts' => $equityAccounts,
                'current_period_earnings_cents' => $currentPeriodEarnings,
                'total_cents' => $totalEquity,
            ],
            totalLiabilitiesAndEquityCents: $totalLiabilitiesAndEquity,
            isBalanced: $netDiff === 0,
            netDifferenceCents: $netDiff,
        );
    }

    /**
     * Get net account balance by code as of a specific date (in minor unit integer cents).
     */
    public function getAccountBalanceByCode(string $code, ?string $asOfDate = null): int
    {
        $date = $asOfDate !== null ? Carbon::parse($asOfDate)->toDateString() : Carbon::now()->toDateString();
        $account = Account::query()->where('code', $code)->first();
        if ($account === null) {
            return 0;
        }

        /** @var object{total_debit: int|string|null, total_credit: int|string|null}|null $totals */
        $totals = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($query) use ($date): void {
                $query->where('status', JournalEntryStatus::Posted->value)
                    ->where('entry_date', '<=', $date);
            })
            ->selectRaw('SUM(debit_cents) as total_debit, SUM(credit_cents) as total_credit')
            ->first();

        $debit = $totals !== null ? (int) $totals->total_debit : 0;
        $credit = $totals !== null ? (int) $totals->total_credit : 0;

        if ($account->type->normalBalance() === 'debit') {
            return $debit - $credit;
        }

        return $credit - $debit;
    }
}
