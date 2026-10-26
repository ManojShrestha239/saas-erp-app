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

if (! function_exists('dropFrontendAuthTestTenantDatabase')) {
    function dropFrontendAuthTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha_fe', 'tenant_beta_fe'], true)) {
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

function loginAsFrontendAlphaUser(mixed $testCase, string $email = 'owner@alpha-fe.nexuserp.test', string $password = 'AlphaSecret123!'): string
{
    $response = $testCase->postJson('http://alpha-fe.nexuserp.test/api/v1/auth/login', [
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

    dropFrontendAuthTestTenantDatabase('tenant_alpha_fe');
    dropFrontendAuthTestTenantDatabase('tenant_beta_fe');

    Domain::query()->whereIn('host', ['alpha-fe.nexuserp.test', 'beta-fe.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-fe', 'beta-fe'])->delete();

    // 1. Provision Alpha tenant
    $alphaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha FE Corp',
        'slug' => 'alpha-fe',
        'database_name' => 'tenant_alpha_fe',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha-fe.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha-fe.nexuserp.test',
        ownerName: 'Alpha FE Owner',
    );
    Tenant::on('central')->where('id', $alphaId)->update(['status' => 'active']);

    $this->alphaTenant = Tenant::on('central')->find($alphaId);

    // 2. Provision Beta tenant
    $betaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta FE Corp',
        'slug' => 'beta-fe',
        'database_name' => 'tenant_beta_fe',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta-fe.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta-fe.nexuserp.test',
        ownerName: 'Beta FE Owner',
    );
    Tenant::on('central')->where('id', $betaId)->update(['status' => 'active']);

    $this->betaTenant = Tenant::on('central')->find($betaId);

    // Switch context to alpha to read and setup owner user
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@alpha-fe.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);

    /** @var TenantUser $user */
    $user = TenantUser::query()->where('email', 'owner@alpha-fe.nexuserp.test')->firstOrFail();
    $this->alphaUser = $user;

    TenantContext::set(null);
    DB::purge('tenant');
});

afterEach(function (): void {
    TenantContext::set(null);
    DB::purge('tenant');
    Auth::guard('web')->logout();

    dropFrontendAuthTestTenantDatabase('tenant_alpha_fe');
    dropFrontendAuthTestTenantDatabase('tenant_beta_fe');
});

test('guest can visit login page on tenant domain and see auth/Login Inertia component', function (): void {
    $response = $this->get('http://alpha-fe.nexuserp.test/login');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('tenant.id', $this->alphaTenant->id)
        ->where('tenant.slug', 'alpha-fe')
        ->where('auth.user', null)
    );
});

test('guest can visit forgot password page and see auth/ForgotPassword Inertia component', function (): void {
    $response = $this->get('http://alpha-fe.nexuserp.test/forgot-password');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ForgotPassword')
        ->where('tenant.slug', 'alpha-fe')
        ->where('auth.user', null)
    );
});

test('guest can visit reset password page with token and see auth/ResetPassword Inertia component', function (): void {
    $testToken = 'sample-reset-token-12345';

    $response = $this->get("http://alpha-fe.nexuserp.test/reset-password/{$testToken}");

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ResetPassword')
        ->where('token', $testToken)
        ->where('tenant.slug', 'alpha-fe')
        ->where('auth.user', null)
    );
});

test('unauthenticated user is redirected from protected web routes to /login', function (): void {
    $response = $this->get('http://alpha-fe.nexuserp.test/users');
    $response->assertRedirect('/login');

    $verifyResponse = $this->get('http://alpha-fe.nexuserp.test/verify-email');
    $verifyResponse->assertRedirect('/login');
});

test('authenticated tenant user can access /verify-email and receives shared auth props', function (): void {
    $sessionCookie = loginAsFrontendAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://alpha-fe.nexuserp.test/verify-email');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/VerifyEmail')
        ->where('auth.user.id', $this->alphaUser->id)
        ->where('auth.user.email', 'owner@alpha-fe.nexuserp.test')
        ->where('tenant.id', $this->alphaTenant->id)
    );
});

test('authenticated tenant user can access /users and receives shared user roles and permissions', function (): void {
    $sessionCookie = loginAsFrontendAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://alpha-fe.nexuserp.test/users');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('users/UsersIndex')
        ->where('auth.user.id', $this->alphaUser->id)
        ->where('auth.user.roles', ['owner'])
        ->has('auth.user.permissions', 18)
        ->where('tenant.id', $this->alphaTenant->id)
    );
});

test('cross-tenant session access to web routes is rejected with redirect to login', function (): void {
    $alphaCookie = loginAsFrontendAlphaUser($this);

    // Replay alpha session cookie on beta domain
    $response = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->get('http://beta-fe.nexuserp.test/users');

    $response->assertRedirect('http://beta-fe.nexuserp.test/login');
});
