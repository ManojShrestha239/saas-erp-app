<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Events\GoodsReceiptRecorded;
use Modules\Purchasing\Events\PurchaseOrderApproved;
use Modules\Purchasing\Events\SupplierBillPosted;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropPurchasingTestTenantDatabase')) {
    function dropPurchasingTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_purch_alpha', 'tenant_purch_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsPurchTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-28 12:00:00');
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

    dropPurchasingTestTenantDatabase('tenant_purch_alpha');
    dropPurchasingTestTenantDatabase('tenant_purch_beta');

    Domain::query()->whereIn('host', ['purchalpha.nexuserp.test', 'purchbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['purchalpha', 'purchbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Purchasing Alpha Corp',
        'slug' => 'purchalpha',
        'database_name' => 'tenant_purch_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'purchalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@purchalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Purchasing Beta Corp',
        'slug' => 'purchbeta',
        'database_name' => 'tenant_purch_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'purchbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@purchbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $this->tenantManager = app(TenantManager::class);

    // Alpha tenant setup
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->ownerUser = TenantUser::where('email', 'owner@purchalpha.nexuserp.test')->firstOrFail();
    $this->ownerUser->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@purchalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    $this->warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@purchalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->warehouseUser->syncRoles([TenantRole::WarehouseManager->value]);

    $this->salesUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@purchalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->salesUser->syncRoles([TenantRole::SalesRep->value]);

    // Seed Supplier Contact
    $supplier = Contact::create([
        'type' => ContactType::Supplier,
        'code' => 'SUPP-000001',
        'name' => 'Acme Raw Materials Ltd',
        'email' => 'vendor@acme.test',
        'payment_terms_days' => 30,
        'credit_limit_cents' => 1000000,
        'opening_balance_cents' => 0,
        'is_active' => true,
    ]);
    $this->supplierId = $supplier->id;

    // Seed Warehouse
    $warehouse = Warehouse::create([
        'name' => 'Primary Distribution Center',
        'code' => 'WH-PRI',
        'is_default' => true,
        'is_active' => true,
    ]);
    $this->warehouseId = $warehouse->id;

    // Seed Products
    $product1 = Product::create([
        'name' => 'Steel Rods 10mm',
        'sku' => 'STEEL-ROD-10',
        'cost_price_cents' => 5000,
        'selling_price_cents' => 8000,
        'low_stock_threshold' => 10,
        'is_active' => true,
    ]);
    $this->product1Id = $product1->id;

    $product2 = Product::create([
        'name' => 'Copper Wire 50m',
        'sku' => 'COPPER-WIRE-50',
        'cost_price_cents' => 12000,
        'selling_price_cents' => 18000,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);
    $this->product2Id = $product2->id;

    $this->tenantManager->reset();

    // Beta tenant setup
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@purchbeta.nexuserp.test')->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->tenantManager->reset();
});

afterEach(function (): void {
    Carbon::setTestNow();

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropPurchasingTestTenantDatabase('tenant_purch_alpha');
    dropPurchasingTestTenantDatabase('tenant_purch_beta');
});

test('unauthenticated access to purchasing endpoints is rejected with 401', function (): void {
    $this->getJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders')
        ->assertStatus(401);

    $this->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [])
        ->assertStatus(401);

    $this->getJson('http://purchalpha.nexuserp.test/api/v1/purchasing/bills')
        ->assertStatus(401);
});

test('RBAC: SalesRep cannot draft or manage purchasing documents', function (): void {
    $cookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'sales@purchalpha.nexuserp.test');

    $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders')
        ->assertStatus(403);

    $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-28',
            'items' => [
                [
                    'product_id' => $this->product1Id,
                    'quantity_ordered' => 10,
                    'unit_cost_cents' => 5000,
                ],
            ],
        ])
        ->assertStatus(403);
});

test('creates a draft purchase order with sequential document numbering', function (): void {
    $warehouseCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'warehouse@purchalpha.nexuserp.test');
    $ownerCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'owner@purchalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-28',
            'expected_delivery_date' => '2026-11-05',
            'notes' => 'Urgent replenishment',
            'items' => [
                [
                    'product_id' => $this->product1Id,
                    'quantity_ordered' => 20,
                    'unit_cost_cents' => 5000, // 20 * 5000 = 100,000 cents ($1,000.00)
                    'tax_rate_percent' => 10,  // 10,000 cents tax
                ],
                [
                    'product_id' => $this->product2Id,
                    'quantity_ordered' => 5,
                    'unit_cost_cents' => 12000, // 5 * 12000 = 60,000 cents ($600.00)
                    'tax_rate_percent' => 0,
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.order_number', 'PO-2026-000001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal_cents', 160000)
        ->assertJsonPath('data.tax_cents', 10000)
        ->assertJsonPath('data.total_cents', 170000)
        ->assertJsonPath('data.items.0.quantity_ordered', 20)
        ->assertJsonPath('data.items.0.quantity_received', 0)
        ->assertJsonPath('data.items.0.quantity_remaining', 20);

    // Verify next PO generates PO-2026-000002
    $response2 = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-28',
            'items' => [
                [
                    'product_id' => $this->product1Id,
                    'quantity_ordered' => 5,
                    'unit_cost_cents' => 5000,
                ],
            ],
        ]);

    $response2->assertCreated()
        ->assertJsonPath('data.order_number', 'PO-2026-000002');
});

test('approving purchase order transitions status and dispatches event', function (): void {
    Event::fake([PurchaseOrderApproved::class]);

    $warehouseCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'warehouse@purchalpha.nexuserp.test');

    // 1. Warehouse drafts PO
    $createResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-28',
            'items' => [
                [
                    'product_id' => $this->product1Id,
                    'quantity_ordered' => 2,
                    'unit_cost_cents' => 5000,
                ],
            ],
        ]);
    $createResponse->assertCreated();
    /** @var string $poId */
    $poId = $createResponse->json('data.id');

    // 2. Warehouse Manager cannot approve PO (requires PurchasesManage)
    $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/approve")
        ->assertStatus(403);

    // 3. Owner logs in and approves PO
    $ownerCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'owner@purchalpha.nexuserp.test');

    $approveResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/approve");

    $approveResponse->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by_user_id', $this->ownerUser->id);

    Event::assertDispatched(PurchaseOrderApproved::class, function (PurchaseOrderApproved $e) use ($poId): bool {
        return $e->purchaseOrder->id === $poId;
    });
});

test('cannot receive goods on a draft purchase order until approved', function (): void {
    $warehouseCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'warehouse@purchalpha.nexuserp.test');

    // 1. Warehouse drafts PO
    $createResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-28',
            'items' => [
                [
                    'product_id' => $this->product1Id,
                    'quantity_ordered' => 10,
                    'unit_cost_cents' => 5000,
                ],
            ],
        ]);
    $createResponse->assertCreated();
    /** @var string $poId */
    $poId = $createResponse->json('data.id');
    /** @var string $itemId */
    $itemId = $createResponse->json('data.items.0.id');

    $response = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/receipts", [
            'received_date' => '2026-10-28',
            'items' => [
                [
                    'purchase_order_item_id' => $itemId,
                    'quantity_received' => 5,
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_PO_STATUS');
});

test('goods receipt increases stock atomically and updates PO status', function (): void {
    Event::fake([GoodsReceiptRecorded::class]);

    $warehouseCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'warehouse@purchalpha.nexuserp.test');
    $ownerCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'owner@purchalpha.nexuserp.test');

    // Initial stock check
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $stockBefore = StockLevel::where('warehouse_id', $this->warehouseId)
        ->where('product_id', $this->product1Id)
        ->first();
    expect($stockBefore?->quantity_on_hand ?? 0)->toBe(0);
    $this->tenantManager->reset();

    // Create PO and approve
    $createResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/orders', [
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'order_date' => '2026-10-28',
            'items' => [
                [
                    'product_id' => $this->product1Id,
                    'quantity_ordered' => 20,
                    'unit_cost_cents' => 5000,
                ],
            ],
        ]);
    $createResponse->assertCreated();
    /** @var string $poId */
    $poId = $createResponse->json('data.id');
    /** @var string $poItemId */
    $poItemId = $createResponse->json('data.items.0.id');

    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/approve")
        ->assertOk();

    // 1. Partial receipt: receive 8 units
    $receipt1Response = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/receipts", [
            'received_date' => '2026-10-28',
            'notes' => 'Partial shipment from vendor',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity_received' => 8,
                ],
            ],
        ]);

    $receipt1Response->assertCreated()
        ->assertJsonPath('data.receipt_number', 'GRN-2026-000001')
        ->assertJsonPath('data.items.0.quantity_received', 8);

    // Stock level increased to 8
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $stockAfter1 = StockLevel::where('warehouse_id', $this->warehouseId)
        ->where('product_id', $this->product1Id)
        ->firstOrFail();
    expect($stockAfter1->quantity_on_hand)->toBe(8);

    $po = PurchaseOrder::findOrFail($poId);
    expect($po->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
    $this->tenantManager->reset();

    // 2. Reject excess receipt (> remaining 12)
    $excessResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/receipts", [
            'received_date' => '2026-10-29',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity_received' => 15, // Only 12 remaining!
                ],
            ],
        ]);

    $excessResponse->assertStatus(422)
        ->assertJsonPath('code', 'EXCESS_RECEIPT_QUANTITY');

    // 3. Receive remaining 12 units
    $receipt2Response = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/orders/{$poId}/receipts", [
            'received_date' => '2026-10-29',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity_received' => 12,
                ],
            ],
        ]);

    $receipt2Response->assertCreated()
        ->assertJsonPath('data.receipt_number', 'GRN-2026-000002');

    // Total stock is now 20
    $this->tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $stockAfter2 = StockLevel::where('warehouse_id', $this->warehouseId)
        ->where('product_id', $this->product1Id)
        ->firstOrFail();
    expect($stockAfter2->quantity_on_hand)->toBe(20);

    $po->refresh();
    expect($po->status)->toBe(PurchaseOrderStatus::Received);
    $this->tenantManager->reset();

    Event::assertDispatched(GoodsReceiptRecorded::class, 2);
});

test('supplier bill creation, posting, immutability and AP balance calculation', function (): void {
    Event::fake([SupplierBillPosted::class]);

    $accountantCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'accountant@purchalpha.nexuserp.test');

    // 1. Accountant creates draft bill
    $response = $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/bills', [
            'supplier_id' => $this->supplierId,
            'supplier_invoice_number' => 'INV-SUPP-9988',
            'bill_date' => '2026-10-28',
            'due_date' => '2026-11-27',
            'notes' => 'Raw materials purchase invoice',
            'items' => [
                [
                    'description' => 'Steel Rods Batch 1',
                    'quantity' => 10,
                    'unit_cost_cents' => 5000, // 50,000 cents
                    'tax_rate_percent' => 10,  // 5,000 cents
                    'product_id' => $this->product1Id,
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.bill_number', 'BILL-2026-000001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal_cents', 50000)
        ->assertJsonPath('data.tax_cents', 5000)
        ->assertJsonPath('data.total_cents', 55000)
        ->assertJsonPath('data.amount_due_cents', 0); // Draft bill does not yet impact AP due

    /** @var string $billId */
    $billId = $response->json('data.id');

    // AP balance before posting is 0
    $balanceBefore = $this->withCookie(config('session.cookie'), $accountantCookie)
        ->getJson("http://purchalpha.nexuserp.test/api/v1/purchasing/suppliers/{$this->supplierId}/payable-balance");

    $balanceBefore->assertOk()
        ->assertJsonPath('data.outstanding_payable_cents', 0);

    // 2. Post the bill
    $postResponse = $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/bills/{$billId}/post");

    $postResponse->assertOk()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_due_cents', 55000)
        ->assertJsonPath('data.amount_paid_cents', 0);

    // 3. AP balance after posting is 55,000 cents ($550.00)
    $balanceAfter = $this->withCookie(config('session.cookie'), $accountantCookie)
        ->getJson("http://purchalpha.nexuserp.test/api/v1/purchasing/suppliers/{$this->supplierId}/payable-balance");

    $balanceAfter->assertOk()
        ->assertJsonPath('data.outstanding_payable_cents', 55000);

    // 4. Immutability: Attempting to re-post a posted bill fails
    $repostResponse = $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson("http://purchalpha.nexuserp.test/api/v1/purchasing/bills/{$billId}/post");

    $repostResponse->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_BILL_STATUS');

    Event::assertDispatched(SupplierBillPosted::class, function (SupplierBillPosted $e) use ($billId): bool {
        return $e->bill->id === $billId && $e->bill->amount_due_cents === 55000;
    });
});

test('multi-tenant isolation: purchasing documents on Alpha cannot be accessed from Beta', function (): void {
    // 1. Alpha creates bill
    $alphaCookie = loginAsPurchTenant($this, 'purchalpha.nexuserp.test', 'accountant@purchalpha.nexuserp.test');

    $createResponse = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://purchalpha.nexuserp.test/api/v1/purchasing/bills', [
            'supplier_id' => $this->supplierId,
            'supplier_invoice_number' => 'INV-SUPP-ALPHA-1',
            'bill_date' => '2026-10-28',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'description' => 'Alpha goods',
                    'quantity' => 1,
                    'unit_cost_cents' => 30000,
                ],
            ],
        ]);
    $createResponse->assertCreated();
    /** @var string $alphaBillId */
    $alphaBillId = $createResponse->json('data.id');

    // 2. Beta logs in
    $betaCookie = loginAsPurchTenant($this, 'purchbeta.nexuserp.test', 'owner@purchbeta.nexuserp.test');

    // Beta requests Alpha's bill ID -> 404 NOT_FOUND
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://purchbeta.nexuserp.test/api/v1/purchasing/bills/{$alphaBillId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'BILL_NOT_FOUND');

    // 3. Beta's bills list is empty
    $listResponse = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://purchbeta.nexuserp.test/api/v1/purchasing/bills');

    $listResponse->assertOk()
        ->assertJsonPath('meta.total', 0);
});
