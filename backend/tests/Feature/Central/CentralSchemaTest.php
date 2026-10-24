<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class TestTenantModel extends Model
{
    protected $connection = 'central';

    protected $table = 'tenants';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}

final class TestDomainModel extends Model
{
    protected $connection = 'central';

    protected $table = 'domains';

    protected $guarded = [];
}

final class TestPackageModel extends Model
{
    protected $connection = 'central';

    protected $table = 'packages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features' => 'array',
        ];
    }
}

final class TestSubscriptionModel extends Model
{
    protected $connection = 'central';

    protected $table = 'subscriptions';

    protected $guarded = [];
}

final class TestSuperAdminModel extends Model
{
    protected $connection = 'central';

    protected $table = 'super_admins';

    protected $guarded = [];
}

final class TestGlobalAuditLogModel extends Model
{
    protected $connection = 'central';

    protected $table = 'global_audit_logs';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function (): void {
    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);
});

afterEach(function (): void {
    TestGlobalAuditLogModel::query()->delete();
    TestSubscriptionModel::query()->delete();
    TestDomainModel::query()->delete();
    TestTenantModel::query()->delete();
    TestPackageModel::query()->delete();
    TestSuperAdminModel::query()->delete();
});

test('all required central tables exist on the central connection', function (string $table): void {
    expect(Schema::connection('central')->hasTable($table))->toBeTrue();
})->with([
    'tenants',
    'domains',
    'packages',
    'subscriptions',
    'super_admins',
    'global_audit_logs',
]);

test('tenants table rejects duplicate slug', function (): void {
    $ulid1 = (string) Str::ulid();
    $ulid2 = (string) Str::ulid();

    TestTenantModel::query()->create([
        'id' => $ulid1,
        'name' => 'Acme Corporation',
        'slug' => 'acme-corp',
        'database_name' => 'tenant_acme_1',
        'status' => 'active',
    ]);

    expect(fn () => TestTenantModel::query()->create([
        'id' => $ulid2,
        'name' => 'Acme Duplicate',
        'slug' => 'acme-corp',
        'database_name' => 'tenant_acme_2',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

test('tenants table rejects duplicate database_name', function (): void {
    $ulid1 = (string) Str::ulid();
    $ulid2 = (string) Str::ulid();

    TestTenantModel::query()->create([
        'id' => $ulid1,
        'name' => 'First Company',
        'slug' => 'first-co',
        'database_name' => 'tenant_shared_db',
        'status' => 'active',
    ]);

    expect(fn () => TestTenantModel::query()->create([
        'id' => $ulid2,
        'name' => 'Second Company',
        'slug' => 'second-co',
        'database_name' => 'tenant_shared_db',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

test('domains table rejects duplicate host', function (): void {
    $tenantId = (string) Str::ulid();

    TestTenantModel::query()->create([
        'id' => $tenantId,
        'name' => 'Domain Corp',
        'slug' => 'domain-corp',
        'database_name' => 'tenant_domain_corp',
        'status' => 'active',
    ]);

    TestDomainModel::query()->create([
        'tenant_id' => $tenantId,
        'host' => 'portal.acme.test',
        'is_primary' => true,
    ]);

    expect(fn () => TestDomainModel::query()->create([
        'tenant_id' => $tenantId,
        'host' => 'portal.acme.test',
        'is_primary' => false,
    ]))->toThrow(QueryException::class);
});

test('packages table rejects duplicate slug', function (): void {
    TestPackageModel::query()->create([
        'name' => 'Growth Package',
        'slug' => 'growth',
        'max_users' => 10,
        'max_warehouses' => 2,
        'max_invoices_per_month' => 500,
        'features' => ['inventory' => true],
        'price_cents' => 9900,
    ]);

    expect(fn () => TestPackageModel::query()->create([
        'name' => 'Growth Package 2',
        'slug' => 'growth',
        'max_users' => 20,
        'max_warehouses' => 5,
        'max_invoices_per_month' => 1000,
        'features' => ['inventory' => true],
        'price_cents' => 19900,
    ]))->toThrow(QueryException::class);
});

test('super_admins table rejects duplicate email', function (): void {
    TestSuperAdminModel::query()->create([
        'name' => 'Platform Admin',
        'email' => 'admin@nexuserp.test',
        'password' => 'secret_hash',
    ]);

    expect(fn () => TestSuperAdminModel::query()->create([
        'name' => 'Another Admin',
        'email' => 'admin@nexuserp.test',
        'password' => 'another_secret',
    ]))->toThrow(QueryException::class);
});

test('cascade delete on domains removes linked domains when tenant is deleted', function (): void {
    $tenantId = (string) Str::ulid();

    $tenant = TestTenantModel::query()->create([
        'id' => $tenantId,
        'name' => 'Ephemeral Corp',
        'slug' => 'ephemeral',
        'database_name' => 'tenant_ephemeral',
        'status' => 'active',
    ]);

    TestDomainModel::query()->create([
        'tenant_id' => $tenantId,
        'host' => 'ephemeral.nexuserp.test',
        'is_primary' => true,
    ]);

    expect(TestDomainModel::query()->where('tenant_id', $tenantId)->count())->toBe(1);

    $tenant->delete();

    expect(TestDomainModel::query()->where('tenant_id', $tenantId)->count())->toBe(0);
});

test('packages price_cents is BIGINT and stores values beyond 32-bit integer range without overflow', function (): void {
    $columnType = Schema::connection('central')->getColumnType('packages', 'price_cents');
    expect(in_array($columnType, ['bigint', 'int8'], true))->toBeTrue();

    // 5 billion cents ($50,000,000.00) exceeds 32-bit signed integer max (2,147,483,647)
    $largeAmountCents = 5_000_000_000;

    $package = TestPackageModel::query()->create([
        'name' => 'Enterprise Unlimited',
        'slug' => 'enterprise-unlimited',
        'max_users' => 1000,
        'max_warehouses' => 50,
        'max_invoices_per_month' => 100000,
        'features' => ['custom_domain' => true],
        'price_cents' => $largeAmountCents,
    ]);

    $retrieved = TestPackageModel::query()->find($package->id);

    expect((int) $retrieved->price_cents)->toBe($largeAmountCents);
});

test('global_audit_logs is append-only without updated_at and indexes tenant_id and created_at', function (): void {
    expect(Schema::connection('central')->hasColumn('global_audit_logs', 'created_at'))->toBeTrue()
        ->and(Schema::connection('central')->hasColumn('global_audit_logs', 'updated_at'))->toBeFalse();

    $tenant = TestTenantModel::query()->create([
        'id' => (string) Str::ulid(),
        'name' => 'Audit Tenant',
        'slug' => 'audit-tenant',
        'database_name' => 'tenant_audit',
        'status' => 'active',
    ]);

    $log = TestGlobalAuditLogModel::query()->create([
        'tenant_id' => $tenant->id,
        'event' => 'tenant.registered',
        'auditable_type' => 'Tenant',
        'auditable_id' => $tenant->id,
        'ip_address' => '127.0.0.1',
    ]);

    expect($log->id)->not->toBeNull();

    $globalLog = TestGlobalAuditLogModel::query()->create([
        'tenant_id' => null,
        'event' => 'system.maintenance_enabled',
        'ip_address' => '127.0.0.1',
    ]);

    expect($globalLog->id)->not->toBeNull()
        ->and($globalLog->tenant_id)->toBeNull();
});

test('foreign key and status columns have database indexes', function (): void {
    $tenantIndexes = Schema::connection('central')->getIndexes('tenants');
    $statusIndexed = collect($tenantIndexes)->contains(fn (array $idx): bool => in_array('status', $idx['columns'], true));
    expect($statusIndexed)->toBeTrue();

    $domainIndexes = Schema::connection('central')->getIndexes('domains');
    $tenantIdIndexed = collect($domainIndexes)->contains(fn (array $idx): bool => in_array('tenant_id', $idx['columns'], true));
    expect($tenantIdIndexed)->toBeTrue();

    $subIndexes = Schema::connection('central')->getIndexes('subscriptions');
    $subTenantIndexed = collect($subIndexes)->contains(fn (array $idx): bool => in_array('tenant_id', $idx['columns'], true));
    $subPackageIndexed = collect($subIndexes)->contains(fn (array $idx): bool => in_array('package_id', $idx['columns'], true));
    $subStatusIndexed = collect($subIndexes)->contains(fn (array $idx): bool => in_array('status', $idx['columns'], true));
    expect($subTenantIndexed)->toBeTrue()
        ->and($subPackageIndexed)->toBeTrue()
        ->and($subStatusIndexed)->toBeTrue();

    $auditIndexes = Schema::connection('central')->getIndexes('global_audit_logs');
    $auditTenantIndexed = collect($auditIndexes)->contains(fn (array $idx): bool => in_array('tenant_id', $idx['columns'], true));
    $auditCreatedIndexed = collect($auditIndexes)->contains(fn (array $idx): bool => in_array('created_at', $idx['columns'], true));
    expect($auditTenantIndexed)->toBeTrue()
        ->and($auditCreatedIndexed)->toBeTrue();
});
