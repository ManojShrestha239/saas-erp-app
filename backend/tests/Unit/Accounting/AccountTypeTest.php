<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\SystemAccount;

test('AccountType normalBalance returns correct debit or credit polarity', function (): void {
    expect(AccountType::Asset->normalBalance())->toBe('debit')
        ->and(AccountType::Expense->normalBalance())->toBe('debit')
        ->and(AccountType::Liability->normalBalance())->toBe('credit')
        ->and(AccountType::Equity->normalBalance())->toBe('credit')
        ->and(AccountType::Income->normalBalance())->toBe('credit');
});

test('AccountType label returns human-readable label', function (): void {
    expect(AccountType::Asset->label())->toBe('Asset')
        ->and(AccountType::Liability->label())->toBe('Liability')
        ->and(AccountType::Equity->label())->toBe('Equity')
        ->and(AccountType::Income->label())->toBe('Income')
        ->and(AccountType::Expense->label())->toBe('Expense');
});

test('SystemAccount enum maps to expected codes and types', function (): void {
    expect(SystemAccount::Cash->defaultCode())->toBe('1110')
        ->and(SystemAccount::Cash->type())->toBe(AccountType::Asset)
        ->and(SystemAccount::Bank->defaultCode())->toBe('1120')
        ->and(SystemAccount::Bank->type())->toBe(AccountType::Asset)
        ->and(SystemAccount::AccountsReceivable->defaultCode())->toBe('1130')
        ->and(SystemAccount::AccountsReceivable->type())->toBe(AccountType::Asset)
        ->and(SystemAccount::InventoryAsset->defaultCode())->toBe('1150')
        ->and(SystemAccount::InventoryAsset->type())->toBe(AccountType::Asset)
        ->and(SystemAccount::InputVat->defaultCode())->toBe('1160')
        ->and(SystemAccount::InputVat->type())->toBe(AccountType::Asset)
        ->and(SystemAccount::AccountsPayable->defaultCode())->toBe('2110')
        ->and(SystemAccount::AccountsPayable->type())->toBe(AccountType::Liability)
        ->and(SystemAccount::OutputVat->defaultCode())->toBe('2120')
        ->and(SystemAccount::OutputVat->type())->toBe(AccountType::Liability)
        ->and(SystemAccount::RetainedEarnings->defaultCode())->toBe('3200')
        ->and(SystemAccount::RetainedEarnings->type())->toBe(AccountType::Equity)
        ->and(SystemAccount::OpeningBalanceEquity->defaultCode())->toBe('3300')
        ->and(SystemAccount::OpeningBalanceEquity->type())->toBe(AccountType::Equity)
        ->and(SystemAccount::SalesRevenue->defaultCode())->toBe('4110')
        ->and(SystemAccount::SalesRevenue->type())->toBe(AccountType::Income)
        ->and(SystemAccount::CostOfGoodsSold->defaultCode())->toBe('5100')
        ->and(SystemAccount::CostOfGoodsSold->type())->toBe(AccountType::Expense)
        ->and(SystemAccount::InventoryAdjustment->defaultCode())->toBe('5200')
        ->and(SystemAccount::InventoryAdjustment->type())->toBe(AccountType::Expense);
});
