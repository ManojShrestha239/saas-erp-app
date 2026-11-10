<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use PDOException;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropProvisionerTestDatabase')) {
    function dropProvisionerTestDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_least_privilege_test') {
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
    try {
        Cache::store('redis')->flush();
    } catch (Throwable) {
        // Redis store might not be available
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropProvisionerTestDatabase('tenant_least_privilege_test');
    Domain::query()->where('host', 'leastpriv.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'leastpriv')->delete();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropProvisionerTestDatabase('tenant_least_privilege_test');
});

test('nexuserp_provisioner role exists with CREATEDB privilege and without superuser status', function (): void {
    $config = config('database.connections.central');
    /** @var array{host: string, port: int|string, username: string, password: string} $config */
    $pdo = new PDO(
        dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
        username: (string) $config['username'],
        password: (string) $config['password'],
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query(
        "SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolcanlogin 
         FROM pg_roles 
         WHERE rolname = 'nexuserp_provisioner'"
    );

    $role = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    expect($role)->not->toBeNull()
        ->and($role['rolname'])->toBe('nexuserp_provisioner')
        ->and($role['rolsuper'])->toBeFalse()
        ->and($role['rolcreaterole'])->toBeFalse()
        ->and($role['rolcreatedb'])->toBeTrue()
        ->and($role['rolcanlogin'])->toBeTrue();
});

test('ProvisionTenantDatabase successfully creates database and migrates tenant schema using non-superuser provisioner role', function (): void {
    $centralConfig = config('database.connections.central');
    /** @var array{host: string, port: int|string} $centralConfig */

    // Configure dedicated non-superuser provisioner connection
    config([
        'database.connections.provisioner' => [
            'driver' => 'pgsql',
            'host' => $centralConfig['host'],
            'port' => $centralConfig['port'],
            'database' => 'postgres',
            'username' => 'nexuserp_provisioner',
            'password' => 'provisioner_secret',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ],
    ]);

    $tenantId = (string) Str::ulid();
    $tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Least Privilege Tenant Corp',
        'slug' => 'leastpriv',
        'database_name' => 'tenant_least_privilege_test',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'leastpriv.nexuserp.test',
    ]);

    // Dispatch provisioning job
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@leastpriv.nexuserp.test',
        ownerName: 'Least Privilege Owner',
    );

    // Verify tenant status is active
    $tenant->refresh();
    expect($tenant->status)->toBe('active')
        ->and($tenant->provisioning_error)->toBeNull();

    // Verify database was physically created
    $adminPdo = new PDO(
        dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $centralConfig['host'], $centralConfig['port']),
        username: (string) config('database.connections.central.username'),
        password: (string) config('database.connections.central.password'),
    );
    $checkStmt = $adminPdo->query("SELECT COUNT(*) FROM pg_database WHERE datname = 'tenant_least_privilege_test'");
    expect((int) $checkStmt->fetchColumn())->toBe(1);

    // Verify tenant connection works and tables are migrated
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    $tableCount = $tenantManager->connection()
        ->select("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = 'public'");
    expect((int) $tableCount[0]->count)->toBeGreaterThan(10);
});

test('non-superuser provisioner role is strictly blocked from executing superuser administrative operations', function (): void {
    $centralConfig = config('database.connections.central');
    /** @var array{host: string, port: int|string} $centralConfig */
    $provisionerPdo = new PDO(
        dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $centralConfig['host'], $centralConfig['port']),
        username: 'nexuserp_provisioner',
        password: 'provisioner_secret',
    );
    $provisionerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Attempting ALTER SYSTEM must fail with SQLSTATE 42501 (must be superuser)
    expect(function () use ($provisionerPdo): void {
        $provisionerPdo->exec("ALTER SYSTEM SET log_connections = 'on'");
    })->toThrow(PDOException::class);

    // Attempting to create a superuser or create another role must fail with insufficient privilege
    expect(function () use ($provisionerPdo): void {
        $provisionerPdo->exec('CREATE ROLE rogue_role WITH SUPERUSER LOGIN');
    })->toThrow(PDOException::class);
});
