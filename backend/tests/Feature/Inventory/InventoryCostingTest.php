<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\CreateStockAdjustmentData;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\DTOs\StockAdjustmentItemData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Enums\TaxCategory;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\InventoryCostingService;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Services\StockService;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\DTOs\SalesInvoiceItemData;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('loginAsCostingTenant')) {
    function loginAsCostingTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
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
}

if (! function_exists('dropInventoryCostingTestTenantDb')) {
    function dropInventoryCostingTestTenantDb(string $dbName): void
    {
        if ($dbName !== 'tenant_costing_alpha') {
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
            $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.$pdo->quote($dbName).' AND pid <> pg_backend_pid()');
            $quotedName = '"'.str_replace('"', '""', $dbName).'"';
            $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
        } catch (Throwable) {
        }
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 10:00:00');
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

    dropInventoryCostingTestTenantDb('tenant_costing_alpha');

    Domain::query()->where('host', 'costingalpha.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'costingalpha')->delete();

    $tenantId = (string) Str::ulid();
    $tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Costing Alpha Corp',
        'slug' => 'costingalpha',
        'database_name' => 'tenant_costing_alpha',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'costingalpha.nexuserp.test',
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@costingalpha.nexuserp.test',
        ownerName: 'Costing Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    TenantUser::where('email', 'owner@costingalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->owner = TenantUser::where('email', 'owner@costingalpha.nexuserp.test')->firstOrFail();
    $this->owner->syncRoles([TenantRole::Owner->value]);

    $this->tenant = $tenant;
});

afterEach(function (): void {
    Carbon::setTestNow();
    dropInventoryCostingTestTenantDb('tenant_costing_alpha');
});

test('calculateMovingWeightedAverage handles zero initial stock and fractional cents correctly', function (): void {
    /** @var InventoryCostingService $costingService */
    $costingService = app(InventoryCostingService::class);

    // Initial stock 0: incoming cost sets the average
    expect($costingService->calculateMovingWeightedAverage(
        currentQuantity: 0,
        currentUnitCostCents: 1000,
        incomingQuantity: 10,
        incomingUnitCostCents: 1500,
    ))->toBe(1500);

    // Existing stock 10 @ $10.00 + new 10 @ $20.00 => 20 @ $15.00
    expect($costingService->calculateMovingWeightedAverage(
        currentQuantity: 10,
        currentUnitCostCents: 1000,
        incomingQuantity: 10,
        incomingUnitCostCents: 2000,
    ))->toBe(1500);

    // Fractional cents rounding: (10 * 100 + 3 * 150) / 13 = 1450 / 13 = 111.538... => 112 cents
    expect($costingService->calculateMovingWeightedAverage(
        currentQuantity: 10,
        currentUnitCostCents: 100,
        incomingQuantity: 3,
        incomingUnitCostCents: 150,
    ))->toBe(112);

    // Incoming quantity 0 => remains current cost
    expect($costingService->calculateMovingWeightedAverage(
        currentQuantity: 10,
        currentUnitCostCents: 1200,
        incomingQuantity: 0,
        incomingUnitCostCents: 2000,
    ))->toBe(1200);
});

test('purchase receipts continuously update moving weighted average cost and sales deliveries preserve it', function (): void {
    $warehouse = Warehouse::create([
        'name' => 'Main Distribution Center',
        'code' => 'MDC-01',
        'is_active' => true,
        'is_default' => true,
    ]);

    $product = Product::create([
        'sku' => 'WIDGET-AVCO',
        'name' => 'Weighted Average Widget',
        'cost_price_cents' => 1000, // baseline $10.00
        'selling_price_cents' => 2500,
        'tax_category' => TaxCategory::Standard,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // 1. First Purchase Receipt: 10 units @ 1,000 cents ($10.00)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 1000,
        sourceType: 'goods_receipt',
        sourceId: 'GRN-001',
        userId: $this->owner->id,
    ));

    $product->refresh();
    expect($product->cost_price_cents)->toBe(1000);
    expect($stockService->getProductTotalStock($product->id))->toBe(10);

    // 2. Second Purchase Receipt at a higher price: 10 units @ 2,000 cents ($20.00)
    // New total: 20 units, Total value: 10,000 + 20,000 = 30,000 cents => Average: 1,500 cents ($15.00)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 2000,
        sourceType: 'goods_receipt',
        sourceId: 'GRN-002',
        userId: $this->owner->id,
    ));

    $product->refresh();
    expect($product->cost_price_cents)->toBe(1500);
    expect($stockService->getProductTotalStock($product->id))->toBe(20);

    // 3. Sales Delivery of 5 units does NOT change the moving average unit cost
    $deliveryMovement = $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::SalesDelivery,
        quantity: 5,
        unitCostCents: 0, // Should resolve to current moving average cost
        sourceType: 'sales_invoice',
        sourceId: 'INV-001',
        userId: $this->owner->id,
    ));

    $product->refresh();
    expect($deliveryMovement->unit_cost_cents)->toBe(1500);
    expect($product->cost_price_cents)->toBe(1500);
    expect($stockService->getProductTotalStock($product->id))->toBe(15);
});

test('sales invoice posting calculates COGS using the current moving weighted average cost', function (): void {
    $warehouse = Warehouse::create([
        'name' => 'Main Distribution Center',
        'code' => 'MDC-02',
        'is_active' => true,
        'is_default' => true,
    ]);

    $customer = Contact::create([
        'name' => 'Acme Retail Corp',
        'type' => 'customer',
        'code' => 'CUST-0001',
        'email' => 'acme@example.com',
        'is_active' => true,
    ]);

    $product = Product::create([
        'sku' => 'LAPTOP-PRO',
        'name' => 'Laptop Pro 16',
        'cost_price_cents' => 100000, // baseline $1,000.00
        'selling_price_cents' => 180000, // $1,800.00
        'tax_category' => TaxCategory::Standard,
        'low_stock_threshold' => 2,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // Receive 2 units @ $1,000.00 (100,000 cents) = $2,000.00
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 2,
        unitCostCents: 100000,
        sourceType: 'goods_receipt',
        sourceId: 'GRN-010',
        userId: $this->owner->id,
    ));

    // Receive 2 units @ $1,400.00 (140,000 cents) = $2,800.00
    // Total: 4 units, Total value: 480,000 cents => Moving Average: 120,000 cents ($1,200.00)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 2,
        unitCostCents: 140000,
        sourceType: 'goods_receipt',
        sourceId: 'GRN-011',
        userId: $this->owner->id,
    ));

    $product->refresh();
    expect($product->cost_price_cents)->toBe(120000);

    /** @var SalesInvoiceService $invoiceService */
    $invoiceService = app(SalesInvoiceService::class);

    $invoice = $invoiceService->create(new CreateSalesInvoiceData(
        customerId: $customer->id,
        issueDate: '2026-11-01',
        dueDate: '2026-11-15',
        currency: 'USD',
        notes: 'Sales order fulfillment',
        items: [
            new SalesInvoiceItemData(
                productId: $product->id,
                description: 'Laptop Pro 16 item',
                quantity: 2,
                unitPriceCents: 180000,
                taxRatePercent: 13,
                taxCategory: 'standard',
            ),
        ],
        warehouseId: $warehouse->id,
    ));

    $postedInvoice = $invoiceService->post($invoice->id, $this->owner->id);

    // Stock decremented to 2
    expect($stockService->getProductTotalStock($product->id))->toBe(2);

    // COGS should be 2 units * 120,000 cents = 240,000 cents
    $journalEntry = JournalEntry::with('lines.account')
        ->where('source_type', 'sales_invoice')
        ->where('source_id', $postedInvoice->id)
        ->firstOrFail();

    $lines = $journalEntry->lines()->with('account')->get();
    $cogsLine = $lines->firstWhere('account.code', '5100');
    $inventoryReliefLine = $lines->firstWhere('account.code', '1150');

    expect($cogsLine)->not->toBeNull();
    expect($cogsLine->debit_cents)->toBe(240000);
    expect($inventoryReliefLine)->not->toBeNull();
    expect($inventoryReliefLine->credit_cents)->toBe(240000);
});

test('stock adjustment in with custom unit cost updates moving weighted average cost', function (): void {
    $warehouse = Warehouse::create([
        'name' => 'Main Distribution Center',
        'code' => 'MDC-03',
        'is_active' => true,
        'is_default' => true,
    ]);

    $product = Product::create([
        'sku' => 'PHONE-X',
        'name' => 'Smartphone X',
        'cost_price_cents' => 50000, // baseline $500.00
        'selling_price_cents' => 80000,
        'tax_category' => TaxCategory::Standard,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // Start with 10 units @ 50,000 cents ($500.00) = 500,000 cents
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 50000,
        sourceType: 'goods_receipt',
        sourceId: 'GRN-020',
        userId: $this->owner->id,
    ));

    /** @var StockAdjustmentService $adjustmentService */
    $adjustmentService = app(StockAdjustmentService::class);

    // Adjust in 5 units @ 65,000 cents ($650.00) = 325,000 cents
    // Total value: 500,000 + 325,000 = 825,000 cents across 15 units => 55,000 cents ($550.00)
    $adjustmentService->create(new CreateStockAdjustmentData(
        warehouseId: $warehouse->id,
        reason: 'Surplus audit count with current supplier replacement pricing',
        userId: $this->owner->id,
        items: [
            new StockAdjustmentItemData(
                productId: $product->id,
                quantityDelta: 5,
                unitCostCents: 65000,
            ),
        ],
    ));

    $product->refresh();
    expect($product->cost_price_cents)->toBe(55000);
    expect($stockService->getProductTotalStock($product->id))->toBe(15);
});

test('valuation and cost history API endpoints return accurate metrics and audit trail', function (): void {
    $warehouse = Warehouse::create([
        'name' => 'Main Distribution Center',
        'code' => 'MDC-04',
        'is_active' => true,
        'is_default' => true,
    ]);

    $product = Product::create([
        'sku' => 'KEYBOARD-MECH',
        'name' => 'Mechanical Keyboard RGB',
        'cost_price_cents' => 6000,
        'selling_price_cents' => 12000,
        'tax_category' => TaxCategory::Standard,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // Inbound receipt: 20 units @ 6,000 cents
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 20,
        unitCostCents: 6000,
        sourceType: 'goods_receipt',
        sourceId: 'GRN-030',
        userId: $this->owner->id,
    ));

    $cookie = loginAsCostingTenant($this, 'costingalpha.nexuserp.test', 'owner@costingalpha.nexuserp.test');

    // Authenticated API request to GET /api/v1/inventory/valuation
    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://costingalpha.nexuserp.test/api/v1/inventory/valuation');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.total_units_on_hand', 20)
        ->assertJsonPath('data.summary.total_valuation_cents', 120000);

    // Authenticated API request to GET /api/v1/inventory/valuation/{id}/history
    $historyResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson("http://costingalpha.nexuserp.test/api/v1/inventory/valuation/{$product->id}/history");

    $historyResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.product.sku', 'KEYBOARD-MECH')
        ->assertJsonPath('data.product.current_moving_average_cost_cents', 6000)
        ->assertJsonPath('data.history.0.quantity_delta', 20)
        ->assertJsonPath('data.history.0.movement_unit_cost_cents', 6000)
        ->assertJsonPath('data.history.0.resulting_average_cost_cents', 6000);
});
