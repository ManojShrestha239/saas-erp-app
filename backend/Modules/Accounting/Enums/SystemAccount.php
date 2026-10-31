<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

enum SystemAccount: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case AccountsReceivable = 'accounts_receivable';
    case InventoryAsset = 'inventory_asset';
    case InputVat = 'input_vat';
    case AccountsPayable = 'accounts_payable';
    case OutputVat = 'output_vat';
    case RetainedEarnings = 'retained_earnings';
    case OpeningBalanceEquity = 'opening_balance_equity';
    case SalesRevenue = 'sales_revenue';
    case CostOfGoodsSold = 'cogs';
    case InventoryAdjustment = 'inventory_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash on Hand',
            self::Bank => 'Operating Bank Account',
            self::AccountsReceivable => 'Accounts Receivable (Trade Debtors)',
            self::InventoryAsset => 'Inventory Asset',
            self::InputVat => 'Input VAT (Recoverable)',
            self::AccountsPayable => 'Accounts Payable (Trade Creditors)',
            self::OutputVat => 'Output VAT (Payable)',
            self::RetainedEarnings => 'Retained Earnings',
            self::OpeningBalanceEquity => 'Opening Balance Equity',
            self::SalesRevenue => 'Sales Revenue',
            self::CostOfGoodsSold => 'Cost of Goods Sold (COGS)',
            self::InventoryAdjustment => 'Inventory Adjustment',
        };
    }

    public function defaultCode(): string
    {
        return match ($this) {
            self::Cash => '1110',
            self::Bank => '1120',
            self::AccountsReceivable => '1130',
            self::InventoryAsset => '1150',
            self::InputVat => '1160',
            self::AccountsPayable => '2110',
            self::OutputVat => '2120',
            self::RetainedEarnings => '3200',
            self::OpeningBalanceEquity => '3300',
            self::SalesRevenue => '4110',
            self::CostOfGoodsSold => '5100',
            self::InventoryAdjustment => '5200',
        };
    }

    public function type(): AccountType
    {
        return match ($this) {
            self::Cash, self::Bank, self::AccountsReceivable, self::InventoryAsset, self::InputVat => AccountType::Asset,
            self::AccountsPayable, self::OutputVat => AccountType::Liability,
            self::RetainedEarnings, self::OpeningBalanceEquity => AccountType::Equity,
            self::SalesRevenue => AccountType::Income,
            self::CostOfGoodsSold, self::InventoryAdjustment => AccountType::Expense,
        };
    }
}
