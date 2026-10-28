<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Events\LowStockDetected;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropLowStockTestTenantDatabase')) {
    function dropLowStockTestTenantDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_low_stock_test') {
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
        // Redis store might not be available in local testing
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropLowStockTestTenantDatabase('tenant_low_stock_test');
    Domain::query()->where('host', 'lowstock.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'lowstock')->delete();

    $tenantId = (string) Str::ulid();
    $tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Low Stock Test Corp',
        'slug' => 'lowstock',
        'database_name' => 'tenant_low_stock_test',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'lowstock.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@lowstock.nexuserp.test',
        ownerName: 'Low Stock Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    TenantUser::where('email', 'owner@lowstock.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Warehouse Manager user
    $whUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'LowStock WH Mgr',
        'email' => 'whmgr@lowstock.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $whUser->assignRole(TenantRole::WarehouseManager->value);

    // Create default warehouse
    Warehouse::create([
        'id' => (string) Str::ulid(),
        'name' => 'Main Warehouse',
        'code' => 'MAIN-01',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->tenant = $tenant;
    $this->whUser = $whUser;
    $this->ownerUser = TenantUser::where('email', 'owner@lowstock.nexuserp.test')->firstOrFail();
});

afterEach(function (): void {
    dropLowStockTestTenantDatabase('tenant_low_stock_test');
});

test('recording stock movement dispatches LowStockDetected event when quantity falls to or below threshold', function (): void {
    Event::fake([LowStockDetected::class]);

    $warehouse = Warehouse::firstOrFail();
    $product = Product::create([
        'name' => 'Critical Sensor',
        'sku' => 'SENSOR-CRIT-01',
        'cost_price_cents' => 1000,
        'selling_price_cents' => 2000,
        'low_stock_threshold' => 10,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // Initial stock receipt: 25 units
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 25,
        userId: $this->whUser->id,
    ));

    // 25 > 10: Event should NOT have fired yet
    Event::assertNotDispatched(LowStockDetected::class);

    // Issue 18 units: 25 - 18 = 7 units (<= 10)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::SalesDelivery,
        quantity: 18,
        userId: $this->whUser->id,
    ));

    // Event should fire with correct payload
    Event::assertDispatched(LowStockDetected::class, function (LowStockDetected $event) use ($product, $warehouse): bool {
        return $event->productId === $product->id
            && $event->warehouseId === $warehouse->id
            && $event->quantityOnHand === 7
            && $event->lowStockThreshold === 10;
    });
});

test('recording stock movement does not dispatch LowStockDetected when product threshold is 0', function (): void {
    Event::fake([LowStockDetected::class]);

    $warehouse = Warehouse::firstOrFail();
    $product = Product::create([
        'name' => 'Regular Screw',
        'sku' => 'SCREW-01',
        'cost_price_cents' => 50,
        'selling_price_cents' => 100,
        'low_stock_threshold' => 0, // No low stock monitoring
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 5,
        userId: $this->whUser->id,
    ));

    Event::assertNotDispatched(LowStockDetected::class);
});

test('GET /api/v1/inventory/low-stock returns items below threshold with deficit and details', function (): void {
    $warehouse = Warehouse::firstOrFail();

    $productLow = Product::create([
        'name' => 'Low Stock Cable',
        'sku' => 'CABLE-01',
        'cost_price_cents' => 500,
        'selling_price_cents' => 1000,
        'low_stock_threshold' => 15,
        'is_active' => true,
    ]);

    $productHigh = Product::create([
        'name' => 'Ample Stock Cable',
        'sku' => 'CABLE-02',
        'cost_price_cents' => 500,
        'selling_price_cents' => 1000,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // ProductLow has 4 units (threshold 15) -> deficit 11
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $productLow->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 4,
        userId: $this->whUser->id,
    ));

    // ProductHigh has 20 units (threshold 5) -> not low stock
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $productHigh->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 20,
        userId: $this->whUser->id,
    ));

    $response = $this->actingAs($this->whUser, 'web')
        ->withHeaders([
            'Host' => 'lowstock.nexuserp.test',
            'Accept' => 'application/json',
        ])
        ->getJson('/api/v1/inventory/low-stock');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sku', 'CABLE-01')
        ->assertJsonPath('data.0.quantity_on_hand', 4)
        ->assertJsonPath('data.0.low_stock_threshold', 15)
        ->assertJsonPath('data.0.deficit', 11)
        ->assertJsonPath('data.0.is_out_of_stock', false);
});

test('inactive products are excluded from low-stock queries', function (): void {
    $warehouse = Warehouse::firstOrFail();

    $inactiveProduct = Product::create([
        'name' => 'Deprecated Valve',
        'sku' => 'VALVE-DEP',
        'cost_price_cents' => 1000,
        'selling_price_cents' => 2000,
        'low_stock_threshold' => 50,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $inactiveProduct->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 2,
        userId: $this->ownerUser->id,
    ));

    $inactiveProduct->update(['is_active' => false]);

    $response = $this->actingAs($this->ownerUser, 'web')
        ->withHeaders([
            'Host' => 'lowstock.nexuserp.test',
            'Accept' => 'application/json',
        ])
        ->getJson('/api/v1/inventory/low-stock');

    $response->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('GET /api/v1/stream connects successfully and streams connected event with tenant id', function (): void {
    $response = $this->actingAs($this->whUser, 'web')
        ->withHeaders([
            'Host' => 'lowstock.nexuserp.test',
            'Accept' => 'text/event-stream',
        ])
        ->get('/api/v1/stream');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    expect($response->streamedContent())->toContain('event: connected');
    expect($response->streamedContent())->toContain($this->tenant->id);
});

test('unauthenticated access to /api/v1/stream and /api/v1/inventory/low-stock is rejected', function (): void {
    $streamRes = $this->withHeaders([
        'Host' => 'lowstock.nexuserp.test',
        'Accept' => 'text/event-stream',
    ])->get('/api/v1/stream');

    // Unauthenticated on web guard redirects to /login
    expect($streamRes->getStatusCode())->toBeIn([302, 401]);

    $lowStockRes = $this->withHeaders([
        'Host' => 'lowstock.nexuserp.test',
        'Accept' => 'application/json',
    ])->getJson('/api/v1/inventory/low-stock');

    expect($lowStockRes->getStatusCode())->toBeIn([302, 401]);
});
