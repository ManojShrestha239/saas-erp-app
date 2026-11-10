<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropFinancialReportsTestTenantDatabase')) {
    function dropFinancialReportsTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_reports_fin_alpha', 'tenant_reports_fin_beta'], true)) {
            return;
        }

        try {
            $config = config('database.connections.central');
            /** @var array{host: string, port: int|string, username: string, password: string} $config */
            $pdo = new PDO(
                dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
                username: (string) $config['username'],
                password: (string) $config['password'],
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
                $pdo->quote($dbName).' AND pid <> pg_backend_pid()');

            $quotedName = '"'.str_replace('"', '""', $dbName).'"';
            $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
        } catch (Throwable) {
            // Best-effort cleanup
        }
    }
}

beforeEach(function (): void {
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

    dropFinancialReportsTestTenantDatabase('tenant_reports_fin_alpha');
    dropFinancialReportsTestTenantDatabase('tenant_reports_fin_beta');

    Domain::query()->whereIn('host', ['reportalpha.nexuserp.test', 'reportbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['reportalpha', 'reportbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Report Alpha Corp',
        'slug' => 'reportalpha',
        'database_name' => 'tenant_reports_fin_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'reportalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@reportalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Report Beta Corp',
        'slug' => 'reportbeta',
        'database_name' => 'tenant_reports_fin_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'reportbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@reportbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha Users
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@reportalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Beta Owner Password
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@reportbeta.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Switch back to Alpha
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->alphaAccountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@reportalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaAccountant->assignRole(TenantRole::Accountant->value);

    $this->alphaWarehouseManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@reportalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaWarehouseManager->assignRole(TenantRole::WarehouseManager->value);

    $this->alphaSalesRep = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'salesrep@reportalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaSalesRep->assignRole(TenantRole::SalesRep->value);

    // Create posted journal transactions in Alpha Tenant
    $cash = Account::where('code', '1110')->firstOrFail();
    $revenue = Account::where('code', '4110')->firstOrFail();
    $cogs = Account::where('code', '5100')->firstOrFail();
    $inventory = Account::where('code', '1150')->firstOrFail();

    // Entry 1: Sale of $1,000 (100,000 cents) cash revenue
    $entry1 = JournalEntry::create([
        'id' => (string) Str::ulid(),
        'entry_number' => 'JE-2026-00001',
        'entry_date' => Carbon::now()->toDateString(),
        'status' => JournalEntryStatus::Posted->value,
        'total_debit_cents' => 100000,
        'total_credit_cents' => 100000,
        'currency' => 'NPR',
        'created_by_user_id' => $this->alphaAccountant->id,
        'posted_by_user_id' => $this->alphaAccountant->id,
        'posted_at' => now(),
    ]);
    $entry1->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $cash->id,
        'line_number' => 1,
        'debit_cents' => 100000,
        'credit_cents' => 0,
        'description' => 'Cash received from sales',
    ]);
    $entry1->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $revenue->id,
        'line_number' => 2,
        'debit_cents' => 0,
        'credit_cents' => 100000,
        'description' => 'Sales revenue',
    ]);

    // Entry 2: Cost of goods sold of $400 (40,000 cents)
    $entry2 = JournalEntry::create([
        'id' => (string) Str::ulid(),
        'entry_number' => 'JE-2026-00002',
        'entry_date' => Carbon::now()->toDateString(),
        'status' => JournalEntryStatus::Posted->value,
        'total_debit_cents' => 40000,
        'total_credit_cents' => 40000,
        'currency' => 'NPR',
        'created_by_user_id' => $this->alphaAccountant->id,
        'posted_by_user_id' => $this->alphaAccountant->id,
        'posted_at' => now(),
    ]);
    $entry2->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $cogs->id,
        'line_number' => 1,
        'debit_cents' => 40000,
        'credit_cents' => 0,
        'description' => 'COGS for delivered goods',
    ]);
    $entry2->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $inventory->id,
        'line_number' => 2,
        'debit_cents' => 0,
        'credit_cents' => 40000,
        'description' => 'Inventory asset reduction',
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    dropFinancialReportsTestTenantDatabase('tenant_reports_fin_alpha');
    dropFinancialReportsTestTenantDatabase('tenant_reports_fin_beta');
});

test('Trial Balance: Debits equal Credits, is_balanced is true, and net difference is 0', function (): void {
    $this->postJson('http://reportalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@reportalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/trial-balance');
    $response->assertOk()
        ->assertJsonPath('data.is_balanced', true)
        ->assertJsonPath('data.net_difference_cents', 0)
        ->assertJsonPath('data.total_debits_cents', 140000)
        ->assertJsonPath('data.total_credits_cents', 140000);

    $accounts = $response->json('data.accounts');
    expect($accounts)->toBeArray()->not->toBeEmpty();
});

test('Profit and Loss: Calculates revenue, cogs, gross profit, and net income in minor unit cents', function (): void {
    $this->postJson('http://reportalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@reportalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/profit-and-loss');
    $response->assertOk()
        ->assertJsonPath('data.total_revenue_cents', 100000)
        ->assertJsonPath('data.total_cogs_cents', 40000)
        ->assertJsonPath('data.gross_profit_cents', 60000)
        ->assertJsonPath('data.total_operating_expenses_cents', 0)
        ->assertJsonPath('data.net_income_cents', 60000);
});

test('Balance Sheet: Assets equal Liabilities plus Equity, and is_balanced is true', function (): void {
    $this->postJson('http://reportalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@reportalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/balance-sheet');
    $response->assertOk()
        ->assertJsonPath('data.is_balanced', true)
        ->assertJsonPath('data.net_difference_cents', 0)
        ->assertJsonPath('data.assets.total_cents', 60000)
        ->assertJsonPath('data.liabilities.total_cents', 0)
        ->assertJsonPath('data.equity.current_period_earnings_cents', 60000)
        ->assertJsonPath('data.equity.total_cents', 60000)
        ->assertJsonPath('data.total_liabilities_and_equity_cents', 60000);
});

test('RBAC: Owner and Accountant can view financial reports, but Warehouse Manager and Sales Rep get 403', function (): void {
    // 1. Warehouse Manager -> 403 Forbidden
    $this->postJson('http://reportalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'warehouse@reportalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/trial-balance')->assertForbidden();
    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/profit-and-loss')->assertForbidden();
    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/balance-sheet')->assertForbidden();

    $this->flushSession();

    // 2. Sales Rep -> 403 Forbidden
    $this->postJson('http://reportalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'salesrep@reportalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/trial-balance')->assertForbidden();

    $this->flushSession();

    // 3. Owner -> 200 OK
    $this->postJson('http://reportalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@reportalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/trial-balance')->assertOk();
    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/profit-and-loss')->assertOk();
    $this->getJson('http://reportalpha.nexuserp.test/api/v1/reports/balance-sheet')->assertOk();
});

test('Multi-tenant isolation: Beta tenant reports zero revenue and balanced empty books', function (): void {
    $this->postJson('http://reportbeta.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@reportbeta.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    // P&L in Beta should be 0
    $pnl = $this->getJson('http://reportbeta.nexuserp.test/api/v1/reports/profit-and-loss');
    $pnl->assertOk()
        ->assertJsonPath('data.total_revenue_cents', 0)
        ->assertJsonPath('data.total_cogs_cents', 0)
        ->assertJsonPath('data.net_income_cents', 0);

    // Trial balance in Beta should be balanced at 0
    $tb = $this->getJson('http://reportbeta.nexuserp.test/api/v1/reports/trial-balance');
    $tb->assertOk()
        ->assertJsonPath('data.is_balanced', true)
        ->assertJsonPath('data.total_debits_cents', 0)
        ->assertJsonPath('data.total_credits_cents', 0);
});
