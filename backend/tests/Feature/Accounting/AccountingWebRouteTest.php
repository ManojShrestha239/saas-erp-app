<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

if (! function_exists('dropAwrApiTestTenantDatabase')) {
    function dropAwrApiTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_awr_api_alpha'], true)) {
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

function loginAsAwrTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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

    dropAwrApiTestTenantDatabase('tenant_awr_api_alpha');

    Domain::query()->whereIn('host', ['awralpha.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['awralpha'])->delete();

    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'AWR Alpha Corp',
        'slug' => 'awralpha',
        'database_name' => 'tenant_awr_api_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'awralpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@awralpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'awralpha',
        databaseName: 'tenant_awr_api_alpha',
    ));

    $this->accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@awralpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropAwrApiTestTenantDatabase('tenant_awr_api_alpha');
});

test('authenticated accountant can access the accounting web page', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsAwrTenant($this, 'awralpha.nexuserp.test', 'accountant@awralpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->get('http://awralpha.nexuserp.test/accounting');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/AccountingIndex')
        );
});
