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

if (! function_exists('dropInventoryWebTestTenantDatabase')) {
    function dropInventoryWebTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha_iw', 'tenant_beta_iw'], true)) {
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

function loginAsInventoryWebAlphaUser(mixed $testCase, string $email = 'owner@alpha-iw.nexuserp.test', string $password = 'AlphaSecret123!'): string
{
    $response = $testCase->postJson('http://alpha-iw.nexuserp.test/api/v1/auth/login', [
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

    dropInventoryWebTestTenantDatabase('tenant_alpha_iw');
    dropInventoryWebTestTenantDatabase('tenant_beta_iw');

    Domain::query()->whereIn('host', ['alpha-iw.nexuserp.test', 'beta-iw.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-iw', 'beta-iw'])->delete();

    // 1. Provision Alpha tenant
    $alphaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha IW Corp',
        'slug' => 'alpha-iw',
        'database_name' => 'tenant_alpha_iw',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha-iw.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha-iw.nexuserp.test',
        ownerName: 'Alpha IW Owner',
    );
    Tenant::on('central')->where('id', $alphaId)->update(['status' => 'active']);

    $this->alphaTenant = Tenant::on('central')->find($alphaId);

    // 2. Provision Beta tenant
    $betaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta IW Corp',
        'slug' => 'beta-iw',
        'database_name' => 'tenant_beta_iw',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta-iw.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta-iw.nexuserp.test',
        ownerName: 'Beta IW Owner',
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

    TenantUser::where('email', 'owner@alpha-iw.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);

    /** @var TenantUser $user */
    $user = TenantUser::query()->where('email', 'owner@alpha-iw.nexuserp.test')->firstOrFail();
    $this->alphaUser = $user;

    TenantContext::set(null);
    DB::purge('tenant');
});

afterEach(function (): void {
    TenantContext::set(null);
    DB::purge('tenant');
    Auth::guard('web')->logout();

    dropInventoryWebTestTenantDatabase('tenant_alpha_iw');
    dropInventoryWebTestTenantDatabase('tenant_beta_iw');
});

test('unauthenticated user is redirected from /inventory to /login', function (): void {
    $response = $this->get('http://alpha-iw.nexuserp.test/inventory');
    $response->assertRedirect('/login');
});

test('authenticated tenant user can access /inventory and receives inventory/InventoryIndex Inertia component', function (): void {
    $sessionCookie = loginAsInventoryWebAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://alpha-iw.nexuserp.test/inventory');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('inventory/InventoryIndex')
        ->where('auth.user.id', $this->alphaUser->id)
        ->where('auth.user.email', 'owner@alpha-iw.nexuserp.test')
    );
});

test('GET /products redirects to /inventory', function (): void {
    $sessionCookie = loginAsInventoryWebAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://alpha-iw.nexuserp.test/products');

    $response->assertRedirect('/inventory');
});

test('cross-tenant session access to /inventory is rejected with redirect to /login', function (): void {
    $sessionCookie = loginAsInventoryWebAlphaUser($this);

    // Replay alpha cookie on beta tenant
    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://beta-iw.nexuserp.test/inventory');

    $response->assertRedirect('/login');
});
