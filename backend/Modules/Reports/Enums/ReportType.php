<?php

declare(strict_types=1);

namespace Modules\Reports\Enums;

enum ReportType: string
{
    case TrialBalance = 'trial-balance';
    case ProfitAndLoss = 'profit-and-loss';
    case BalanceSheet = 'balance-sheet';
    case ArAging = 'ar-aging';
    case ApAging = 'ap-aging';
    case StockValuation = 'stock-valuation';
    case StockMovements = 'stock-movements';

    public function requiredPermission(): string
    {
        return match ($this) {
            self::TrialBalance,
            self::ProfitAndLoss,
            self::BalanceSheet,
            self::ArAging,
            self::ApAging => 'reports.financial',

            self::StockValuation,
            self::StockMovements => 'reports.stock',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::TrialBalance => 'Trial Balance',
            self::ProfitAndLoss => 'Profit and Loss Statement',
            self::BalanceSheet => 'Balance Sheet',
            self::ArAging => 'Accounts Receivable Aging Report',
            self::ApAging => 'Accounts Payable Aging Report',
            self::StockValuation => 'Stock Valuation Report',
            self::StockMovements => 'Stock Movement History',
        };
    }
}
