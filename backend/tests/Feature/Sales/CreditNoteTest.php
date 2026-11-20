<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Sales\DTOs\CreateCreditNoteData;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\DTOs\CreditNoteItemData;
use Modules\Sales\DTOs\SalesInvoiceItemData;
use Modules\Sales\Enums\CreditNoteStatus;
use Modules\Sales\Events\CreditNotePosted;
use Modules\Sales\Models\CreditNote;
use Modules\Sales\Services\CreditNoteService;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropCreditNoteTestTenantDb')) {
    function dropCreditNoteTestTenantDb(string $dbName): void
    {
        if ($dbName !== 'tenant_credit_note_alpha') {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-02 10:00:00');
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

    dropCreditNoteTestTenantDb('tenant_credit_note_alpha');

    Domain::query()->where('host', 'cn-alpha.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'credit-note-alpha')->delete();

    $tenantId = (string) Str::ulid();
    $this->tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Credit Note Tenant Alpha',
        'slug' => 'credit-note-alpha',
        'status' => 'provisioning',
        'plan' => 'enterprise',
        'database_name' => 'tenant_credit_note_alpha',
    ]);

    $this->domain = Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'cn-alpha.nexuserp.test',
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@cn-alpha.nexuserp.test',
        ownerName: 'CN Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->tenant->id,
        slug: $this->tenant->slug,
        databaseName: $this->tenant->database_name,
    ));

    TenantUser::where('email', 'owner@cn-alpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->owner = TenantUser::where('email', 'owner@cn-alpha.nexuserp.test')->firstOrFail();
    $this->owner->syncRoles([TenantRole::Owner->value]);

    $this->warehouse = Warehouse::create([
        'code' => 'WH-CN-01',
        'name' => 'CN Main Warehouse',
        'is_default' => true,
        'status' => 'active',
    ]);

    $this->customer = Contact::create([
        'code' => 'CUST-CN-001',
        'name' => 'CN Acme Corp',
        'type' => ContactType::Customer,
        'currency' => 'USD',
        'opening_balance_cents' => 0,
        'status' => 'active',
    ]);

    $this->product = Product::create([
        'sku' => 'PROD-CN-001',
        'name' => 'CN Widget Alpha',
        'cost_price_cents' => 2000,
        'selling_price_cents' => 5000,
        'tax_rate_percent' => 13,
        'status' => 'active',
    ]);

    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'quantity_on_hand' => 0,
        'quantity_reserved' => 0,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    dropCreditNoteTestTenantDb('tenant_credit_note_alpha');
});

test('draft credit note creates line items and calculates totals in integer cents', function (): void {
    $service = app(CreditNoteService::class);

    $data = new CreateCreditNoteData(
        customerId: (string) $this->customer->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        restock: true,
        reason: 'customer_return',
        items: [
            new CreditNoteItemData(
                productId: (string) $this->product->id,
                quantity: 2,
                unitPriceCents: 5000,
                taxRatePercent: 13,
                cogsUnitCostCents: 2000,
            ),
        ]
    );

    $creditNote = $service->create($data, (string) $this->owner->id);

    expect($creditNote)->toBeInstanceOf(CreditNote::class)
        ->and($creditNote->credit_note_number)->toStartWith('CN-2026-')
        ->and($creditNote->status)->toBe(CreditNoteStatus::Draft)
        ->and($creditNote->subtotal_cents)->toBe(10000)
        ->and($creditNote->tax_cents)->toBe(1300)
        ->and($creditNote->total_cents)->toBe(11300)
        ->and($creditNote->items)->toHaveCount(1)
        ->and($creditNote->items->first()->total_cents)->toBe(11300);
});

test('posting a credit note restocks inventory, reduces linked invoice amount_due_cents, and posts balanced GL journal', function (): void {
    Event::fake([CreditNotePosted::class]);

    $stockService = app(StockService::class);
    $invoiceService = app(SalesInvoiceService::class);
    $creditNoteService = app(CreditNoteService::class);

    // Initial stock: 10 units @ $20.00 (2000 cents)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehouse->id,
        productId: (string) $this->product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 2000,
        sourceType: 'test',
        sourceId: 'init-stock',
    ));

    // Post an invoice for 5 units @ $50.00 + 13% tax
    $invoice = $invoiceService->create(new CreateSalesInvoiceData(
        customerId: (string) $this->customer->id,
        issueDate: '2026-11-01',
        dueDate: '2026-11-15',
        warehouseId: (string) $this->warehouse->id,
        items: [
            new SalesInvoiceItemData(
                productId: (string) $this->product->id,
                quantity: 5,
                unitPriceCents: 5000,
                taxRatePercent: 13,
            ),
        ]
    ), (string) $this->owner->id);

    $postedInvoice = $invoiceService->post((string) $invoice->id, (string) $this->owner->id);
    expect($postedInvoice->amount_due_cents)->toBe(28250); // 25000 + 3250 tax

    // Check stock after sales delivery: 10 - 5 = 5 units
    $stockLevel = StockLevel::where('warehouse_id', $this->warehouse->id)->where('product_id', $this->product->id)->firstOrFail();
    expect($stockLevel->quantity_on_hand)->toBe(5);

    // Create and post Credit Note for 2 returned units linked to invoice
    $creditNote = $creditNoteService->create(new CreateCreditNoteData(
        customerId: (string) $this->customer->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        salesInvoiceId: (string) $postedInvoice->id,
        restock: true,
        reason: 'damaged_item_return',
        items: [
            new CreditNoteItemData(
                productId: (string) $this->product->id,
                quantity: 2,
                unitPriceCents: 5000,
                taxRatePercent: 13,
                cogsUnitCostCents: 2000,
            ),
        ]
    ), (string) $this->owner->id);

    $postedCn = $creditNoteService->post((string) $creditNote->id, (string) $this->owner->id);

    expect($postedCn->status)->toBe(CreditNoteStatus::Posted)
        ->and($postedCn->total_cents)->toBe(11300); // 10000 subtotal + 1300 tax

    // Verify stock returned: 5 + 2 = 7 units
    $stockLevel->refresh();
    expect($stockLevel->quantity_on_hand)->toBe(7);

    // Verify invoice amount_due_cents reduced: 28250 - 11300 = 16950
    $postedInvoice->refresh();
    expect($postedInvoice->amount_due_cents)->toBe(16950);

    // Verify General Ledger Journal Entry
    $journalEntry = JournalEntry::where('source_type', 'credit_note')
        ->where('source_id', $postedCn->id)
        ->firstOrFail();

    $lines = $journalEntry->lines()->with('account')->get();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted)
        ->and($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'));
    $salesRevenueDebit = $lines->firstWhere('account.code', '4110');
    $vatDebit = $lines->firstWhere('account.code', '2120');
    $arCredit = $lines->firstWhere('account.code', '1130');
    $inventoryDebit = $lines->firstWhere('account.code', '1150');
    $cogsCredit = $lines->firstWhere('account.code', '5100');

    expect($salesRevenueDebit)->not->toBeNull()
        ->and($salesRevenueDebit->debit_cents)->toBe(10000)
        ->and($vatDebit)->not->toBeNull()
        ->and($vatDebit->debit_cents)->toBe(1300)
        ->and($arCredit)->not->toBeNull()
        ->and($arCredit->credit_cents)->toBe(11300)
        ->and($inventoryDebit)->not->toBeNull()
        ->and($inventoryDebit->debit_cents)->toBe(4000) // 2 units * 2000 cents
        ->and($cogsCredit)->not->toBeNull()
        ->and($cogsCredit->credit_cents)->toBe(4000);

    Event::assertDispatched(CreditNotePosted::class);
});

test('credit notes are immutable once posted and cannot be modified or deleted', function (): void {
    $service = app(CreditNoteService::class);

    $cn = $service->create(new CreateCreditNoteData(
        customerId: (string) $this->customer->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        items: [
            new CreditNoteItemData(
                productId: (string) $this->product->id,
                quantity: 1,
                unitPriceCents: 5000,
            ),
        ]
    ), (string) $this->owner->id);

    $postedCn = $service->post((string) $cn->id, (string) $this->owner->id);

    expect(fn () => $service->delete((string) $postedCn->id))
        ->toThrow(ApiException::class, 'immutable');

    expect(fn () => $service->update((string) $postedCn->id, new CreateCreditNoteData(
        customerId: (string) $this->customer->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        items: [
            new CreditNoteItemData(
                productId: (string) $this->product->id,
                quantity: 2,
                unitPriceCents: 5000,
            ),
        ]
    )))->toThrow(ApiException::class, 'immutable');
});

test('HTTP API endpoints allow listing, creating, and posting credit notes', function (): void {
    $response = $this->actingAs($this->owner, 'web')
        ->postJson('http://cn-alpha.nexuserp.test/api/v1/sales/credit-notes', [
            'customer_id' => (string) $this->customer->id,
            'warehouse_id' => (string) $this->warehouse->id,
            'issue_date' => '2026-11-02',
            'restock' => true,
            'reason' => 'customer_return',
            'items' => [
                [
                    'product_id' => (string) $this->product->id,
                    'quantity' => 2,
                    'unit_price_cents' => 5000,
                    'tax_rate_percent' => 13,
                    'cogs_unit_cost_cents' => 2000,
                ],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.total_cents', 11300)
        ->assertJsonPath('data.status', 'draft');

    $cnId = (string) $response->json('data.id');

    // Post via API
    $postResponse = $this->actingAs($this->owner, 'web')
        ->postJson("http://cn-alpha.nexuserp.test/api/v1/sales/credit-notes/{$cnId}/post");

    $postResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    // List via API
    $listResponse = $this->actingAs($this->owner, 'web')
        ->getJson('http://cn-alpha.nexuserp.test/api/v1/sales/credit-notes');

    $listResponse->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $cnId);
});
