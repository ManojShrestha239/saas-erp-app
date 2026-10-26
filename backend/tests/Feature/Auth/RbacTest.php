<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantPermission;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropRbacTestTenantDatabase')) {
    function dropRbacTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha', 'tenant_beta'], true)) {
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

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropRbacTestTenantDatabase('tenant_alpha');
    dropRbacTestTenantDatabase('tenant_beta');

    Domain::query()->whereIn('host', ['alpha.nexuserp.test', 'beta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha', 'beta'])->delete();

    // 1. Provision alpha tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha Corp',
        'slug' => 'alpha',
        'database_name' => 'tenant_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision beta tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Corp',
        'slug' => 'beta',
        'database_name' => 'tenant_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    $this->alphaTenant = $alphaTenant;
    $this->betaTenant = $betaTenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropRbacTestTenantDatabase('tenant_alpha');
    dropRbacTestTenantDatabase('tenant_beta');
});

test('provisioning seeds all PRD roles and permissions and assigns owner role to tenant owner', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    // Verify all roles exist
    foreach (TenantRole::cases() as $role) {
        expect(Role::where('name', $role->value)->exists())->toBeTrue();
    }

    // Verify all permissions exist
    foreach (TenantPermission::cases() as $perm) {
        expect(Permission::where('name', $perm->value)->exists())->toBeTrue();
    }

    // Verify owner user has owner role and all permissions
    $owner = TenantUser::where('email', 'owner@alpha.nexuserp.test')->firstOrFail();
    expect($owner->hasRole(TenantRole::Owner))->toBeTrue();

    foreach (TenantPermission::cases() as $perm) {
        expect($owner->can($perm->value))->toBeTrue();
    }
});

test('accountant role has financial and reporting capabilities but cannot manage users or adjust stock', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $accountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@alpha.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);

    $accountant->assignRole(TenantRole::Accountant);

    expect($accountant->hasRole(TenantRole::Accountant))->toBeTrue();

    // Allowed permissions
    expect($accountant->can(TenantPermission::AccountingManage->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::ReportsFinancial->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::ReportsStock->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::SalesManage->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::PurchasesManage->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::PurchasesPoDraft->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::CustomersManage->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::SuppliersManage->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::PaymentsCustomerReceipts->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::PaymentsSupplierBills->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::AuditView->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::ProductsView->value))->toBeTrue()
        ->and($accountant->can(TenantPermission::StockView->value))->toBeTrue();

    // Prohibited permissions per PRD matrix
    expect($accountant->can(TenantPermission::UsersManage->value))->toBeFalse()
        ->and($accountant->can(TenantPermission::RolesManage->value))->toBeFalse()
        ->and($accountant->can(TenantPermission::SettingsManage->value))->toBeFalse()
        ->and($accountant->can(TenantPermission::ProductsManage->value))->toBeFalse()
        ->and($accountant->can(TenantPermission::StockAdjust->value))->toBeFalse();
});

test('warehouse manager role has stock adjust and po draft but cannot manage accounting or sales', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $warehouse = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Manager',
        'email' => 'warehouse@alpha.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);

    $warehouse->assignRole(TenantRole::WarehouseManager);

    expect($warehouse->hasRole(TenantRole::WarehouseManager))->toBeTrue();

    // Allowed permissions
    expect($warehouse->can(TenantPermission::ProductsView->value))->toBeTrue()
        ->and($warehouse->can(TenantPermission::StockView->value))->toBeTrue()
        ->and($warehouse->can(TenantPermission::ProductsManage->value))->toBeTrue()
        ->and($warehouse->can(TenantPermission::StockAdjust->value))->toBeTrue()
        ->and($warehouse->can(TenantPermission::PurchasesPoDraft->value))->toBeTrue()
        ->and($warehouse->can(TenantPermission::ReportsStock->value))->toBeTrue();

    // Prohibited permissions per PRD matrix
    expect($warehouse->can(TenantPermission::UsersManage->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::AccountingManage->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::SalesManage->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::CustomersManage->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::SuppliersManage->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::PurchasesManage->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::PaymentsCustomerReceipts->value))->toBeFalse()
        ->and($warehouse->can(TenantPermission::AuditView->value))->toBeFalse();
});

test('sales rep role has customers and sales manage but cannot manage suppliers or accounting', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $salesRep = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@alpha.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);

    $salesRep->assignRole(TenantRole::SalesRep);

    expect($salesRep->hasRole(TenantRole::SalesRep))->toBeTrue();

    // Allowed permissions
    expect($salesRep->can(TenantPermission::ProductsView->value))->toBeTrue()
        ->and($salesRep->can(TenantPermission::StockView->value))->toBeTrue()
        ->and($salesRep->can(TenantPermission::CustomersManage->value))->toBeTrue()
        ->and($salesRep->can(TenantPermission::SalesManage->value))->toBeTrue()
        ->and($salesRep->can(TenantPermission::PaymentsCustomerReceipts->value))->toBeTrue();

    // Prohibited permissions per PRD matrix
    expect($salesRep->can(TenantPermission::SuppliersManage->value))->toBeFalse()
        ->and($salesRep->can(TenantPermission::PurchasesManage->value))->toBeFalse()
        ->and($salesRep->can(TenantPermission::AccountingManage->value))->toBeFalse()
        ->and($salesRep->can(TenantPermission::StockAdjust->value))->toBeFalse()
        ->and($salesRep->can(TenantPermission::ReportsFinancial->value))->toBeFalse()
        ->and($salesRep->can(TenantPermission::AuditView->value))->toBeFalse()
        ->and($salesRep->can(TenantPermission::UsersManage->value))->toBeFalse();
});

test('roles and permissions are strictly isolated across tenants and cache does not leak', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // 1. Switch to alpha, create user with Accountant role
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $alphaUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha User',
        'email' => 'worker@alpha.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);
    $alphaUser->assignRole(TenantRole::Accountant);

    expect($alphaUser->can(TenantPermission::AccountingManage->value))->toBeTrue()
        ->and($alphaUser->can(TenantPermission::StockAdjust->value))->toBeFalse();

    // 2. Switch to beta, create user with WarehouseManager role
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    $betaUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Beta User',
        'email' => 'worker@beta.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);
    $betaUser->assignRole(TenantRole::WarehouseManager);

    expect($betaUser->can(TenantPermission::StockAdjust->value))->toBeTrue()
        ->and($betaUser->can(TenantPermission::AccountingManage->value))->toBeFalse();

    // 3. Switch back to alpha: alpha permissions remain intact and did not adopt beta state
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $reloadedAlphaUser = TenantUser::where('email', 'worker@alpha.nexuserp.test')->firstOrFail();
    expect($reloadedAlphaUser->can(TenantPermission::AccountingManage->value))->toBeTrue()
        ->and($reloadedAlphaUser->can(TenantPermission::StockAdjust->value))->toBeFalse();

    // Beta user cannot be found in alpha DB
    expect(TenantUser::where('email', 'worker@beta.nexuserp.test')->exists())->toBeFalse();
});

test('direct permissions can be granted and revoked on tenant users', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $user = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Special Worker',
        'email' => 'special@alpha.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);

    expect($user->can(TenantPermission::AuditView->value))->toBeFalse();

    $user->givePermissionTo(TenantPermission::AuditView->value);
    expect($user->can(TenantPermission::AuditView->value))->toBeTrue();

    $user->revokePermissionTo(TenantPermission::AuditView->value);
    expect($user->can(TenantPermission::AuditView->value))->toBeFalse();
});

test('role can be revoked dynamically and permissions update immediately', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $user = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Dynamic Rep',
        'email' => 'dynamic@alpha.nexuserp.test',
        'password' => Hash::make('Secret12345678'),
    ]);

    $user->assignRole(TenantRole::SalesRep);
    expect($user->can(TenantPermission::SalesManage->value))->toBeTrue();

    $user->removeRole(TenantRole::SalesRep);
    expect($user->can(TenantPermission::SalesManage->value))->toBeFalse();
});
