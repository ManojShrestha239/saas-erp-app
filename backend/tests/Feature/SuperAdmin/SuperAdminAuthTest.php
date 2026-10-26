<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Auth\Models\TenantUser;
use Modules\SuperAdmin\Models\SuperAdmin;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropSuperAdminTestTenantDatabase')) {
    function dropSuperAdminTestTenantDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_alpha_sa') {
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
    app('cache')->flush();
    Cache::store('redis')->flush();
    RateLimiter::clear('super_admin_login:*');

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    // Clear super admins
    SuperAdmin::query()->delete();

    // Create a known super admin
    SuperAdmin::create([
        'name' => 'Root Super Admin',
        'email' => 'admin@nexuserp.test',
        'password' => Hash::make('SuperAdminSecret123!'),
        'email_verified_at' => now(),
    ]);

    dropSuperAdminTestTenantDatabase('tenant_alpha_sa');
    Domain::query()->where('host', 'alpha-sa.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'alpha-sa')->delete();

    // Create a test tenant for subdomain rejection & cross-guard testing
    $tenantId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Alpha SA Corp',
        'slug' => 'alpha-sa',
        'database_name' => 'tenant_alpha_sa',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'alpha-sa.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@alpha-sa.nexuserp.test',
        ownerName: 'Alpha SA Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenantId,
        slug: 'alpha-sa',
        databaseName: 'tenant_alpha_sa',
    ));
    TenantUser::where('email', 'owner@alpha-sa.nexuserp.test')->update([
        'password' => Hash::make('TenantUserSecret123!'),
        'email_verified_at' => now(),
    ]);
    $tenantManager->reset();

    $this->tenantId = $tenantId;
});

afterEach(function (): void {
    dropSuperAdminTestTenantDatabase('tenant_alpha_sa');
});

describe('Super Admin Login Endpoint', function (): void {
    it('allows super admin to log in on central domain with valid credentials and sets session cookie', function (): void {
        $response = $this->postJson('http://nexuserp.test/api/v1/super-admin/login', [
            'email' => 'admin@nexuserp.test',
            'password' => 'SuperAdminSecret123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Super admin login successful.')
            ->assertJsonPath('data.user.email', 'admin@nexuserp.test')
            ->assertJsonPath('data.user.name', 'Root Super Admin');

        $sessionCookie = $response->getCookie(config('session.cookie'));
        expect($sessionCookie)->not->toBeNull();
    });

    it('rejects super admin login with invalid password (401 INVALID_CREDENTIALS)', function (): void {
        $response = $this->postJson('http://nexuserp.test/api/v1/super-admin/login', [
            'email' => 'admin@nexuserp.test',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    });

    it('rejects unknown email with 401 INVALID_CREDENTIALS to prevent enumeration', function (): void {
        $response = $this->postJson('http://nexuserp.test/api/v1/super-admin/login', [
            'email' => 'unknown-admin@nexuserp.test',
            'password' => 'SuperAdminSecret123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    });

    it('throttles super admin login attempts after 5 consecutive failures (429 TOO_MANY_ATTEMPTS)', function (): void {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.50.0.1'])
                ->postJson('http://nexuserp.test/api/v1/super-admin/login', [
                    'email' => 'admin@nexuserp.test',
                    'password' => 'WrongPassword!',
                ])->assertStatus(401);
        }

        $throttled = $this->withServerVariables(['REMOTE_ADDR' => '10.50.0.1'])
            ->postJson('http://nexuserp.test/api/v1/super-admin/login', [
                'email' => 'admin@nexuserp.test',
                'password' => 'WrongPassword!',
            ]);

        $throttled->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    });

    it('clears throttle attempts upon successful login', function (): void {
        for ($i = 0; $i < 4; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.50.0.2'])
                ->postJson('http://nexuserp.test/api/v1/super-admin/login', [
                    'email' => 'admin@nexuserp.test',
                    'password' => 'WrongPassword!',
                ])->assertStatus(401);
        }

        // Successful login
        $this->withServerVariables(['REMOTE_ADDR' => '10.50.0.2'])
            ->postJson('http://nexuserp.test/api/v1/super-admin/login', [
                'email' => 'admin@nexuserp.test',
                'password' => 'SuperAdminSecret123!',
            ])->assertOk();

        // New failure must NOT be throttled
        $this->withServerVariables(['REMOTE_ADDR' => '10.50.0.2'])
            ->postJson('http://nexuserp.test/api/v1/super-admin/login', [
                'email' => 'admin@nexuserp.test',
                'password' => 'WrongPassword!',
            ])->assertStatus(401);
    });
});

describe('Central Domain Route Protection', function (): void {
    it('rejects super admin routes on tenant subdomains with 404 ROUTE_NOT_FOUND', function (): void {
        $response = $this->postJson('http://alpha-sa.nexuserp.test/api/v1/super-admin/login', [
            'email' => 'admin@nexuserp.test',
            'password' => 'SuperAdminSecret123!',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ROUTE_NOT_FOUND');
    });
});

describe('Super Admin Session & Profile Endpoints', function (): void {
    it('returns authenticated super admin profile on GET /api/v1/super-admin/me', function (): void {
        $loginRes = $this->postJson('http://nexuserp.test/api/v1/super-admin/login', [
            'email' => 'admin@nexuserp.test',
            'password' => 'SuperAdminSecret123!',
        ]);

        $cookie = $loginRes->getCookie(config('session.cookie'));
        expect($cookie)->not->toBeNull();

        $meRes = $this->withCookie(config('session.cookie'), $cookie->getValue())
            ->getJson('http://nexuserp.test/api/v1/super-admin/me');

        $meRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'admin@nexuserp.test')
            ->assertJsonPath('data.user.name', 'Root Super Admin');
    });

    it('rejects unauthenticated request on GET /api/v1/super-admin/me with 401 UNAUTHENTICATED', function (): void {
        $response = $this->getJson('http://nexuserp.test/api/v1/super-admin/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });

    it('logs out super admin and invalidates session', function (): void {
        $loginRes = $this->postJson('http://nexuserp.test/api/v1/super-admin/login', [
            'email' => 'admin@nexuserp.test',
            'password' => 'SuperAdminSecret123!',
        ]);

        $cookie = $loginRes->getCookie(config('session.cookie'));
        expect($cookie)->not->toBeNull();

        $logoutRes = $this->withCookie(config('session.cookie'), $cookie->getValue())
            ->postJson('http://nexuserp.test/api/v1/super-admin/logout');

        $logoutRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Super admin logged out successfully.');

        // Subsequent call with old session is rejected
        $meRes = $this->withCookie(config('session.cookie'), $cookie->getValue())
            ->getJson('http://nexuserp.test/api/v1/super-admin/me');

        $meRes->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });

    it('rejects tenant user session cookie on super admin endpoints', function (): void {
        // 1. Log in tenant user on tenant domain
        $tenantLogin = $this->postJson('http://alpha-sa.nexuserp.test/api/v1/auth/login', [
            'email' => 'owner@alpha-sa.nexuserp.test',
            'password' => 'TenantUserSecret123!',
        ]);

        $tenantCookie = $tenantLogin->getCookie(config('session.cookie'));
        expect($tenantCookie)->not->toBeNull();

        // 2. Attempt to use tenant user session against super-admin endpoint on central domain
        $response = $this->withCookie(config('session.cookie'), $tenantCookie->getValue())
            ->getJson('http://nexuserp.test/api/v1/super-admin/me');

        // Must be rejected with 401 UNAUTHENTICATED
        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });
});
