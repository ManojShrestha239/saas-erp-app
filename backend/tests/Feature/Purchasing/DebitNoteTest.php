<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

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
use Modules\Purchasing\DTOs\CreateDebitNoteData;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\DebitNoteItemData;
use Modules\Purchasing\DTOs\SupplierBillItemData;
use Modules\Purchasing\Enums\DebitNoteStatus;
use Modules\Purchasing\Events\DebitNotePosted;
use Modules\Purchasing\Models\DebitNote;
use Modules\Purchasing\Services\DebitNoteService;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropDebitNoteTestTenantDb')) {
    function dropDebitNoteTestTenantDb(string $dbName): void
    {
        if ($dbName !== 'tenant_debit_note_alpha') {
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

    dropDebitNoteTestTenantDb('tenant_debit_note_alpha');

    Domain::query()->where('host', 'dn-alpha.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'debit-note-alpha')->delete();

    $tenantId = (string) Str::ulid();
    $this->tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Debit Note Tenant Alpha',
        'slug' => 'debit-note-alpha',
        'status' => 'provisioning',
        'plan' => 'enterprise',
        'database_name' => 'tenant_debit_note_alpha',
    ]);

    $this->domain = Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'dn-alpha.nexuserp.test',
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@dn-alpha.nexuserp.test',
        ownerName: 'DN Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->tenant->id,
        slug: $this->tenant->slug,
        databaseName: $this->tenant->database_name,
    ));

    TenantUser::where('email', 'owner@dn-alpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->owner = TenantUser::where('email', 'owner@dn-alpha.nexuserp.test')->firstOrFail();
    $this->owner->syncRoles([TenantRole::Owner->value]);

    $this->warehouse = Warehouse::create([
        'code' => 'WH-DN-01',
        'name' => 'DN Main Warehouse',
        'is_default' => true,
        'status' => 'active',
    ]);

    $this->supplier = Contact::create([
        'code' => 'SUPP-DN-001',
        'name' => 'DN Global Supplies Inc',
        'type' => ContactType::Supplier,
        'currency' => 'USD',
        'opening_balance_cents' => 0,
        'status' => 'active',
    ]);

    $this->product = Product::create([
        'sku' => 'PROD-DN-001',
        'name' => 'DN Hardware Alpha',
        'cost_price_cents' => 3000,
        'selling_price_cents' => 6000,
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
    dropDebitNoteTestTenantDb('tenant_debit_note_alpha');
});

test('draft debit note creates line items and calculates totals in integer cents', function (): void {
    $service = app(DebitNoteService::class);

    $data = new CreateDebitNoteData(
        supplierId: (string) $this->supplier->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        returnStock: true,
        reason: 'supplier_return',
        items: [
            new DebitNoteItemData(
                productId: (string) $this->product->id,
                quantity: 3,
                unitCostCents: 3000,
                taxRatePercent: 13,
            ),
        ]
    );

    $debitNote = $service->create($data, (string) $this->owner->id);

    expect($debitNote)->toBeInstanceOf(DebitNote::class)
        ->and($debitNote->debit_note_number)->toStartWith('DN-2026-')
        ->and($debitNote->status)->toBe(DebitNoteStatus::Draft)
        ->and($debitNote->subtotal_cents)->toBe(9000)
        ->and($debitNote->tax_cents)->toBe(1170)
        ->and($debitNote->total_cents)->toBe(10170)
        ->and($debitNote->items)->toHaveCount(1)
        ->and($debitNote->items->first()->total_cents)->toBe(10170);
});

test('posting a debit note deducts stock, reduces linked bill amount_due_cents, and posts balanced GL journal', function (): void {
    Event::fake([DebitNotePosted::class]);

    $stockService = app(StockService::class);
    $billService = app(SupplierBillService::class);
    $debitNoteService = app(DebitNoteService::class);

    // Initial stock: 10 units @ $30.00 (3000 cents)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehouse->id,
        productId: (string) $this->product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 3000,
        sourceType: 'test',
        sourceId: 'init-stock',
    ));

    // Post a supplier bill for 10 units @ $30.00 + 13% tax
    $bill = $billService->create(new CreateSupplierBillData(
        supplierId: (string) $this->supplier->id,
        billDate: '2026-11-01',
        dueDate: '2026-11-30',
        items: [
            new SupplierBillItemData(
                description: 'Initial supplier delivery',
                quantity: 10,
                unitCostCents: 3000,
                taxRatePercent: 13,
                productId: (string) $this->product->id,
            ),
        ]
    ));

    $postedBill = $billService->postBill((string) $bill->id, (string) $this->owner->id);
    expect($postedBill->amount_due_cents)->toBe(33900); // 30000 + 3900 tax

    // Check stock before return: 10 units
    $stockLevel = StockLevel::where('warehouse_id', $this->warehouse->id)->where('product_id', $this->product->id)->firstOrFail();
    expect($stockLevel->quantity_on_hand)->toBe(10);

    // Create and post Debit Note for 4 returned units linked to bill
    $debitNote = $debitNoteService->create(new CreateDebitNoteData(
        supplierId: (string) $this->supplier->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        supplierBillId: (string) $postedBill->id,
        returnStock: true,
        reason: 'defective_goods_returned',
        items: [
            new DebitNoteItemData(
                productId: (string) $this->product->id,
                quantity: 4,
                unitCostCents: 3000,
                taxRatePercent: 13,
            ),
        ]
    ), (string) $this->owner->id);

    $postedDn = $debitNoteService->post((string) $debitNote->id, (string) $this->owner->id);

    expect($postedDn->status)->toBe(DebitNoteStatus::Posted)
        ->and($postedDn->total_cents)->toBe(13560); // 12000 subtotal + 1560 tax

    // Verify stock deducted: 10 - 4 = 6 units
    $stockLevel->refresh();
    expect($stockLevel->quantity_on_hand)->toBe(6);

    // Verify bill amount_due_cents reduced: 33900 - 13560 = 20340
    $postedBill->refresh();
    expect($postedBill->amount_due_cents)->toBe(20340);

    // Verify General Ledger Journal Entry
    $journalEntry = JournalEntry::where('source_type', 'debit_note')
        ->where('source_id', $postedDn->id)
        ->firstOrFail();

    $lines = $journalEntry->lines()->with('account')->get();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted)
        ->and($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'));
    $apDebit = $lines->firstWhere('account.code', '2110');
    $inventoryCredit = $lines->firstWhere('account.code', '1150');
    $vatCredit = $lines->firstWhere('account.code', '1160');

    expect($apDebit)->not->toBeNull()
        ->and($apDebit->debit_cents)->toBe(13560)
        ->and($inventoryCredit)->not->toBeNull()
        ->and($inventoryCredit->credit_cents)->toBe(12000)
        ->and($vatCredit)->not->toBeNull()
        ->and($vatCredit->credit_cents)->toBe(1560);

    Event::assertDispatched(DebitNotePosted::class);
});

test('debit notes are immutable once posted and cannot be modified or deleted', function (): void {
    $service = app(DebitNoteService::class);

    $dn = $service->create(new CreateDebitNoteData(
        supplierId: (string) $this->supplier->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        returnStock: false,
        items: [
            new DebitNoteItemData(
                productId: (string) $this->product->id,
                quantity: 1,
                unitCostCents: 3000,
            ),
        ]
    ), (string) $this->owner->id);

    $postedDn = $service->post((string) $dn->id, (string) $this->owner->id);

    expect(fn () => $service->delete((string) $postedDn->id))
        ->toThrow(ApiException::class, 'immutable');

    expect(fn () => $service->update((string) $postedDn->id, new CreateDebitNoteData(
        supplierId: (string) $this->supplier->id,
        warehouseId: (string) $this->warehouse->id,
        issueDate: '2026-11-02',
        items: [
            new DebitNoteItemData(
                productId: (string) $this->product->id,
                quantity: 2,
                unitCostCents: 3000,
            ),
        ]
    )))->toThrow(ApiException::class, 'immutable');
});

test('HTTP API endpoints allow listing, creating, and posting debit notes', function (): void {
    $response = $this->actingAs($this->owner, 'web')
        ->postJson('http://dn-alpha.nexuserp.test/api/v1/purchasing/debit-notes', [
            'supplier_id' => (string) $this->supplier->id,
            'warehouse_id' => (string) $this->warehouse->id,
            'issue_date' => '2026-11-02',
            'return_stock' => false,
            'reason' => 'price_correction',
            'items' => [
                [
                    'product_id' => (string) $this->product->id,
                    'quantity' => 1,
                    'unit_cost_cents' => 3000,
                    'tax_rate_percent' => 13,
                ],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.total_cents', 3390)
        ->assertJsonPath('data.status', 'draft');

    $dnId = (string) $response->json('data.id');

    // Post via API
    $postResponse = $this->actingAs($this->owner, 'web')
        ->postJson("http://dn-alpha.nexuserp.test/api/v1/purchasing/debit-notes/{$dnId}/post");

    $postResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    // List via API
    $listResponse = $this->actingAs($this->owner, 'web')
        ->getJson('http://dn-alpha.nexuserp.test/api/v1/purchasing/debit-notes');

    $listResponse->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $dnId);
});
