<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Events\StockAdjusted;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropStockAdjTestTenantDatabase')) {
    function dropStockAdjTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_adj_alpha', 'tenant_adj_beta'], true)) {
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

    dropStockAdjTestTenantDatabase('tenant_adj_alpha');
    dropStockAdjTestTenantDatabase('tenant_adj_beta');

    Domain::query()->whereIn('host', ['adjalpha.nexuserp.test', 'adjbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['adjalpha', 'adjbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Adj Alpha Corp',
        'slug' => 'adjalpha',
        'database_name' => 'tenant_adj_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'adjalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@adjalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Adj Beta Corp',
        'slug' => 'adjbeta',
        'database_name' => 'tenant_adj_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'adjbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@adjbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha users and inventory setup
    $tenantManager->switchTo(new TenantContext(
        id: $alphaTenant->id,
        slug: $alphaTenant->slug,
        databaseName: $alphaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@adjalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Warehouse Manager on Alpha
    $warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@adjalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $warehouseUser->assignRole(TenantRole::WarehouseManager);

    // Create Accountant on Alpha
    $accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@adjalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $accountantUser->assignRole(TenantRole::Accountant);

    // Create warehouse on Alpha
    $alphaWarehouse = Warehouse::create([
        'name' => 'Alpha Central Depot',
        'code' => 'ALPHA-WH-1',
        'is_default' => true,
        'is_active' => true,
    ]);
    $this->alphaWarehouseId = $alphaWarehouse->id;

    // Create test products on Alpha
    $product1 = Product::create([
        'sku' => 'PROD-ADJ-001',
        'name' => 'Hydraulic Hose',
        'cost_price_cents' => 1500,
        'selling_price_cents' => 3000,
        'tax_category' => 'standard',
        'low_stock_threshold' => 5,
    ]);
    $product2 = Product::create([
        'sku' => 'PROD-ADJ-002',
        'name' => 'Ball Bearing Set',
        'cost_price_cents' => 2500,
        'selling_price_cents' => 5000,
        'tax_category' => 'standard',
        'low_stock_threshold' => 10,
    ]);

    $this->alphaProduct1Id = $product1->id;
    $this->alphaProduct2Id = $product2->id;

    $tenantManager->reset();

    // Beta users and setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@adjbeta.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $betaWarehouse = Warehouse::create([
        'name' => 'Beta Main Depot',
        'code' => 'BETA-WH-1',
        'is_default' => true,
        'is_active' => true,
    ]);
    $this->betaWarehouseId = $betaWarehouse->id;

    $tenantManager->reset();

    $this->alphaTenant = $alphaTenant;
    $this->betaTenant = $betaTenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropStockAdjTestTenantDatabase('tenant_adj_alpha');
    dropStockAdjTestTenantDatabase('tenant_adj_beta');
});

function loginAsAdjTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
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

test('unauthenticated requests to adjustments endpoints return 401 UNAUTHENTICATED', function (): void {
    $this->getJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->alphaWarehouseId,
        'reason' => 'Annual stock count discrepancy',
        'items' => [
            ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 5],
        ],
    ])->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $dummyId = (string) Str::ulid();
    $this->getJson("http://adjalpha.nexuserp.test/api/v1/inventory/adjustments/{$dummyId}")
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('RBAC: accountant can view adjustments but cannot create adjustments', function (): void {
    $accountantCookie = loginAsAdjTenant($this, 'adjalpha.nexuserp.test', 'accountant@adjalpha.nexuserp.test');

    // Accountant can list adjustments
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->getJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Accountant CANNOT create adjustment (403 FORBIDDEN)
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Attempted write-off by accountant',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 2],
            ],
        ])->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('validation: requires reason, at least one item, valid warehouse, and non-zero delta', function (): void {
    $ownerCookie = loginAsAdjTenant($this, 'adjalpha.nexuserp.test', 'owner@adjalpha.nexuserp.test');

    // Missing reason
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => '',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 5],
            ],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    // Empty items array
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Inventory check',
            'items' => [],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['items']);

    // Zero quantity_delta
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Inventory check',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 0],
            ],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.quantity_delta']);

    // Nonexistent warehouse
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => (string) Str::ulid(),
            'reason' => 'Inventory check',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 5],
            ],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['warehouse_id']);
});

test('stock adjustment lifecycle: creates adjustment, updates stock level, logs movements, and calculates costs', function (): void {
    $whManagerCookie = loginAsAdjTenant($this, 'adjalpha.nexuserp.test', 'warehouse@adjalpha.nexuserp.test');

    // Initial adjustment: +20 units of Product 1 at custom unit cost 1800 cents
    $response = $this->withCookie(config('session.cookie'), $whManagerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Initial physical count surplus',
            'items' => [
                [
                    'product_id' => $this->alphaProduct1Id,
                    'quantity_delta' => 20,
                    'unit_cost_cents' => 1800,
                ],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.warehouse_id', $this->alphaWarehouseId)
        ->assertJsonPath('data.reason', 'Initial physical count surplus')
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cost_impact_cents', 36000)
        ->assertJsonPath('data.items.0.quantity_before', 0)
        ->assertJsonPath('data.items.0.quantity_delta', 20)
        ->assertJsonPath('data.items.0.quantity_after', 20)
        ->assertJsonPath('data.items.0.unit_cost_cents', 1800)
        ->assertJsonPath('data.items.0.line_cost_impact_cents', 36000);

    /** @var string $adjId */
    $adjId = $response->json('data.id');
    /** @var string $adjNumber */
    $adjNumber = $response->json('data.adjustment_number');
    expect($adjNumber)->toMatch('/^ADJ-\d{4}-\d{6}$/');

    // Verify stock level updated in DB
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $level = StockLevel::where('warehouse_id', $this->alphaWarehouseId)
        ->where('product_id', $this->alphaProduct1Id)
        ->first();
    expect($level)->not->toBeNull();
    expect($level->quantity_on_hand)->toBe(20);

    // Verify stock movement logged
    $movement = StockMovement::where('warehouse_id', $this->alphaWarehouseId)
        ->where('product_id', $this->alphaProduct1Id)
        ->where('source_type', 'stock_adjustment')
        ->where('source_id', $adjId)
        ->first();
    expect($movement)->not->toBeNull();
    expect($movement->type)->toBe(StockMovementType::AdjustmentIn);
    expect($movement->quantity)->toBe(20);
    expect($movement->unit_cost_cents)->toBe(1800);

    $tenantManager->reset();

    // Second adjustment: Multi-item adjustment (-5 Product 1, +10 Product 2)
    // Product 2 will use its default cost price (2500 cents)
    $multiResponse = $this->withCookie(config('session.cookie'), $whManagerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Cycle count corrections',
            'items' => [
                [
                    'product_id' => $this->alphaProduct1Id,
                    'quantity_delta' => -5,
                    'unit_cost_cents' => 1800, // -5 * 1800 = -9000
                ],
                [
                    'product_id' => $this->alphaProduct2Id,
                    'quantity_delta' => 10,
                    // null unit_cost_cents -> fallback to product cost_price_cents = 2500
                    // +10 * 2500 = +25000
                ],
            ],
        ]);

    // Expected total cost impact: -9000 + 25000 = 16000 cents
    $multiResponse->assertStatus(201)
        ->assertJsonPath('data.total_cost_impact_cents', 16000)
        ->assertJsonPath('data.items.0.quantity_before', 20)
        ->assertJsonPath('data.items.0.quantity_delta', -5)
        ->assertJsonPath('data.items.0.quantity_after', 15)
        ->assertJsonPath('data.items.1.quantity_before', 0)
        ->assertJsonPath('data.items.1.quantity_delta', 10)
        ->assertJsonPath('data.items.1.quantity_after', 10);

    // Fetch adjustment by ID
    $fetchResponse = $this->withCookie(config('session.cookie'), $whManagerCookie)
        ->getJson("http://adjalpha.nexuserp.test/api/v1/inventory/adjustments/{$adjId}");
    $fetchResponse->assertStatus(200)
        ->assertJsonPath('data.id', $adjId)
        ->assertJsonPath('data.adjustment_number', $adjNumber);

    // List adjustments with search filter
    $searchResponse = $this->withCookie(config('session.cookie'), $whManagerCookie)
        ->getJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments?search=Cycle');
    $searchResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reason', 'Cycle count corrections');
});

test('negative stock restriction: rejects adjustments driving stock below zero unless setting is enabled', function (): void {
    $ownerCookie = loginAsAdjTenant($this, 'adjalpha.nexuserp.test', 'owner@adjalpha.nexuserp.test');

    // 1. Product has 0 stock. Delta -5 must be rejected by default (allow_negative_stock=false)
    $failResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Damaged stock write-off',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => -5],
            ],
        ]);

    $failResponse->assertStatus(400)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    // 2. Enable allow_negative_stock in tenant settings
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'allow_negative_stock'],
        ['value' => 'true', 'created_at' => now(), 'updated_at' => now()]
    );

    $tenantManager->reset();

    // 3. Now the negative adjustment should succeed
    $successResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Allowed negative write-off',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => -5],
            ],
        ]);

    $successResponse->assertStatus(201)
        ->assertJsonPath('data.items.0.quantity_after', -5);
});

test('domain event: dispatches StockAdjusted with adjustment metadata', function (): void {
    Event::fake([StockAdjusted::class]);

    $ownerCookie = loginAsAdjTenant($this, 'adjalpha.nexuserp.test', 'owner@adjalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Event test reason',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 10, 'unit_cost_cents' => 1500],
            ],
        ]);

    $response->assertStatus(201);
    /** @var string $adjId */
    $adjId = $response->json('data.id');

    Event::assertDispatched(StockAdjusted::class, function (StockAdjusted $event) use ($adjId): bool {
        return $event->stockAdjustmentId === $adjId
            && $event->warehouseId === $this->alphaWarehouseId
            && $event->reason === 'Event test reason'
            && $event->totalCostImpactCents === 15000
            && count($event->items) === 1
            && $event->items[0]['quantity_delta'] === 10;
    });
});

test('multi-tenant isolation: adjustments are completely isolated across tenants', function (): void {
    $alphaCookie = loginAsAdjTenant($this, 'adjalpha.nexuserp.test', 'owner@adjalpha.nexuserp.test');

    // Create adjustment on Alpha
    $alphaAdj = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://adjalpha.nexuserp.test/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->alphaWarehouseId,
            'reason' => 'Alpha confidential stock adjustment',
            'items' => [
                ['product_id' => $this->alphaProduct1Id, 'quantity_delta' => 12],
            ],
        ]);
    $alphaAdj->assertStatus(201);
    /** @var string $alphaAdjId */
    $alphaAdjId = $alphaAdj->json('data.id');

    $this->flushSession();

    // Login as Beta
    $betaCookie = loginAsAdjTenant($this, 'adjbeta.nexuserp.test', 'owner@adjbeta.nexuserp.test');

    // Beta lists adjustments -> empty
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://adjbeta.nexuserp.test/api/v1/inventory/adjustments')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');

    // Beta tries to fetch Alpha adjustment by ID -> 404
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://adjbeta.nexuserp.test/api/v1/inventory/adjustments/{$alphaAdjId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'STOCK_ADJUSTMENT_NOT_FOUND');
});
