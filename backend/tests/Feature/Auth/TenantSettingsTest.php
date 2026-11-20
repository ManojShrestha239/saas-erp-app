<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\Setting;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

if (! function_exists('dropSettingsTestTenantDb')) {
    function dropSettingsTestTenantDb(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_settings_alpha', 'tenant_settings_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsSettingsTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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
    Carbon::setTestNow('2026-11-13 10:00:00');
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

    dropSettingsTestTenantDb('tenant_settings_alpha');
    dropSettingsTestTenantDb('tenant_settings_beta');

    Domain::query()->whereIn('host', ['settingsalpha.nexuserp.test', 'settingsbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['settingsalpha', 'settingsbeta'])->delete();

    // 1. Setup Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Settings Alpha Corp',
        'slug' => 'settingsalpha',
        'database_name' => 'tenant_settings_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'settingsalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@settingsalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'settingsalpha',
        databaseName: 'tenant_settings_alpha',
    ));

    TenantUser::query()->where('email', 'owner@settingsalpha.nexuserp.test')->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@settingsalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    // 2. Setup Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Settings Beta Corp',
        'slug' => 'settingsbeta',
        'database_name' => 'tenant_settings_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'settingsbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@settingsbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    $tenantManager->switchTo(new TenantContext(
        id: $betaId,
        slug: 'settingsbeta',
        databaseName: 'tenant_settings_beta',
    ));

    TenantUser::query()->where('email', 'owner@settingsbeta.nexuserp.test')->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropSettingsTestTenantDb('tenant_settings_alpha');
    dropSettingsTestTenantDb('tenant_settings_beta');
});

test('unauthenticated user is redirected from /settings to /login', function (): void {
    /** @var TestCase $this */
    $response = $this->get('http://settingsalpha.nexuserp.test/settings');
    $response->assertRedirect('/login');
});

test('authenticated owner can access /settings and receives settings/SettingsIndex Inertia component with initial settings', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsSettingsTenant($this, 'settingsalpha.nexuserp.test', 'owner@settingsalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->get('http://settingsalpha.nexuserp.test/settings');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/SettingsIndex')
            ->has('settings')
            ->where('settings.currency', 'NPR')
            ->where('settings.invoice_prefix', 'INV')
        );
});

test('authenticated user without settings.manage permission is forbidden from /settings with 403', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsSettingsTenant($this, 'settingsalpha.nexuserp.test', 'accountant@settingsalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->get('http://settingsalpha.nexuserp.test/settings');

    $response->assertForbidden();
});

test('authenticated owner can fetch settings via GET /api/v1/settings', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsSettingsTenant($this, 'settingsalpha.nexuserp.test', 'owner@settingsalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://settingsalpha.nexuserp.test/api/v1/settings');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.currency', 'NPR')
        ->assertJsonPath('data.invoice_prefix', 'INV');
});

test('authenticated owner can update settings via PUT /api/v1/settings', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsSettingsTenant($this, 'settingsalpha.nexuserp.test', 'owner@settingsalpha.nexuserp.test');

    $payload = [
        'company_name' => 'Acme Global Traders Ltd.',
        'company_tax_id' => 'VAT-998877',
        'company_email' => 'finance@acmeglobal.test',
        'company_phone' => '+1 (555) 019-8877',
        'company_address' => '123 Market Street, Suite 400',
        'currency' => 'USD',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'fiscal_year_start' => '01-01',
        'allow_negative_stock' => true,
        'invoice_prefix' => 'ACME-INV',
        'bank_details' => 'Chase Bank, Account 987654321, Routing 021000021',
    ];

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->putJson('http://settingsalpha.nexuserp.test/api/v1/settings', $payload);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.company_name', 'Acme Global Traders Ltd.')
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.allow_negative_stock', '1')
        ->assertJsonPath('data.invoice_prefix', 'ACME-INV');

    // Verify database persistence in tenant connection
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: 'settingsalpha',
        databaseName: 'tenant_settings_alpha',
    ));

    expect(Setting::query()->where('key', 'company_name')->value('value'))
        ->toBe('Acme Global Traders Ltd.');
    expect(Setting::query()->where('key', 'currency')->value('value'))
        ->toBe('USD');
    expect(Setting::query()->where('key', 'allow_negative_stock')->value('value'))
        ->toBe('1');
    expect(Setting::query()->where('key', 'invoice_prefix')->value('value'))
        ->toBe('ACME-INV');

    $tenantManager->reset();
});

test('updating settings with invalid data returns 422 validation error', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsSettingsTenant($this, 'settingsalpha.nexuserp.test', 'owner@settingsalpha.nexuserp.test');

    $invalidPayload = [
        'currency' => 'UNITED_STATES_DOLLAR', // exceeds 3 chars
        'fiscal_year_start' => 'invalid-date', // must match MM-DD
        'date_format' => 'invalid_format',
        'invoice_prefix' => 'INV WITH SPACES!',
    ];

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->putJson('http://settingsalpha.nexuserp.test/api/v1/settings', $invalidPayload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['currency', 'fiscal_year_start', 'date_format', 'invoice_prefix']);
});

test('multi-tenant isolation: updating settings in tenant alpha does not affect tenant beta', function (): void {
    /** @var TestCase $this */
    // 1. Alpha updates company name and currency
    $cookieAlpha = loginAsSettingsTenant($this, 'settingsalpha.nexuserp.test', 'owner@settingsalpha.nexuserp.test');
    $this->withCookie(config('session.cookie'), $cookieAlpha)
        ->putJson('http://settingsalpha.nexuserp.test/api/v1/settings', [
            'company_name' => 'Alpha Exclusives',
            'currency' => 'EUR',
        ])
        ->assertOk();

    // 2. Beta logs in and fetches settings
    $this->flushSession();
    $cookieBeta = loginAsSettingsTenant($this, 'settingsbeta.nexuserp.test', 'owner@settingsbeta.nexuserp.test');
    $responseBeta = $this->withCookie(config('session.cookie'), $cookieBeta)
        ->getJson('http://settingsbeta.nexuserp.test/api/v1/settings');

    $responseBeta->assertOk()
        ->assertJsonPath('data.currency', 'NPR')
        ->assertJsonMissing(['company_name' => 'Alpha Exclusives']);
});
