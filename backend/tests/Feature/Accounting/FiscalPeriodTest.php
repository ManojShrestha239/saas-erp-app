<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\FiscalPeriodStatus;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

if (! function_exists('dropFpApiTestTenantDatabase')) {
    function dropFpApiTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_fp_api_alpha', 'tenant_fp_api_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsFpTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
{
    $testCase->flushSession();

    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-31 10:00:00');
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

    dropFpApiTestTenantDatabase('tenant_fp_api_alpha');
    dropFpApiTestTenantDatabase('tenant_fp_api_beta');

    Domain::query()->whereIn('host', ['fpalpha.nexuserp.test', 'fpbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['fpalpha', 'fpbeta'])->delete();

    // --- Alpha Tenant ---
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'FP Alpha Corp',
        'slug' => 'fpalpha',
        'database_name' => 'tenant_fp_api_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'fpalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@fpalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // --- Beta Tenant ---
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'FP Beta Corp',
        'slug' => 'fpbeta',
        'database_name' => 'tenant_fp_api_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'fpbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@fpbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // --- Extra users in Alpha ---
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'fpalpha',
        databaseName: 'tenant_fp_api_alpha',
    ));

    $this->accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@fpalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    $this->warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse',
        'email' => 'warehouse@fpalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->warehouseUser->syncRoles([TenantRole::WarehouseManager->value]);

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropFpApiTestTenantDatabase('tenant_fp_api_alpha');
    dropFpApiTestTenantDatabase('tenant_fp_api_beta');
});

test('accountant can list fiscal periods with filters', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'fpalpha',
        databaseName: 'tenant_fp_api_alpha',
    ));

    FiscalPeriod::create([
        'name' => 'January 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => FiscalPeriodStatus::Open,
    ]);

    FiscalPeriod::create([
        'name' => 'February 2026',
        'fiscal_year' => 2026,
        'period_number' => 2,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => FiscalPeriodStatus::Locked,
    ]);

    $tenantManager->reset();

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods?fiscal_year=2026');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'January 2026')
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.1.name', 'February 2026')
        ->assertJsonPath('data.1.status', 'locked');
});

test('accountant can create a single fiscal period', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    $payload = [
        'name' => 'March 2026',
        'fiscal_year' => 2026,
        'period_number' => 3,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
    ];

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods', $payload);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'March 2026')
        ->assertJsonPath('data.status', 'open');
});

test('duplicate fiscal period year and number is rejected', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'fpalpha',
        databaseName: 'tenant_fp_api_alpha',
    ));

    FiscalPeriod::create([
        'name' => 'January 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => FiscalPeriodStatus::Open,
    ]);

    $tenantManager->reset();

    $payload = [
        'name' => 'Duplicate Jan 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ];

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods', $payload);

    $response->assertUnprocessable();
});

test('accountant can auto generate 12 periods for fiscal year', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    $payload = [
        'fiscal_year' => 2027,
        'start_date' => '2027-01-01',
    ];

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods/generate', $payload);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonCount(12, 'data');
});

test('accountant can lock and unlock a fiscal period', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'fpalpha',
        databaseName: 'tenant_fp_api_alpha',
    ));

    $period = FiscalPeriod::create([
        'name' => 'January 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => FiscalPeriodStatus::Open,
    ]);

    $tenantManager->reset();

    // Lock
    $lockResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods/{$period->id}/lock");

    $lockResponse->assertOk()
        ->assertJsonPath('data.status', 'locked');

    // Unlock
    $unlockResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods/{$period->id}/unlock");

    $unlockResponse->assertOk()
        ->assertJsonPath('data.status', 'open');
});

test('accountant can close a fiscal period and closing is irreversible', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'fpalpha',
        databaseName: 'tenant_fp_api_alpha',
    ));

    $period = FiscalPeriod::create([
        'name' => 'January 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => FiscalPeriodStatus::Open,
    ]);

    $tenantManager->reset();

    $closeResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods/{$period->id}/close");

    $closeResponse->assertOk()
        ->assertJsonPath('data.status', 'closed');

    // Cannot unlock closed period
    $unlockResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods/{$period->id}/unlock");

    $unlockResponse->assertBadRequest()
        ->assertJsonPath('code', 'PERIOD_ALREADY_CLOSED');
});

test('posting to a locked or closed fiscal period is rejected', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'accountant@fpalpha.nexuserp.test');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'fpalpha',
        databaseName: 'tenant_fp_api_alpha',
    ));

    FiscalPeriod::create([
        'name' => 'January 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => FiscalPeriodStatus::Locked,
    ]);

    FiscalPeriod::create([
        'name' => 'February 2026',
        'fiscal_year' => 2026,
        'period_number' => 2,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => FiscalPeriodStatus::Closed,
    ]);

    $tenantManager->reset();

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://fpalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://fpalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    // Attempt posting in locked period
    $lockedPayload = [
        'entry_date' => '2026-01-15',
        'currency' => 'USD',
        'lines' => [
            ['account_id' => $debitId, 'debit_cents' => 10000],
            ['account_id' => $creditId, 'credit_cents' => 10000],
        ],
    ];

    $responseLocked = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://fpalpha.nexuserp.test/api/v1/accounting/journal-entries', $lockedPayload);

    $responseLocked->assertBadRequest()
        ->assertJsonPath('code', 'FISCAL_PERIOD_LOCKED');

    // Attempt posting in closed period
    $closedPayload = [
        'entry_date' => '2026-02-15',
        'currency' => 'USD',
        'lines' => [
            ['account_id' => $debitId, 'debit_cents' => 10000],
            ['account_id' => $creditId, 'credit_cents' => 10000],
        ],
    ];

    $responseClosed = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://fpalpha.nexuserp.test/api/v1/accounting/journal-entries', $closedPayload);

    $responseClosed->assertBadRequest()
        ->assertJsonPath('code', 'FISCAL_PERIOD_CLOSED');
});

test('warehouse manager is denied access to fiscal period endpoints', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsFpTenant($this, 'fpalpha.nexuserp.test', 'warehouse@fpalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://fpalpha.nexuserp.test/api/v1/accounting/fiscal-periods');

    $response->assertForbidden();
});
