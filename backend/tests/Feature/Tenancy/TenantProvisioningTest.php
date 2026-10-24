<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

/*
|--------------------------------------------------------------------------
| Tenant Registration Feature Tests
|--------------------------------------------------------------------------
|
| Tests run with QUEUE_CONNECTION=sync so ProvisionTenantDatabase executes
| inline. Each test creates real PostgreSQL tenant databases; before and
| after each test, all tenant databases are dropped to keep the system clean.
|
*/

function cleanupAllTenantDatabases(): void
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

        $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'tenant_%'");
        $dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);

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

    cleanupAllTenantDatabases();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    cleanupAllTenantDatabases();

    Domain::query()->delete();
    Tenant::query()->delete();

    TenantContext::set(null);
    app('cache')->flush();
});

test('POST /api/v1/tenants returns 202 and creates central rows with provisioning status', function (): void {
    Bus::fake();

    $response = $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'Acme Corporation',
        'slug' => 'acme-reg-test',
        'owner_email' => 'owner@acme.com',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.slug', 'acme-reg-test')
        ->assertJsonPath('data.status', 'provisioning');

    expect(Tenant::on('central')->where('slug', 'acme-reg-test')->exists())->toBeTrue();
    expect(Domain::on('central')->where('host', 'acme-reg-test.nexuserp.test')->exists())->toBeTrue();

    Bus::assertDispatched(ProvisionTenantDatabase::class, function (ProvisionTenantDatabase $job): bool {
        return $job->ownerEmail === 'owner@acme.com';
    });
});

test('POST /api/v1/tenants is rate limited after 5 requests per minute', function (): void {
    Bus::fake();

    for ($i = 1; $i <= 5; $i++) {
        $response = $this->postJson('http://nexuserp.test/api/v1/tenants', [
            'name' => "Rate Limit Corp {$i}",
            'slug' => "rate-limit-{$i}",
            'owner_email' => "owner{$i}@ratelimit.com",
        ]);

        $response->assertStatus(202);
    }

    $throttled = $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'Rate Limit Exceeded Corp',
        'slug' => 'rate-limit-exceeded',
        'owner_email' => 'owner@ratelimit.com',
    ]);

    $throttled->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

test('POST /api/v1/tenants returns 422 for invalid slug', function (): void {
    $response = $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'Test Co',
        'slug' => 'Invalid Slug!',
        'owner_email' => 'owner@test.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

test('POST /api/v1/tenants returns 422 for duplicate slug', function (): void {
    Bus::fake();

    $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'First Co',
        'slug' => 'first-co',
        'owner_email' => 'owner@first.com',
    ])->assertStatus(202);

    $response = $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'Duplicate Co',
        'slug' => 'first-co',
        'owner_email' => 'owner@duplicate.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

test('POST /api/v1/tenants returns 422 for invalid email', function (): void {
    $response = $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'Test Co',
        'slug' => 'test-co',
        'owner_email' => 'not-an-email',
    ]);

    $response->assertStatus(422);
});

test('ProvisionTenantDatabase job creates DB, runs migrations, seeds roles and settings, creates owner, marks tenant active', function (): void {
    // Create tenant row with provisioning status
    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Provision Test Corp',
        'slug' => 'provision-test',
        'database_name' => 'tenant_provision_test',
        'status' => 'provisioning',
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenant->id,
        ownerEmail: 'owner@provision.com',
        ownerName: 'Provision Owner',
    );

    $tenant->refresh();

    expect($tenant->status)->toBe('active')
        ->and($tenant->provisioning_error)->toBeNull();

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Users table exists with owner
    expect($db->connection('tenant')->table('users')->where('email', 'owner@provision.com')->exists())->toBeTrue();

    // Roles were seeded
    expect($db->connection('tenant')->table('roles')->where('name', 'owner')->exists())->toBeTrue();
    expect($db->connection('tenant')->table('roles')->where('name', 'admin')->exists())->toBeTrue();
    expect($db->connection('tenant')->table('roles')->where('name', 'staff')->exists())->toBeTrue();

    // Settings were seeded
    expect($db->connection('tenant')->table('settings')->where('key', 'currency')->exists())->toBeTrue();
    expect($db->connection('tenant')->table('settings')->where('key', 'timezone')->exists())->toBeTrue();

    // Owner has the owner role
    $ownerId = $db->connection('tenant')->table('users')->where('email', 'owner@provision.com')->value('id');
    $ownerRoleId = $db->connection('tenant')->table('roles')->where('name', 'owner')->value('id');
    expect($db->connection('tenant')->table('user_roles')
        ->where('user_id', $ownerId)
        ->where('role_id', $ownerRoleId)
        ->exists()
    )->toBeTrue();
});

test('ProvisionTenantDatabase job is idempotent: running twice does not duplicate roles or settings', function (): void {
    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Idempotent Corp',
        'slug' => 'idempotent-co',
        'database_name' => 'tenant_idempotent_co',
        'status' => 'provisioning',
    ]);

    // First run
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenant->id,
        ownerEmail: 'owner@idempotent.com',
        ownerName: 'Idempotent Owner',
    );

    $tenant->refresh();
    expect($tenant->status)->toBe('active');

    // Reset to provisioning to simulate re-queue (e.g., before status update was committed)
    $tenant->update(['status' => 'provisioning']);

    // Second run must not throw or duplicate data
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenant->id,
        ownerEmail: 'owner@idempotent.com',
        ownerName: 'Idempotent Owner',
    );

    $tenant->refresh();
    expect($tenant->status)->toBe('active');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Roles not duplicated
    $ownerRoleCount = $db->connection('tenant')->table('roles')->where('name', 'owner')->count();
    expect($ownerRoleCount)->toBe(1);

    // Users not duplicated
    $ownerUserCount = $db->connection('tenant')->table('users')->where('email', 'owner@idempotent.com')->count();
    expect($ownerUserCount)->toBe(1);

    // Settings not duplicated
    $currencyCount = $db->connection('tenant')->table('settings')->where('key', 'currency')->count();
    expect($currencyCount)->toBe(1);
});

test('ProvisionTenantDatabase job marks tenant provisioning_failed on error and rethrows', function (): void {
    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Fail Corp',
        'slug' => 'fail-corp',
        'database_name' => 'tenant_fail_corp',
        'status' => 'provisioning',
    ]);

    // Force a connection failure by pointing the PDO host to a non-connectable address
    config(['database.connections.central.host' => '0.0.0.0']);

    $threw = false;

    try {
        ProvisionTenantDatabase::dispatchSync(
            tenantId: $tenant->id,
            ownerEmail: 'owner@fail.com',
            ownerName: 'Fail Owner',
        );
    } catch (Throwable) {
        $threw = true;
    } finally {
        // Restore real host before any assertions (uses the central connection)
        config(['database.connections.central.host' => 'postgres']);
        app('db')->purge('central');
        app('db')->reconnect('central');
    }

    expect($threw)->toBeTrue();

    $tenant->refresh();
    expect($tenant->status)->toBe('provisioning_failed')
        ->and($tenant->provisioning_error)->not->toBeNull();
});

test('ProvisionTenantDatabase job succeeds on retry after previous failure', function (): void {
    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Retry Corp',
        'slug' => 'retry-co',
        'database_name' => 'tenant_retry_co',
        'status' => 'provisioning',
    ]);

    // Simulate a previous failed run by setting provisioning_failed with an error
    $tenant->update([
        'status' => 'provisioning_failed',
        'provisioning_error' => 'Previous run failed',
    ]);

    // Retry: reset to provisioning then run the job (as a queue worker would re-queue it)
    $tenant->update(['status' => 'provisioning']);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenant->id,
        ownerEmail: 'owner@retry.com',
        ownerName: 'Retry Owner',
    );

    $tenant->refresh();
    expect($tenant->status)->toBe('active')
        ->and($tenant->provisioning_error)->toBeNull();

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection('tenant')->table('users')->where('email', 'owner@retry.com')->exists())->toBeTrue();
});

test('job skips re-provisioning if tenant is already active', function (): void {
    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Already Active Corp',
        'slug' => 'already-active',
        'database_name' => 'tenant_already_active',
        'status' => 'active',
    ]);

    // Job should exit early without touching the DB
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenant->id,
        ownerEmail: 'owner@active.com',
        ownerName: 'Active Owner',
    );

    $tenant->refresh();
    // Status unchanged, no error
    expect($tenant->status)->toBe('active')
        ->and($tenant->provisioning_error)->toBeNull();
});

test('migration failure midway marks tenant provisioning_failed and retry succeeds', function (): void {
    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Midway Failure Corp',
        'slug' => 'midway-fail-co',
        'database_name' => 'tenant_midway_fail_co',
        'status' => 'provisioning',
    ]);

    $failMidway = true;

    Event::listen(
        MigrationStarted::class,
        function (MigrationStarted $event) use (&$failMidway): void {
            if ($failMidway && str_contains((string) $event->name, 'create_tenant_roles_table')) {
                throw new RuntimeException('Midway migration failure during roles table migration');
            }
        }
    );

    $threw = false;

    try {
        ProvisionTenantDatabase::dispatchSync(
            tenantId: $tenant->id,
            ownerEmail: 'owner@midway.com',
            ownerName: 'Midway Owner',
        );
    } catch (Throwable $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Midway migration failure during roles table migration');
    }

    expect($threw)->toBeTrue();

    // Verify tenant marked as provisioning_failed with error
    $tenant->refresh();
    expect($tenant->status)->toBe('provisioning_failed')
        ->and($tenant->provisioning_error)->toContain('Midway migration failure during roles table migration');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Verify first migration (users) succeeded and table exists
    expect($db->connection('tenant')->getSchemaBuilder()->hasTable('users'))->toBeTrue();
    // Verify second migration (roles) did NOT complete
    expect($db->connection('tenant')->getSchemaBuilder()->hasTable('roles'))->toBeFalse();

    $tenantManager->reset();

    // Now retry: remove failure flag and reset status to provisioning
    $failMidway = false;
    $tenant->update(['status' => 'provisioning']);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenant->id,
        ownerEmail: 'owner@midway.com',
        ownerName: 'Midway Owner',
    );

    $tenant->refresh();
    expect($tenant->status)->toBe('active')
        ->and($tenant->provisioning_error)->toBeNull();

    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    // After retry: roles, settings, and owner user now exist
    expect($db->connection('tenant')->getSchemaBuilder()->hasTable('roles'))->toBeTrue();
    expect($db->connection('tenant')->getSchemaBuilder()->hasTable('settings'))->toBeTrue();
    expect($db->connection('tenant')->table('users')->where('email', 'owner@midway.com')->exists())->toBeTrue();
});
