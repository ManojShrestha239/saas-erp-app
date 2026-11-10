<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Audit\Models\TenantActivity;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\Models\Warehouse;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropAuditTestTenantDatabase')) {
    function dropAuditTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_audit_alpha', 'tenant_audit_beta'], true)) {
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

    dropAuditTestTenantDatabase('tenant_audit_alpha');
    dropAuditTestTenantDatabase('tenant_audit_beta');

    Domain::query()->whereIn('host', ['auditalpha.nexuserp.test', 'auditbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['auditalpha', 'auditbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Audit Alpha Corp',
        'slug' => 'auditalpha',
        'database_name' => 'tenant_audit_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'auditalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@auditalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Audit Beta Corp',
        'slug' => 'auditbeta',
        'database_name' => 'tenant_audit_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'auditbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@auditbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha Users
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@auditalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->alphaAccountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@auditalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaAccountant->assignRole(TenantRole::Accountant->value);

    $this->alphaSalesRep = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'salesrep@auditalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaSalesRep->assignRole(TenantRole::SalesRep->value);

    $this->alphaWarehouseManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@auditalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaWarehouseManager->assignRole(TenantRole::WarehouseManager->value);
});

afterEach(function (): void {
    dropAuditTestTenantDatabase('tenant_audit_alpha');
    dropAuditTestTenantDatabase('tenant_audit_beta');
});

test('tenant activity logs are strictly immutable and cannot be updated or deleted', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $activity = TenantActivity::create([
        'description' => 'Original immutability check log',
        'log_name' => 'default',
        'event' => 'custom',
    ]);

    expect($activity->id)->not->toBeNull();

    // Attempting to update MUST throw AUDIT_LOG_IMMUTABLE
    expect(fn () => $activity->update(['description' => 'Tampered description']))
        ->toThrow(ApiException::class, 'Audit logs are immutable and cannot be updated.');

    // Confirm database record was not altered
    expect($activity->fresh()->description)->toBe('Original immutability check log');

    // Attempting to delete MUST throw AUDIT_LOG_IMMUTABLE
    expect(fn () => $activity->delete())
        ->toThrow(ApiException::class, 'Audit logs are immutable and cannot be deleted.');

    // Confirm database record still exists
    expect(TenantActivity::find($activity->id))->not->toBeNull();
});

test('domain model mutation automatically generates auditable activity log with diffs', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    // Clear any previous logs from seeders
    TenantActivity::truncate();

    // Create a Warehouse
    $warehouse = Warehouse::create([
        'id' => (string) Str::ulid(),
        'name' => 'Kathmandu Logistics Hub',
        'code' => 'WH-KTM-01',
        'address' => 'Balaju Industrial Estate',
        'is_default' => true,
        'is_active' => true,
    ]);

    $createdLog = TenantActivity::where('subject_type', Warehouse::class)
        ->where('subject_id', $warehouse->id)
        ->where('event', 'created')
        ->first();

    $createdAttributes = $createdLog?->attribute_changes?->get('attributes') ?? $createdLog?->properties?->get('attributes');

    expect($createdLog)->not->toBeNull()
        ->and($createdLog->event)->toBe('created')
        ->and($createdAttributes['name'])->toBe('Kathmandu Logistics Hub');

    // Update Warehouse
    $warehouse->update(['name' => 'Greater Kathmandu Central Hub']);

    $updatedLog = TenantActivity::where('subject_type', Warehouse::class)
        ->where('subject_id', $warehouse->id)
        ->where('event', 'updated')
        ->first();

    $updatedOld = $updatedLog?->attribute_changes?->get('old') ?? $updatedLog?->properties?->get('old');
    $updatedAttributes = $updatedLog?->attribute_changes?->get('attributes') ?? $updatedLog?->properties?->get('attributes');

    expect($updatedLog)->not->toBeNull()
        ->and($updatedLog->event)->toBe('updated')
        ->and($updatedOld['name'])->toBe('Kathmandu Logistics Hub')
        ->and($updatedAttributes['name'])->toBe('Greater Kathmandu Central Hub');
});

test('RBAC: Owner and Accountant can view audit logs, but Sales Rep and Warehouse Manager get 403', function (): void {
    // 1. Owner Login -> 200 OK
    $loginResp = $this->postJson('http://auditalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@auditalpha.nexuserp.test',
        'password' => 'Password12345!',
    ]);
    $loginResp->assertOk();

    $response = $this->getJson('http://auditalpha.nexuserp.test/api/v1/audit-logs');
    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'event', 'description', 'subject_type', 'subject_id', 'changes', 'created_at'],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

    $this->flushSession();

    // 2. Accountant Login -> 200 OK
    $loginResp = $this->postJson('http://auditalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@auditalpha.nexuserp.test',
        'password' => 'Password12345!',
    ]);
    $loginResp->assertOk();

    $this->getJson('http://auditalpha.nexuserp.test/api/v1/audit-logs')
        ->assertOk();

    $this->flushSession();

    // 3. Sales Rep Login -> 403 Forbidden
    $loginResp = $this->postJson('http://auditalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'salesrep@auditalpha.nexuserp.test',
        'password' => 'Password12345!',
    ]);
    $loginResp->assertOk();

    $this->getJson('http://auditalpha.nexuserp.test/api/v1/audit-logs')
        ->assertForbidden();

    $this->flushSession();

    // 4. Warehouse Manager Login -> 403 Forbidden
    $loginResp = $this->postJson('http://auditalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'warehouse@auditalpha.nexuserp.test',
        'password' => 'Password12345!',
    ]);
    $loginResp->assertOk();

    $this->getJson('http://auditalpha.nexuserp.test/api/v1/audit-logs')
        ->assertForbidden();
});

test('audit log viewer supports filtering by event and search query', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantActivity::truncate();

    TenantActivity::create([
        'description' => 'Invoice 001 created',
        'event' => 'created',
        'subject_type' => 'Modules\Sales\Models\SalesInvoice',
        'subject_id' => (string) Str::ulid(),
    ]);

    TenantActivity::create([
        'description' => 'Warehouse stock adjustment updated',
        'event' => 'updated',
        'subject_type' => 'Modules\Inventory\Models\StockAdjustment',
        'subject_id' => (string) Str::ulid(),
    ]);

    $this->postJson('http://auditalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@auditalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    // Filter event=created
    $resCreated = $this->getJson('http://auditalpha.nexuserp.test/api/v1/audit-logs?event=created');
    $resCreated->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'created')
        ->assertJsonPath('data.0.description', 'Invoice 001 created');

    // Search "adjustment"
    $resSearch = $this->getJson('http://auditalpha.nexuserp.test/api/v1/audit-logs?search=adjustment');
    $resSearch->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'updated');
});

test('tenant isolation: tenant alpha activities are completely isolated from tenant beta', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha Tenant activity
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantActivity::truncate();
    TenantActivity::create([
        'description' => 'Confidential Alpha Secret Activity',
        'event' => 'created',
    ]);

    // Beta Tenant setup
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@auditbeta.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    TenantActivity::truncate();
    TenantActivity::create([
        'description' => 'Beta Activity Record',
        'event' => 'created',
    ]);

    $this->flushSession();

    // Query as Beta Owner
    $this->postJson('http://auditbeta.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@auditbeta.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://auditbeta.nexuserp.test/api/v1/audit-logs');
    $response->assertOk();

    $descriptions = collect($response->json('data'))->pluck('description')->all();
    expect($descriptions)->toContain('Beta Activity Record')
        ->not->toContain('Confidential Alpha Secret Activity');
});
