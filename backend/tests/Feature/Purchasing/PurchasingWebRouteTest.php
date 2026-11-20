<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropPurchasingWebTestTenantDatabase')) {
    function dropPurchasingWebTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha_pw', 'tenant_beta_pw'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsPurchasingWebAlphaUser(mixed $testCase, string $email = 'owner@alpha-pw.nexuserp.test', string $password = 'AlphaSecret123!'): string
{
    $response = $testCase->postJson('http://alpha-pw.nexuserp.test/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

beforeEach(function (): void {
    app('cache')->flush();
    Cache::store('redis')->flush();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropPurchasingWebTestTenantDatabase('tenant_alpha_pw');
    dropPurchasingWebTestTenantDatabase('tenant_beta_pw');

    Domain::query()->whereIn('host', ['alpha-pw.nexuserp.test', 'beta-pw.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-pw', 'beta-pw'])->delete();

    // 1. Provision Alpha tenant
    $alphaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha PW Corp',
        'slug' => 'alpha-pw',
        'database_name' => 'tenant_alpha_pw',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha-pw.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha-pw.nexuserp.test',
        ownerName: 'Alpha PW Owner',
    );
    Tenant::on('central')->where('id', $alphaId)->update(['status' => 'active']);

    $this->alphaTenant = Tenant::on('central')->find($alphaId);

    // 2. Provision Beta tenant
    $betaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta PW Corp',
        'slug' => 'beta-pw',
        'database_name' => 'tenant_beta_pw',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta-pw.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta-pw.nexuserp.test',
        ownerName: 'Beta PW Owner',
    );
    Tenant::on('central')->where('id', $betaId)->update(['status' => 'active']);

    $this->betaTenant = Tenant::on('central')->find($betaId);

    // Switch context to alpha to setup owner user password
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@alpha-pw.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);

    /** @var TenantUser $user */
    $user = TenantUser::query()->where('email', 'owner@alpha-pw.nexuserp.test')->firstOrFail();
    $this->alphaUser = $user;

    TenantContext::set(null);
    DB::purge('tenant');
});

afterEach(function (): void {
    TenantContext::set(null);
    DB::purge('tenant');
    Auth::guard('web')->logout();

    dropPurchasingWebTestTenantDatabase('tenant_alpha_pw');
    dropPurchasingWebTestTenantDatabase('tenant_beta_pw');
});

test('unauthenticated user is redirected from /purchasing to /login', function (): void {
    $response = $this->get('http://alpha-pw.nexuserp.test/purchasing');
    $response->assertRedirect('/login');
});

test('authenticated tenant user can access /purchasing and receives purchasing/PurchasingIndex Inertia component', function (): void {
    $sessionCookie = loginAsPurchasingWebAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://alpha-pw.nexuserp.test/purchasing');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('purchasing/PurchasingIndex')
        ->where('auth.user.id', $this->alphaUser->id)
        ->where('auth.user.email', 'owner@alpha-pw.nexuserp.test')
    );
});

test('cross-tenant session access to /purchasing is rejected with redirect to /login', function (): void {
    $sessionCookie = loginAsPurchasingWebAlphaUser($this);

    // Replay alpha cookie on beta tenant
    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://beta-pw.nexuserp.test/purchasing');

    $response->assertRedirect('/login');
});
