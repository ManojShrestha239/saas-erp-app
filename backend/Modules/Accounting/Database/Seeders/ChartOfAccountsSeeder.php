<?php

declare(strict_types=1);

namespace Modules\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\SystemAccount;
use Modules\Accounting\Models\Account;

final class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed the default hierarchical Chart of Accounts for the active tenant.
     */
    public function run(): void
    {
        $definitions = $this->getDefinitions();
        /** @var array<string, Account> $seededAccounts */
        $seededAccounts = [];

        foreach ($definitions as $def) {
            $parentId = null;
            if (isset($def['parent_code'])) {
                /** @var string $parentCode */
                $parentCode = $def['parent_code'];
                if (isset($seededAccounts[$parentCode])) {
                    $parentId = $seededAccounts[$parentCode]->id;
                } else {
                    $parentModel = Account::where('code', $parentCode)->first();
                    $parentId = $parentModel?->id;
                }
            }

            /** @var Account $account */
            $account = Account::firstOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'type' => $def['type']->value,
                    'parent_id' => $parentId,
                    'is_system' => $def['is_system'] ?? false,
                    'system_name' => $def['system_name'] ?? null,
                    'is_active' => true,
                    'currency' => 'USD',
                    'description' => $def['description'] ?? null,
                ]
            );

            // Ensure parent and system attributes are set if pre-existing
            $dirty = false;
            if ($account->parent_id === null && $parentId !== null) {
                $account->parent_id = $parentId;
                $dirty = true;
            }
            if (isset($def['is_system']) && $account->is_system !== $def['is_system']) {
                $account->is_system = (bool) $def['is_system'];
                $account->system_name = $def['system_name'] ?? null;
                $dirty = true;
            }
            if ($dirty) {
                $account->save();
            }

            $seededAccounts[$def['code']] = $account;
        }
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     type: AccountType,
     *     parent_code?: string,
     *     is_system?: bool,
     *     system_name?: string,
     *     description?: string
     * }>
     */
    private function getDefinitions(): array
    {
        return [
            // ==========================================
            // 1000 - ASSETS
            // ==========================================
            [
                'code' => '1000',
                'name' => 'Assets',
                'type' => AccountType::Asset,
                'is_system' => false,
                'description' => 'Top-level group for all economic resources and assets',
            ],
            [
                'code' => '1100',
                'name' => 'Current Assets',
                'type' => AccountType::Asset,
                'parent_code' => '1000',
                'is_system' => false,
                'description' => 'Cash, receivables, inventory, and other short-term liquid assets',
            ],
            [
                'code' => '1110',
                'name' => 'Cash on Hand',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => true,
                'system_name' => SystemAccount::Cash->value,
                'description' => 'Physical cash and petty cash balances',
            ],
            [
                'code' => '1120',
                'name' => 'Operating Bank Account',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => true,
                'system_name' => SystemAccount::Bank->value,
                'description' => 'Primary operating commercial bank account',
            ],
            [
                'code' => '1130',
                'name' => 'Accounts Receivable (Trade Debtors)',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => true,
                'system_name' => SystemAccount::AccountsReceivable->value,
                'description' => 'Amounts due from customers for sales on credit',
            ],
            [
                'code' => '1140',
                'name' => 'Allowance for Doubtful Accounts',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => false,
                'description' => 'Contra-asset account for estimated uncollectible receivables',
            ],
            [
                'code' => '1150',
                'name' => 'Inventory Asset',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => true,
                'system_name' => SystemAccount::InventoryAsset->value,
                'description' => 'Total valuation of physical stock and goods on hand',
            ],
            [
                'code' => '1160',
                'name' => 'Input VAT (Recoverable)',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => true,
                'system_name' => SystemAccount::InputVat->value,
                'description' => 'Value Added Tax paid on supplier purchases, recoverable from tax authorities',
            ],
            [
                'code' => '1170',
                'name' => 'Prepaid Expenses',
                'type' => AccountType::Asset,
                'parent_code' => '1100',
                'is_system' => false,
                'description' => 'Payments made in advance for goods or services to be received in future',
            ],
            [
                'code' => '1500',
                'name' => 'Non-Current Assets',
                'type' => AccountType::Asset,
                'parent_code' => '1000',
                'is_system' => false,
                'description' => 'Long-term physical and intangible assets',
            ],
            [
                'code' => '1510',
                'name' => 'Furniture & Equipment',
                'type' => AccountType::Asset,
                'parent_code' => '1500',
                'is_system' => false,
                'description' => 'Office furniture, warehouse machinery, computers, and equipment',
            ],
            [
                'code' => '1520',
                'name' => 'Accumulated Depreciation - Equipment',
                'type' => AccountType::Asset,
                'parent_code' => '1500',
                'is_system' => false,
                'description' => 'Contra-asset account tracking cumulative depreciation on equipment',
            ],

            // ==========================================
            // 2000 - LIABILITIES
            // ==========================================
            [
                'code' => '2000',
                'name' => 'Liabilities',
                'type' => AccountType::Liability,
                'is_system' => false,
                'description' => 'Top-level group for all claims and debts owed to external parties',
            ],
            [
                'code' => '2100',
                'name' => 'Current Liabilities',
                'type' => AccountType::Liability,
                'parent_code' => '2000',
                'is_system' => false,
                'description' => 'Obligations and debts payable within one fiscal year',
            ],
            [
                'code' => '2110',
                'name' => 'Accounts Payable (Trade Creditors)',
                'type' => AccountType::Liability,
                'parent_code' => '2100',
                'is_system' => true,
                'system_name' => SystemAccount::AccountsPayable->value,
                'description' => 'Amounts owed to suppliers for purchases on credit',
            ],
            [
                'code' => '2120',
                'name' => 'Output VAT (Payable)',
                'type' => AccountType::Liability,
                'parent_code' => '2100',
                'is_system' => true,
                'system_name' => SystemAccount::OutputVat->value,
                'description' => 'Value Added Tax collected on sales to customers, payable to tax authorities',
            ],
            [
                'code' => '2130',
                'name' => 'Salaries & Wages Payable',
                'type' => AccountType::Liability,
                'parent_code' => '2100',
                'is_system' => false,
                'description' => 'Accrued employee compensation owed but not yet disbursed',
            ],
            [
                'code' => '2140',
                'name' => 'Accrued Expenses',
                'type' => AccountType::Liability,
                'parent_code' => '2100',
                'is_system' => false,
                'description' => 'Expenses incurred but not yet invoiced or paid',
            ],
            [
                'code' => '2500',
                'name' => 'Non-Current Liabilities',
                'type' => AccountType::Liability,
                'parent_code' => '2000',
                'is_system' => false,
                'description' => 'Long-term loans and financial obligations',
            ],
            [
                'code' => '2510',
                'name' => 'Long-Term Bank Loans',
                'type' => AccountType::Liability,
                'parent_code' => '2500',
                'is_system' => false,
                'description' => 'Commercial bank term loans with maturity greater than one year',
            ],

            // ==========================================
            // 3000 - EQUITY
            // ==========================================
            [
                'code' => '3000',
                'name' => 'Equity',
                'type' => AccountType::Equity,
                'is_system' => false,
                'description' => 'Owner and shareholder residual interest in company assets',
            ],
            [
                'code' => '3100',
                'name' => 'Owner Capital / Common Stock',
                'type' => AccountType::Equity,
                'parent_code' => '3000',
                'is_system' => false,
                'description' => 'Initial capital contributed by owners or shareholders',
            ],
            [
                'code' => '3200',
                'name' => 'Retained Earnings',
                'type' => AccountType::Equity,
                'parent_code' => '3000',
                'is_system' => true,
                'system_name' => SystemAccount::RetainedEarnings->value,
                'description' => 'Cumulative net earnings retained by the business from prior periods',
            ],
            [
                'code' => '3300',
                'name' => 'Opening Balance Equity',
                'type' => AccountType::Equity,
                'parent_code' => '3000',
                'is_system' => true,
                'system_name' => SystemAccount::OpeningBalanceEquity->value,
                'description' => 'Balancing account for opening balance setup entries',
            ],
            [
                'code' => '3400',
                'name' => 'Owner Drawings / Distributions',
                'type' => AccountType::Equity,
                'parent_code' => '3000',
                'is_system' => false,
                'description' => 'Withdrawals or dividends paid to owners during the year',
            ],

            // ==========================================
            // 4000 - INCOME / REVENUE
            // ==========================================
            [
                'code' => '4000',
                'name' => 'Income',
                'type' => AccountType::Income,
                'is_system' => false,
                'description' => 'Revenue generated from primary operations and secondary activities',
            ],
            [
                'code' => '4100',
                'name' => 'Operating Revenue',
                'type' => AccountType::Income,
                'parent_code' => '4000',
                'is_system' => false,
                'description' => 'Inflow of economic benefits from normal sales and services',
            ],
            [
                'code' => '4110',
                'name' => 'Sales Revenue',
                'type' => AccountType::Income,
                'parent_code' => '4100',
                'is_system' => true,
                'system_name' => SystemAccount::SalesRevenue->value,
                'description' => 'Gross income earned from sale of merchandise and trading products',
            ],
            [
                'code' => '4120',
                'name' => 'Service Revenue',
                'type' => AccountType::Income,
                'parent_code' => '4100',
                'is_system' => false,
                'description' => 'Income earned from consulting, delivery, repair, or professional services',
            ],
            [
                'code' => '4190',
                'name' => 'Sales Discounts & Allowances',
                'type' => AccountType::Income,
                'parent_code' => '4100',
                'is_system' => false,
                'description' => 'Contra-revenue account for early payment discounts or pricing reductions',
            ],
            [
                'code' => '4200',
                'name' => 'Other Income',
                'type' => AccountType::Income,
                'parent_code' => '4000',
                'is_system' => false,
                'description' => 'Non-operating income from interest, asset disposal, or rebates',
            ],
            [
                'code' => '4210',
                'name' => 'Interest Income',
                'type' => AccountType::Income,
                'parent_code' => '4200',
                'is_system' => false,
                'description' => 'Interest earned on deposits and bank accounts',
            ],

            // ==========================================
            // 5000 - EXPENSES (COGS & DIRECT)
            // ==========================================
            [
                'code' => '5000',
                'name' => 'Cost of Goods Sold',
                'type' => AccountType::Expense,
                'is_system' => false,
                'description' => 'Direct costs attributable to the production or acquisition of sold goods',
            ],
            [
                'code' => '5100',
                'name' => 'Cost of Goods Sold (COGS)',
                'type' => AccountType::Expense,
                'parent_code' => '5000',
                'is_system' => true,
                'system_name' => SystemAccount::CostOfGoodsSold->value,
                'description' => 'Direct cost of inventory sold during the accounting period',
            ],
            [
                'code' => '5200',
                'name' => 'Inventory Adjustment Gain / Loss',
                'type' => AccountType::Expense,
                'parent_code' => '5000',
                'is_system' => true,
                'system_name' => SystemAccount::InventoryAdjustment->value,
                'description' => 'Gains or losses arising from stock counts, shrinkage, damage, or revaluation',
            ],
            [
                'code' => '5300',
                'name' => 'Purchase Discounts',
                'type' => AccountType::Expense,
                'parent_code' => '5000',
                'is_system' => false,
                'description' => 'Contra-expense account for discounts received from suppliers',
            ],

            // ==========================================
            // 6000 - OPERATING EXPENSES
            // ==========================================
            [
                'code' => '6000',
                'name' => 'Operating Expenses',
                'type' => AccountType::Expense,
                'is_system' => false,
                'description' => 'Indirect costs required for general commercial operation and administration',
            ],
            [
                'code' => '6100',
                'name' => 'General & Administrative Expenses',
                'type' => AccountType::Expense,
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Overhead operational expenditures',
            ],
            [
                'code' => '6110',
                'name' => 'Salaries & Employee Benefits',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Staff payroll, social security contributions, and bonuses',
            ],
            [
                'code' => '6120',
                'name' => 'Rent & Facilities Expense',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Warehouse, store, and office lease expenses',
            ],
            [
                'code' => '6130',
                'name' => 'Utilities (Electricity, Water, Internet)',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Power, water, communications, and internet services',
            ],
            [
                'code' => '6140',
                'name' => 'Marketing & Advertising',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Campaigns, digital promotions, and sales collateral',
            ],
            [
                'code' => '6150',
                'name' => 'Office Supplies & Postage',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Stationery, packaging materials, and courier fees',
            ],
            [
                'code' => '6160',
                'name' => 'Bank & Payment Processing Fees',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Banking charges, credit card merchant processing fees, and wire fees',
            ],
            [
                'code' => '6170',
                'name' => 'Travel & Entertainment',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Business travel, per diems, and client meeting expenses',
            ],
            [
                'code' => '6180',
                'name' => 'Depreciation Expense',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'Periodic depreciation expense on fixed tangible assets',
            ],
            [
                'code' => '6190',
                'name' => 'Miscellaneous Operating Expense',
                'type' => AccountType::Expense,
                'parent_code' => '6100',
                'is_system' => false,
                'description' => 'General incidental operating expenses',
            ],
            [
                'code' => '6200',
                'name' => 'Financial & Other Expenses',
                'type' => AccountType::Expense,
                'parent_code' => '6000',
                'is_system' => false,
                'description' => 'Interest on debt, bank loans, and non-operational charges',
            ],
            [
                'code' => '6210',
                'name' => 'Interest Expense',
                'type' => AccountType::Expense,
                'parent_code' => '6200',
                'is_system' => false,
                'description' => 'Interest charges on term loans and credit facilities',
            ],
            [
                'code' => '6220',
                'name' => 'Bad Debt Expense',
                'type' => AccountType::Expense,
                'parent_code' => '6200',
                'is_system' => false,
                'description' => 'Written-off uncollectible customer receivable balances',
            ],
        ];
    }
}
