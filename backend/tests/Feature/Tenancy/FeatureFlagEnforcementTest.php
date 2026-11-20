<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropFeatureFlagTestTenantDatabase')) {
    function dropFeatureFlagTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_flag_alpha'], true)) {
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

    dropFeatureFlagTestTenantDatabase('tenant_flag_alpha');

    DB::connection('central')->table('feature_flags')->delete();
    DB::connection('central')->table('subscriptions')->delete();
    DB::connection('central')->table('packages')->delete();
    Domain::query()->where('host', 'flagalpha.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'flagalpha')->delete();

    // Register test routes guarded by feature middleware
    Route::middleware([
        'identify.tenant',
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        EnsureTenantSession::class,
        'auth:web',
        'feature:advanced_reports',
    ])->get('/api/v1/test/advanced-reports', function (): JsonResponse {
        return ApiResponse::success(['report' => 'data'], 'Report data');
    });

    Route::middleware([
        'central.domain',
        'feature:public_registration',
    ])->get('/api/v1/test/public-registration', function (): JsonResponse {
        return ApiResponse::success(['enabled' => true], 'Registration enabled');
    });

    // Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Flag Alpha Corp',
        'slug' => 'flagalpha',
        'database_name' => 'tenant_flag_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'flagalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@flagalpha.nexuserp.test',
        ownerName: 'Flag Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaTenant->id,
        slug: $alphaTenant->slug,
        databaseName: $alphaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@flagalpha.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);
    $tenantManager->reset();

    $this->alphaTenant = $alphaTenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropFeatureFlagTestTenantDatabase('tenant_flag_alpha');
});

function loginAsFlagTenant(mixed $testCase, string $host, string $email, string $password = 'AlphaSecret123!'): string
{
    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

test('feature middleware allows request when package tier includes feature', function (): void {
    // 1. Package includes advanced_reports => true
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Enterprise Tier',
        'slug' => 'enterprise-tier',
        'max_users' => 50,
        'max_warehouses' => 10,
        'max_invoices_per_month' => 1000,
        'features' => json_encode(['advanced_reports' => true]),
        'price_cents' => 9900,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cookie = loginAsFlagTenant($this, 'flagalpha.nexuserp.test', 'owner@flagalpha.nexuserp.test');

    $res = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://flagalpha.nexuserp.test/api/v1/test/advanced-reports');

    $res->assertStatus(200)
        ->assertJsonPath('data.report', 'data');
});

test('feature middleware blocks request with 403 FEATURE_DISABLED when package tier omits or disables feature', function (): void {
    // 1. Package disables advanced_reports => false
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Starter Tier',
        'slug' => 'starter-tier',
        'max_users' => 5,
        'max_warehouses' => 1,
        'max_invoices_per_month' => 50,
        'features' => json_encode(['advanced_reports' => false]),
        'price_cents' => 1900,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cookie = loginAsFlagTenant($this, 'flagalpha.nexuserp.test', 'owner@flagalpha.nexuserp.test');

    $res = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://flagalpha.nexuserp.test/api/v1/test/advanced-reports');

    $res->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'FEATURE_DISABLED',
        ]);
});

test('global feature flag kill-switch overrides package and blocks feature when globally disabled', function (): void {
    // 1. Package includes advanced_reports => true
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Pro Tier',
        'slug' => 'pro-tier',
        'max_users' => 20,
        'max_warehouses' => 5,
        'max_invoices_per_month' => 500,
        'features' => json_encode(['advanced_reports' => true]),
        'price_cents' => 4900,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Super Admin adds global feature flag advanced_reports with is_enabled = false
    DB::connection('central')->table('feature_flags')->insert([
        'key' => 'advanced_reports',
        'name' => 'Advanced Reports',
        'description' => 'Global kill-switch for reports',
        'is_enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cookie = loginAsFlagTenant($this, 'flagalpha.nexuserp.test', 'owner@flagalpha.nexuserp.test');

    // Blocked by global kill-switch
    $res = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://flagalpha.nexuserp.test/api/v1/test/advanced-reports');

    $res->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'FEATURE_DISABLED',
        ]);

    // 3. Super Admin re-enables global feature flag
    DB::connection('central')->table('feature_flags')->where('key', 'advanced_reports')->update([
        'is_enabled' => true,
    ]);

    $resAllowed = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://flagalpha.nexuserp.test/api/v1/test/advanced-reports');

    $resAllowed->assertStatus(200);
});

test('central domain routes respect global feature flag middleware', function (): void {
    // 1. Feature disabled
    DB::connection('central')->table('feature_flags')->insert([
        'key' => 'public_registration',
        'name' => 'Public Registration',
        'is_enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resBlocked = $this->getJson('http://localhost/api/v1/test/public-registration');
    $resBlocked->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'FEATURE_DISABLED',
        ]);

    // 2. Feature enabled
    DB::connection('central')->table('feature_flags')->where('key', 'public_registration')->update([
        'is_enabled' => true,
    ]);

    $resAllowed = $this->getJson('http://localhost/api/v1/test/public-registration');
    $resAllowed->assertStatus(200)
        ->assertJsonPath('data.enabled', true);
});
