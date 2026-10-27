<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropWhStockTestTenantDatabase')) {
    function dropWhStockTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_wh_alpha', 'tenant_wh_beta'], true)) {
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

    dropWhStockTestTenantDatabase('tenant_wh_alpha');
    dropWhStockTestTenantDatabase('tenant_wh_beta');

    Domain::query()->whereIn('host', ['whalpha.nexuserp.test', 'whbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['whalpha', 'whbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Warehouse Alpha Corp',
        'slug' => 'whalpha',
        'database_name' => 'tenant_wh_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'whalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@whalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Warehouse Beta Corp',
        'slug' => 'whbeta',
        'database_name' => 'tenant_wh_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'whbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@whbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha users setup
    $tenantManager->switchTo(new TenantContext(
        id: $alphaTenant->id,
        slug: $alphaTenant->slug,
        databaseName: $alphaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@whalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Warehouse Manager on Alpha
    $warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@whalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $warehouseUser->assignRole(TenantRole::WarehouseManager);

    // Create Accountant on Alpha
    $accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@whalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $accountantUser->assignRole(TenantRole::Accountant);

    // Create test product on Alpha
    $alphaProduct = Product::create([
        'sku' => 'ALPHA-PROD-01',
        'name' => 'Alpha Steel Pipe',
        'cost_price_cents' => 2000,
        'selling_price_cents' => 3500,
        'tax_category' => 'standard',
        'low_stock_threshold' => 10,
    ]);

    $this->alphaProductId = $alphaProduct->id;

    $tenantManager->reset();

    // Beta users setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@whbeta.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);
    $tenantManager->reset();

    $this->alphaTenant = $alphaTenant;
    $this->betaTenant = $betaTenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropWhStockTestTenantDatabase('tenant_wh_alpha');
    dropWhStockTestTenantDatabase('tenant_wh_beta');
});

function loginAsWhTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
{
    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

test('unauthenticated requests to warehouse and stock endpoints return 401 UNAUTHENTICATED', function (): void {
    $this->getJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->getJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-levels')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->getJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
        'warehouse_id' => (string) Str::ulid(),
        'product_id' => (string) Str::ulid(),
        'type' => 'adjustment_in',
        'quantity' => 10,
    ])->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('RBAC: accountant can view warehouses and stock levels but cannot create warehouse or record stock movement', function (): void {
    $accountantCookie = loginAsWhTenant($this, 'whalpha.nexuserp.test', 'accountant@whalpha.nexuserp.test');

    // 1. Accountant can view warehouses
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->getJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Accountant can view stock levels
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->getJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-levels')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // 3. Accountant CANNOT create a warehouse (403 FORBIDDEN)
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Accountant Depot',
            'code' => 'ACC-DEPOT',
        ])->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    // 4. Accountant CANNOT record stock movement (403 FORBIDDEN)
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
            'warehouse_id' => (string) Str::ulid(),
            'product_id' => $this->alphaProductId,
            'type' => 'adjustment_in',
            'quantity' => 10,
        ])->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('Warehouse lifecycle: create, default flag toggle, update, and deletion rules', function (): void {
    $ownerCookie = loginAsWhTenant($this, 'whalpha.nexuserp.test', 'owner@whalpha.nexuserp.test');

    // 1. Create first warehouse as default
    $wh1Response = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'address' => '123 Logistics Way',
            'is_default' => true,
        ]);

    $wh1Response->assertStatus(201)
        ->assertJsonPath('data.code', 'WH-MAIN')
        ->assertJsonPath('data.is_default', true);

    /** @var string $wh1Id */
    $wh1Id = $wh1Response->json('data.id');

    // 2. Create second warehouse as default, unsetting wh1 default flag
    $wh2Response = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Secondary Warehouse',
            'code' => 'WH-SEC',
            'is_default' => true,
        ]);

    $wh2Response->assertStatus(201)
        ->assertJsonPath('data.code', 'WH-SEC')
        ->assertJsonPath('data.is_default', true);

    /** @var string $wh2Id */
    $wh2Id = $wh2Response->json('data.id');

    // Verify WH1 is no longer default
    $wh1Check = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/warehouses/{$wh1Id}");
    $wh1Check->assertStatus(200)
        ->assertJsonPath('data.is_default', false);

    // 3. Duplicate code is rejected
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Duplicate Warehouse',
            'code' => 'WH-MAIN',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    // 4. Update warehouse details
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->putJson("http://whalpha.nexuserp.test/api/v1/inventory/warehouses/{$wh2Id}", [
            'name' => 'Secondary Logistics Depot',
            'address' => '456 Freight Ave',
        ])->assertStatus(200)
        ->assertJsonPath('data.name', 'Secondary Logistics Depot')
        ->assertJsonPath('data.address', '456 Freight Ave');

    // 5. Delete warehouse with no stock or movements succeeds
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->deleteJson("http://whalpha.nexuserp.test/api/v1/inventory/warehouses/{$wh2Id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Warehouse deleted successfully.');
});

test('Atomic stock movements update cached stock levels and sum equals on-hand', function (): void {
    $whCookie = loginAsWhTenant($this, 'whalpha.nexuserp.test', 'warehouse@whalpha.nexuserp.test');

    // 1. Create a warehouse
    $whResponse = $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Central Hub',
            'code' => 'HUB-01',
            'is_default' => true,
        ]);
    $whResponse->assertStatus(201);
    /** @var string $warehouseId */
    $warehouseId = $whResponse->json('data.id');

    // 2. Record Opening Balance of 100 units
    $openRes = $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
            'warehouse_id' => $warehouseId,
            'product_id' => $this->alphaProductId,
            'type' => StockMovementType::OpeningBalance->value,
            'quantity' => 100,
            'notes' => 'Initial stock load',
        ]);

    $openRes->assertStatus(201)
        ->assertJsonPath('data.quantity', 100)
        ->assertJsonPath('data.type', 'opening_balance');

    // Verify stock_levels updated to 100
    $level1 = $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/stock-levels/{$warehouseId}/{$this->alphaProductId}");

    $level1->assertStatus(200)
        ->assertJsonPath('data.quantity_on_hand', 100)
        ->assertJsonPath('data.quantity_available', 100);

    // 3. Record Purchase Receipt of 50 units
    $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
            'warehouse_id' => $warehouseId,
            'product_id' => $this->alphaProductId,
            'type' => StockMovementType::PurchaseReceipt->value,
            'quantity' => 50,
            'unit_cost_cents' => 2100,
            'source_type' => 'purchase_order',
            'source_id' => 'PO-001',
        ])->assertStatus(201);

    // Verify stock_levels updated to 150
    $level2 = $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/stock-levels/{$warehouseId}/{$this->alphaProductId}");
    $level2->assertStatus(200)
        ->assertJsonPath('data.quantity_on_hand', 150);

    // 4. Record Sales Delivery (reduction) of 30 units
    $salesRes = $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
            'warehouse_id' => $warehouseId,
            'product_id' => $this->alphaProductId,
            'type' => StockMovementType::SalesDelivery->value,
            'quantity' => 30,
            'source_type' => 'sales_invoice',
            'source_id' => 'INV-001',
        ]);

    $salesRes->assertStatus(201)
        ->assertJsonPath('data.quantity', -30);

    // Verify stock_levels updated to 120 (150 - 30)
    $level3 = $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/stock-levels/{$warehouseId}/{$this->alphaProductId}");
    $level3->assertStatus(200)
        ->assertJsonPath('data.quantity_on_hand', 120);

    // 5. Verify movements list contains 3 movements
    $movementsList = $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/stock-movements?product_id={$this->alphaProductId}");

    $movementsList->assertStatus(200)
        ->assertJsonCount(3, 'data');

    // 6. Cannot delete warehouse now because movements exist
    $this->withCookie(config('session.cookie'), $whCookie)
        ->deleteJson("http://whalpha.nexuserp.test/api/v1/inventory/warehouses/{$warehouseId}")
        ->assertStatus(400)
        ->assertJsonPath('code', 'WAREHOUSE_HAS_MOVEMENTS');
});

test('Negative stock is rejected by default and allowed when tenant setting is on', function (): void {
    $whCookie = loginAsWhTenant($this, 'whalpha.nexuserp.test', 'warehouse@whalpha.nexuserp.test');

    // Create warehouse
    $whResponse = $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Stock Check Hub',
            'code' => 'SCH-01',
        ]);
    $warehouseId = $whResponse->json('data.id');

    // 1. Initial stock is 0. Attempting an AdjustmentOut of 5 units fails with 400 INSUFFICIENT_STOCK
    $oversellRes = $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
            'warehouse_id' => $warehouseId,
            'product_id' => $this->alphaProductId,
            'type' => StockMovementType::AdjustmentOut->value,
            'quantity' => 5,
        ]);

    $oversellRes->assertStatus(400)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    // Stock level was not created as negative
    $level = $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/stock-levels/{$warehouseId}/{$this->alphaProductId}");
    // Level is either null (not found) or 0
    if ($level->status() === 200) {
        expect($level->json('data.quantity_on_hand'))->toBe(0);
    }

    // 2. Now enable allow_negative_stock setting on Alpha tenant DB
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'allow_negative_stock'],
        ['value' => 'true', 'group' => 'inventory', 'updated_at' => now()]
    );
    $tenantManager->reset();

    // 3. Retry the same AdjustmentOut movement - now it succeeds!
    $allowedNegativeRes = $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/stock-movements', [
            'warehouse_id' => $warehouseId,
            'product_id' => $this->alphaProductId,
            'type' => StockMovementType::AdjustmentOut->value,
            'quantity' => 5,
        ]);

    $allowedNegativeRes->assertStatus(201)
        ->assertJsonPath('data.quantity', -5);

    $levelNegative = $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson("http://whalpha.nexuserp.test/api/v1/inventory/stock-levels/{$warehouseId}/{$this->alphaProductId}");

    $levelNegative->assertStatus(200)
        ->assertJsonPath('data.quantity_on_hand', -5)
        ->assertJsonPath('data.quantity_available', 0); // available clamps to 0
});

test('Multi-tenant isolation: warehouses, movements, and stock levels are isolated across tenants', function (): void {
    // 1. Alpha creates warehouse
    $alphaCookie = loginAsWhTenant($this, 'whalpha.nexuserp.test', 'owner@whalpha.nexuserp.test');
    $alphaWh = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://whalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Alpha Main Warehouse',
            'code' => 'WH-SHARED-CODE',
        ]);
    $alphaWh->assertStatus(201);
    /** @var string $alphaWhId */
    $alphaWhId = $alphaWh->json('data.id');

    // 2. Beta logs in with clean session
    $this->flushSession();
    $betaCookie = loginAsWhTenant($this, 'whbeta.nexuserp.test', 'owner@whbeta.nexuserp.test');

    // Beta CAN create warehouse with the exact same code WH-SHARED-CODE
    $betaWh = $this->withCookie(config('session.cookie'), $betaCookie)
        ->postJson('http://whbeta.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Beta Main Warehouse',
            'code' => 'WH-SHARED-CODE',
        ]);
    $betaWh->assertStatus(201);

    // Beta cannot view Alpha warehouse by ID
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://whbeta.nexuserp.test/api/v1/inventory/warehouses/{$alphaWhId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'WAREHOUSE_NOT_FOUND');

    // Beta warehouse list contains only Beta warehouse
    $betaList = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://whbeta.nexuserp.test/api/v1/inventory/warehouses');
    $betaList->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Beta Main Warehouse');
});
