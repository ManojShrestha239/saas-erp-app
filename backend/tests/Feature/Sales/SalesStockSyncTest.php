<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Events\SalesInvoicePosted;
use Modules\Sales\Events\SalesInvoicePostedToLedger;
use Modules\Sales\Events\SalesInvoiceVoided;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropStockSyncTestTenantDatabase')) {
    function dropStockSyncTestTenantDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_sales_stocksync') {
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

if (! function_exists('loginAsStockSyncTenant')) {
    function loginAsStockSyncTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
    {
        $testCase->flushSession();

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

if (! function_exists('switchToStockSyncTenant')) {
    function switchToStockSyncTenant(mixed $testCase): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $testCase->tenant->id,
            slug: $testCase->tenant->slug,
            databaseName: $testCase->tenant->database_name,
        ));
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-29 14:00:00');
    app('cache')->flush();

    dropStockSyncTestTenantDatabase('tenant_sales_stocksync');

    Domain::query()->where('host', 'stocksync.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'stocksync-corp')->delete();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--realpath' => false,
        '--force' => true,
    ]);

    $tenantId = (string) Str::ulid();
    $this->tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Stock Sync Corp',
        'slug' => 'stocksync-corp',
        'database_name' => 'tenant_sales_stocksync',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'stocksync.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@stocksync.nexuserp.test',
        ownerName: 'StockSync Owner',
    );

    switchToStockSyncTenant($this);

    $this->owner = DB::connection('tenant')->table('users')->where('email', 'owner@stocksync.nexuserp.test')->first();
    DB::connection('tenant')->table('users')->where('email', 'owner@stocksync.nexuserp.test')->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->customer = Contact::create([
        'code' => 'CUST-000001',
        'type' => ContactType::Customer,
        'name' => 'Apex Technologies Ltd',
        'tax_id' => 'PAN-55443322',
        'email' => 'sales@apextech.test',
        'payment_terms_days' => 30,
        'credit_limit_cents' => 50000000,
        'is_active' => true,
    ]);

    $this->warehousePrimary = Warehouse::create([
        'name' => 'Primary Distribution Hub',
        'code' => 'WH-PRI',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->warehouseSecondary = Warehouse::create([
        'name' => 'Secondary Overflow Warehouse',
        'code' => 'WH-SEC',
        'is_default' => false,
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'name' => 'Industrial Router XR-500',
        'sku' => 'ROUTER-XR500',
        'cost_price_cents' => 15000, // $150.00
        'selling_price_cents' => 25000, // $250.00
        'tax_category' => 'standard',
        'is_active' => true,
    ]);

    app(TenantManager::class)->reset();
});

afterEach(function (): void {
    dropStockSyncTestTenantDatabase('tenant_sales_stocksync');
    Domain::query()->where('host', 'stocksync.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'stocksync-corp')->delete();
});

test('posting an invoice decrements warehouse stock atomically and dispatches ledger event', function (): void {
    Event::fake([SalesInvoicePosted::class, SalesInvoicePostedToLedger::class]);

    switchToStockSyncTenant($this);

    // Seed 50 units initial stock in primary warehouse
    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 50,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $initialLevel = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($initialLevel->quantity_on_hand)->toBe(50);

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Create draft invoice for 12 units
    $createRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 12,
                    'unit_price_cents' => 25000,
                    'tax_rate_percent' => 13,
                ],
            ],
        ]);
    $createRes->assertCreated();
    $invoiceId = (string) $createRes->json('data.id');

    // Post invoice
    $postRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post");

    $postRes->assertOk()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_due_cents', 12 * 25000 + (int) round((12 * 25000) * 0.13));

    // Reconnect to tenant connection to verify database state
    switchToStockSyncTenant($this);

    // Verify stock level decremented: 50 - 12 = 38
    $newLevel = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($newLevel->quantity_on_hand)->toBe(38);

    // Verify StockMovement record created
    $movement = StockMovement::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->where('type', StockMovementType::SalesDelivery)
        ->where('source_type', 'sales_invoice')
        ->where('source_id', $invoiceId)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(-12)
        ->and($movement->unit_cost_cents)->toBe(15000);

    // Verify events dispatched
    Event::assertDispatched(SalesInvoicePosted::class, function (SalesInvoicePosted $event) use ($invoiceId): bool {
        return $event->invoice->id === $invoiceId;
    });

    Event::assertDispatched(SalesInvoicePostedToLedger::class, function (SalesInvoicePostedToLedger $event) use ($invoiceId): bool {
        return $event->invoice->id === $invoiceId
            && $event->cogsCents === (12 * 15000)
            && count($event->lineItems) === 1
            && $event->lineItems[0]['quantity'] === 12;
    });
});

test('insufficient stock prevents invoice posting and rolls back atomically', function (): void {
    Event::fake([SalesInvoicePosted::class, SalesInvoicePostedToLedger::class]);

    switchToStockSyncTenant($this);

    // Seed only 5 units in stock
    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 5,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Create draft invoice requesting 10 units (more than 5 in stock)
    $createRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_price_cents' => 25000,
                    'tax_rate_percent' => 0,
                ],
            ],
        ]);
    $invoiceId = (string) $createRes->json('data.id');

    // Attempt to post: should fail with INSUFFICIENT_STOCK
    $postRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post");

    $postRes->assertStatus(400)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    switchToStockSyncTenant($this);

    // Verify invoice remains in draft status
    $invoice = SalesInvoice::find($invoiceId);
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(SalesInvoiceStatus::Draft);

    // Verify stock remains untouched at 5
    $level = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($level->quantity_on_hand)->toBe(5);

    // Verify NO SalesDelivery movements were recorded
    $movementCount = StockMovement::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->where('type', StockMovementType::SalesDelivery)
        ->count();
    expect($movementCount)->toBe(0);

    Event::assertNotDispatched(SalesInvoicePosted::class);
    Event::assertNotDispatched(SalesInvoicePostedToLedger::class);
});

test('posting allows negative stock when allow_negative_stock setting is enabled', function (): void {
    switchToStockSyncTenant($this);

    // Enable allow_negative_stock setting
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'allow_negative_stock'],
        ['value' => 'true', 'group' => 'inventory', 'updated_at' => now()]
    );

    // Seed stock of 2 units
    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 2,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Create invoice requesting 5 units (exceeding on hand by 3)
    $createRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_price_cents' => 25000,
                    'tax_rate_percent' => 0,
                ],
            ],
        ]);
    $invoiceId = (string) $createRes->json('data.id');

    // Post succeeds
    $postRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post");

    $postRes->assertOk()
        ->assertJsonPath('data.status', 'posted');

    switchToStockSyncTenant($this);

    // Stock on hand is now 2 - 5 = -3
    $level = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($level->quantity_on_hand)->toBe(-3);
});

test('oversell race condition: serialized execution ensures stock does not drop below zero', function (): void {
    switchToStockSyncTenant($this);

    // Seed 15 units in stock
    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 15,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Create Invoice 1 requesting 10 units
    $inv1Res = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price_cents' => 25000, 'tax_rate_percent' => 0],
            ],
        ]);
    $inv1Id = (string) $inv1Res->json('data.id');

    // Create Invoice 2 requesting 10 units
    $inv2Res = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price_cents' => 25000, 'tax_rate_percent' => 0],
            ],
        ]);
    $inv2Id = (string) $inv2Res->json('data.id');

    // Post Invoice 1: succeeds (stock drops from 15 to 5)
    $post1 = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$inv1Id}/post");
    $post1->assertOk();

    // Post Invoice 2: fails because only 5 remain in stock (requested 10)
    $post2 = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$inv2Id}/post");
    $post2->assertStatus(400)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    switchToStockSyncTenant($this);

    // Verify stock remains exactly at 5
    $level = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($level->quantity_on_hand)->toBe(5);

    // Verify Invoice 1 is posted, Invoice 2 is still draft
    expect(SalesInvoice::find($inv1Id)->status)->toBe(SalesInvoiceStatus::Posted);
    expect(SalesInvoice::find($inv2Id)->status)->toBe(SalesInvoiceStatus::Draft);
});

test('voiding a posted invoice restores inventory atomically and dispatches event', function (): void {
    Event::fake([SalesInvoiceVoided::class]);

    switchToStockSyncTenant($this);

    // Seed 20 units
    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 20,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Create & post invoice for 7 units
    $createRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 7, 'unit_price_cents' => 25000, 'tax_rate_percent' => 0],
            ],
        ]);
    $invoiceId = (string) $createRes->json('data.id');

    $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post")
        ->assertOk();

    switchToStockSyncTenant($this);

    // Stock on hand is now 20 - 7 = 13
    $midLevel = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($midLevel->quantity_on_hand)->toBe(13);

    // Void the invoice
    $voidRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/void");

    $voidRes->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.amount_due_cents', 0);

    switchToStockSyncTenant($this);

    // Stock restored back to 20
    $restoredLevel = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect($restoredLevel->quantity_on_hand)->toBe(20);

    // Verify AdjustmentIn movement exists for restoration
    $adjustment = StockMovement::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->where('type', StockMovementType::AdjustmentIn)
        ->where('source_type', 'sales_invoice_void')
        ->where('source_id', $invoiceId)
        ->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment->quantity)->toBe(7);

    // Verify SalesInvoiceVoided event dispatched
    Event::assertDispatched(SalesInvoiceVoided::class, function (SalesInvoiceVoided $event) use ($invoiceId): bool {
        return $event->invoice->id === $invoiceId && $event->stockRestored === true;
    });
});

test('voiding an invoice with payments is rejected with 422', function (): void {
    switchToStockSyncTenant($this);

    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 10,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    $createRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price_cents' => 25000, 'tax_rate_percent' => 0],
            ],
        ]);
    $invoiceId = (string) $createRes->json('data.id');

    $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post")
        ->assertOk();

    switchToStockSyncTenant($this);

    // Record partial payment
    app(SalesInvoiceService::class)->recordPayment($invoiceId, 25000);

    // Attempting to void fails
    $voidRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/void");

    $voidRes->assertStatus(422)
        ->assertJsonPath('code', 'INVOICE_HAS_PAYMENTS');

    switchToStockSyncTenant($this);

    // Invoice status remains unchanged
    expect(SalesInvoice::find($invoiceId)->status)->toBe(SalesInvoiceStatus::PartiallyPaid);
});

test('quotation conversion with post_immediately decrements stock or rolls back if insufficient', function (): void {
    switchToStockSyncTenant($this);

    // Seed 10 units in primary warehouse
    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 10,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Case 1: Convert with sufficient stock & post_immediately = true
    $quote1Res = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/quotations', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price_cents' => 25000],
            ],
        ]);
    $quote1Id = (string) $quote1Res->json('data.id');

    // Accept quote
    $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quote1Id}/accept");

    // Convert with post_immediately: true
    $conv1Res = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quote1Id}/convert", [
            'post_immediately' => true,
        ]);

    $conv1Res->assertCreated()
        ->assertJsonPath('data.status', 'posted');

    switchToStockSyncTenant($this);

    // Stock decremented: 10 - 4 = 6
    expect(StockLevel::where('warehouse_id', $this->warehousePrimary->id)->where('product_id', $this->product->id)->value('quantity_on_hand'))->toBe(6);

    // Case 2: Convert with insufficient stock (quote requests 8, but only 6 remain)
    $quote2Res = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/quotations', [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-29',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 8, 'unit_price_cents' => 25000],
            ],
        ]);
    $quote2Id = (string) $quote2Res->json('data.id');

    $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quote2Id}/accept");

    $conv2Res = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quote2Id}/convert", [
            'post_immediately' => true,
        ]);

    $conv2Res->assertStatus(400)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    switchToStockSyncTenant($this);

    // Stock remains untouched at 6
    expect(StockLevel::where('warehouse_id', $this->warehousePrimary->id)->where('product_id', $this->product->id)->value('quantity_on_hand'))->toBe(6);
});

test('invoice can specify a warehouse and decrement stock specifically from that warehouse', function (): void {
    switchToStockSyncTenant($this);

    $stockService = app(StockService::class);
    // Seed 10 units in primary, 20 units in secondary
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehousePrimary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 10,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehouseSecondary->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 20,
        unitCostCents: 15000,
        sourceType: 'seed',
        sourceId: 'init',
        userId: (string) $this->owner->id,
    ));

    $cookie = loginAsStockSyncTenant($this, 'stocksync.nexuserp.test', 'owner@stocksync.nexuserp.test');

    // Create invoice explicitly targeting the secondary warehouse
    $createRes = $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouseSecondary->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 15, 'unit_price_cents' => 25000, 'tax_rate_percent' => 0],
            ],
        ]);
    $createRes->assertCreated()
        ->assertJsonPath('data.warehouse_id', (string) $this->warehouseSecondary->id);

    $invoiceId = (string) $createRes->json('data.id');

    // Post invoice
    $this->withHeader('Host', 'stocksync.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post")
        ->assertOk();

    switchToStockSyncTenant($this);

    // Secondary warehouse decremented: 20 - 15 = 5
    $secLevel = StockLevel::where('warehouse_id', $this->warehouseSecondary->id)
        ->where('product_id', $this->product->id)
        ->value('quantity_on_hand');
    expect($secLevel)->toBe(5);

    // Primary warehouse completely untouched: 10
    $priLevel = StockLevel::where('warehouse_id', $this->warehousePrimary->id)
        ->where('product_id', $this->product->id)
        ->value('quantity_on_hand');
    expect($priLevel)->toBe(10);
});
