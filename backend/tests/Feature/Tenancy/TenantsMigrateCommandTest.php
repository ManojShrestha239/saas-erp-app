<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

function createRawTestTenantDatabase(string $dbName): void
{
    $config = config('database.connections.central');
    /** @var array{host: string, port: int|string, username: string, password: string} $config */
    $pdo = new PDO(
        dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
        username: (string) $config['username'],
        password: (string) $config['password'],
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $quotedName = '"'.str_replace('"', '""', $dbName).'"';
    $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
    $pdo->exec("CREATE DATABASE {$quotedName}");
}

function cleanupMigrateTestTenantDatabases(): void
{
    try {
        $config = config('database.connections.central');
        /** @var array{host: string, port: int|string, username: string, password: string} $config */
        $pdo = new PDO(
            dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'tenant_test_mig_%'");
        /** @var list<string> $dbs */
        $dbs = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($dbs as $dbName) {
            $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
                $pdo->quote($dbName).' AND pid <> pg_backend_pid()');

            $quotedName = '"'.str_replace('"', '""', $dbName).'"';
            $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
        }
    } catch (Throwable) {
        // Best-effort cleanup
    }
}

beforeEach(function (): void {
    app('cache')->flush();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    Domain::query()->delete();
    Tenant::query()->delete();

    cleanupMigrateTestTenantDatabases();
    app(TenantManager::class)->reset();
});

afterEach(function (): void {
    cleanupMigrateTestTenantDatabases();
    app(TenantManager::class)->reset();
});

test('two active tenants both get the migration', function () {
    $tenant1 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Acme Corp',
        'slug' => 'acme-mig-test',
        'database_name' => 'tenant_test_mig_acme',
        'status' => 'active',
    ]);
    createRawTestTenantDatabase('tenant_test_mig_acme');

    $tenant2 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Globex Corp',
        'slug' => 'globex-mig-test',
        'database_name' => 'tenant_test_mig_globex',
        'status' => 'active',
    ]);
    createRawTestTenantDatabase('tenant_test_mig_globex');

    $this->artisan('tenants:migrate')
        ->expectsOutputToContain('Migrating tenant [acme-mig-test]')
        ->expectsOutputToContain('Migrating tenant [globex-mig-test]')
        ->expectsOutputToContain('2 active tenant(s) migrated successfully.')
        ->assertSuccessful();

    $tenantManager = app(TenantManager::class);

    // Verify tenant 1 tables
    $tenantManager->switchTo(new TenantContext(
        id: (string) $tenant1->id,
        slug: (string) $tenant1->slug,
        databaseName: (string) $tenant1->database_name,
    ));
    expect(DB::connection('tenant')->getSchemaBuilder()->hasTable('users'))->toBeTrue()
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('roles'))->toBeTrue()
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('settings'))->toBeTrue()
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('migrations'))->toBeTrue();

    // Verify tenant 2 tables
    $tenantManager->switchTo(new TenantContext(
        id: (string) $tenant2->id,
        slug: (string) $tenant2->slug,
        databaseName: (string) $tenant2->database_name,
    ));
    expect(DB::connection('tenant')->getSchemaBuilder()->hasTable('users'))->toBeTrue()
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('roles'))->toBeTrue()
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('settings'))->toBeTrue()
        ->and(DB::connection('tenant')->getSchemaBuilder()->hasTable('migrations'))->toBeTrue();

    $tenantManager->reset();
    expect($tenantManager->hasContext())->toBeFalse();
});

test('a suspended tenant is skipped', function () {
    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Active Corp',
        'slug' => 'active-mig-test',
        'database_name' => 'tenant_test_mig_active',
        'status' => 'active',
    ]);
    createRawTestTenantDatabase('tenant_test_mig_active');

    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Suspended Corp',
        'slug' => 'suspended-mig-test',
        'database_name' => 'tenant_test_mig_suspended',
        'status' => 'suspended',
    ]);

    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Provisioning Corp',
        'slug' => 'provisioning-mig-test',
        'database_name' => 'tenant_test_mig_prov',
        'status' => 'provisioning',
    ]);

    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Failed Corp',
        'slug' => 'failed-mig-test',
        'database_name' => 'tenant_test_mig_failed',
        'status' => 'provisioning_failed',
    ]);

    $this->artisan('tenants:migrate')
        ->expectsOutputToContain('Migrating tenant [active-mig-test]')
        ->expectsOutputToContain("Skipping tenant [suspended-mig-test]: status is 'suspended'.")
        ->expectsOutputToContain("Skipping tenant [provisioning-mig-test]: status is 'provisioning'.")
        ->expectsOutputToContain("Skipping tenant [failed-mig-test]: status is 'provisioning_failed'.")
        ->expectsOutputToContain('1 active tenant(s) migrated successfully.')
        ->assertSuccessful();

    expect(app(TenantManager::class)->hasContext())->toBeFalse();
});

test('a failing tenant does not stop the others and the command exits non-zero', function () {
    // Tenant 1 has a non-existent database so migrate fails
    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Broken Corp',
        'slug' => 'broken-mig-test',
        'database_name' => 'tenant_test_mig_non_existent',
        'status' => 'active',
    ]);

    // Tenant 2 has a valid database
    $tenant2 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Healthy Corp',
        'slug' => 'healthy-mig-test',
        'database_name' => 'tenant_test_mig_healthy',
        'status' => 'active',
    ]);
    createRawTestTenantDatabase('tenant_test_mig_healthy');

    $this->artisan('tenants:migrate')
        ->expectsOutputToContain('Exception while migrating tenant [broken-mig-test]: Database \'tenant_test_mig_non_existent\' does not exist.')
        ->expectsOutputToContain('Migrating tenant [healthy-mig-test]')
        ->expectsOutputToContain('Successfully migrated tenant [healthy-mig-test].')
        ->expectsOutputToContain('Failed migrating 1 tenant(s):')
        ->expectsOutputToContain('[broken-mig-test]')
        ->assertExitCode(1);

    // Confirm tenant 2 was indeed migrated despite tenant 1 failure
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $tenant2->id,
        slug: (string) $tenant2->slug,
        databaseName: (string) $tenant2->database_name,
    ));
    expect(DB::connection('tenant')->getSchemaBuilder()->hasTable('users'))->toBeTrue();

    $tenantManager->reset();
    expect($tenantManager->hasContext())->toBeFalse();
});

test('--tenant runs only the target', function () {
    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Target Corp',
        'slug' => 'target-mig-test',
        'database_name' => 'tenant_test_mig_target',
        'status' => 'active',
    ]);
    createRawTestTenantDatabase('tenant_test_mig_target');

    Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Ignored Corp',
        'slug' => 'ignored-mig-test',
        'database_name' => 'tenant_test_mig_ignored',
        'status' => 'active',
    ]);
    createRawTestTenantDatabase('tenant_test_mig_ignored');

    $this->artisan('tenants:migrate', ['--tenant' => 'target-mig-test'])
        ->expectsOutputToContain('Migrating tenant [target-mig-test]')
        ->doesntExpectOutputToContain('ignored-mig-test')
        ->expectsOutputToContain('1 active tenant(s) migrated successfully.')
        ->assertSuccessful();

    $tenantManager = app(TenantManager::class);

    // Target database got migrated
    $tenantManager->switchTo(new TenantContext(
        id: 'target',
        slug: 'target-mig-test',
        databaseName: 'tenant_test_mig_target',
    ));
    expect(DB::connection('tenant')->getSchemaBuilder()->hasTable('users'))->toBeTrue();

    // Ignored database was NOT migrated
    $tenantManager->switchTo(new TenantContext(
        id: 'ignored',
        slug: 'ignored-mig-test',
        databaseName: 'tenant_test_mig_ignored',
    ));
    expect(DB::connection('tenant')->getSchemaBuilder()->hasTable('users'))->toBeFalse();

    $tenantManager->reset();
    expect($tenantManager->hasContext())->toBeFalse();
});

test('--fresh option is disabled and exits non-zero', function () {
    $this->artisan('tenants:migrate', ['--fresh' => true])
        ->expectsOutputToContain('The --fresh option is not supported for tenant migrations.')
        ->assertExitCode(1);
});

test('targeting non-existent tenant exits non-zero', function () {
    $this->artisan('tenants:migrate', ['--tenant' => 'non-existent-slug'])
        ->expectsOutputToContain("Tenant with slug 'non-existent-slug' not found.")
        ->assertExitCode(1);
});
