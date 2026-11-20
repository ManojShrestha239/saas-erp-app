<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\SystemAccount;
use Modules\Accounting\Models\Account;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropCoaTestTenantDatabase')) {
    function dropCoaTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_coa_seed_alpha'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

beforeEach(function (): void {
    app('cache')->flush();

    if (extension_loaded('redis')) {
        try {
            Cache::store('redis')->flush();
        } catch (Throwable) {
        }
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropCoaTestTenantDatabase('tenant_coa_seed_alpha');

    Domain::query()->where('host', 'coaseed.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'coaseed')->delete();

    $this->tenantId = (string) Str::ulid();
    $this->tenant = Tenant::on('central')->create([
        'id' => $this->tenantId,
        'name' => 'COA Seed Tenant',
        'slug' => 'coaseed',
        'database_name' => 'tenant_coa_seed_alpha',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $this->tenantId,
        'host' => 'coaseed.nexuserp.test',
    ]);
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropCoaTestTenantDatabase('tenant_coa_seed_alpha');
});

test('ProvisionTenantDatabase job automatically runs ChartOfAccountsSeeder and creates default CoA', function (): void {
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $this->tenantId,
        ownerEmail: 'owner@coaseed.nexuserp.test',
        ownerName: 'Seed Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->tenantId,
        slug: 'coaseed',
        databaseName: 'tenant_coa_seed_alpha',
    ));

    // Verify all 12 system accounts exist
    foreach (SystemAccount::cases() as $systemAccount) {
        /** @var Account|null $account */
        $account = Account::where('system_name', $systemAccount->value)->first();

        expect($account)->not->toBeNull(sprintf('System account %s should exist in tenant DB', $systemAccount->value))
            ->and($account->is_system)->toBeTrue()
            ->and($account->type)->toBe($systemAccount->type())
            ->and($account->code)->toBe($systemAccount->defaultCode());
    }

    // Check account count and root accounts
    $totalCount = Account::count();
    expect($totalCount)->toBeGreaterThanOrEqual(30);

    // Verify hierarchical relationships
    $cash = Account::where('system_name', SystemAccount::Cash->value)->firstOrFail();
    expect($cash->parent)->not->toBeNull()
        ->and($cash->parent->code)->toBe('1100')
        ->and($cash->parent->name)->toBe('Current Assets')
        ->and($cash->parent->parent)->not->toBeNull()
        ->and($cash->parent->parent->code)->toBe('1000')
        ->and($cash->parent->parent->name)->toBe('Assets');

    $cogs = Account::where('system_name', SystemAccount::CostOfGoodsSold->value)->firstOrFail();
    expect($cogs->type)->toBe(AccountType::Expense)
        ->and($cogs->parent)->not->toBeNull()
        ->and($cogs->parent->code)->toBe('5000');
});

test('ChartOfAccountsSeeder is idempotent and safe to run multiple times', function (): void {
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $this->tenantId,
        ownerEmail: 'owner@coaseed.nexuserp.test',
        ownerName: 'Seed Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->tenantId,
        slug: 'coaseed',
        databaseName: 'tenant_coa_seed_alpha',
    ));

    $initialCount = Account::count();

    // Re-run seeder directly
    (new ChartOfAccountsSeeder)->run();

    expect(Account::count())->toBe($initialCount);

    // Re-run once more
    (new ChartOfAccountsSeeder)->run();

    expect(Account::count())->toBe($initialCount);
});
