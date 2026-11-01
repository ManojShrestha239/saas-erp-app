<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\CreateStockAdjustmentData;
use Modules\Inventory\DTOs\StockAdjustmentItemData;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Payments\DTOs\CreatePaymentData;
use Modules\Payments\Enums\PaymentMethod;
use Modules\Payments\Enums\PaymentType;
use Modules\Payments\Services\PaymentService;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\SupplierBillItemData;
use Modules\Purchasing\Services\SupplierBillService;
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

if (! function_exists('dropDocPostingTestTenantDb')) {
    function dropDocPostingTestTenantDb(string $dbName): void
    {
        if ($dbName !== 'tenant_doc_posting_alpha') {
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

    dropDocPostingTestTenantDb('tenant_doc_posting_alpha');

    Domain::query()->where('host', 'docpost.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'docpost')->delete();

    $alphaId = (string) Str::ulid();
    $this->tenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Doc Posting Corp',
        'slug' => 'docpost',
        'database_name' => 'tenant_doc_posting_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'docpost.nexuserp.test',
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@docpost.nexuserp.test',
        ownerName: 'Doc Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'docpost',
        databaseName: 'tenant_doc_posting_alpha',
    ));

    $this->user = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Doc Accountant',
        'email' => 'accountant@docpost.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->user->syncRoles([TenantRole::Accountant->value]);

    $this->customer = Contact::create([
        'id' => (string) Str::ulid(),
        'code' => 'CUST-000001',
        'type' => 'customer',
        'name' => 'Acme Client',
        'is_active' => true,
    ]);

    $this->supplier = Contact::create([
        'id' => (string) Str::ulid(),
        'code' => 'SUPP-000001',
        'type' => 'supplier',
        'name' => 'Acme Supplier',
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::create([
        'id' => (string) Str::ulid(),
        'code' => 'WH-01',
        'name' => 'Main Warehouse',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'id' => (string) Str::ulid(),
        'sku' => 'PROD-001',
        'name' => 'Widget',
        'cost_price_cents' => 30_00,
        'selling_price_cents' => 100_00,
        'is_active' => true,
    ]);

    // Give some initial stock
    StockLevel::create([
        'id' => (string) Str::ulid(),
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'quantity_on_hand' => 100,
        'quantity_reserved' => 0,
    ]);
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropDocPostingTestTenantDb('tenant_doc_posting_alpha');
});

test('posting a sales invoice generates a balanced general ledger journal entry and voiding reverses it', function (): void {
    /** @var SalesInvoiceService $service */
    $service = app(SalesInvoiceService::class);

    // 1. Create draft invoice: 2 widgets @ $100 = $200 subtotal, 13% tax = $26 tax, total = $226
    $invoice = $service->create(new CreateSalesInvoiceData(
        customerId: $this->customer->id,
        issueDate: '2026-11-01',
        dueDate: '2026-11-15',
        currency: 'USD',
        items: [
            new SalesInvoiceItemData(
                productId: $this->product->id,
                quantity: 2,
                unitPriceCents: 100_00,
                taxRatePercent: 13,
            ),
        ],
        warehouseId: $this->warehouse->id,
    ), $this->user->id);

    expect($invoice->subtotal_cents)->toBe(200_00);
    expect($invoice->tax_cents)->toBe(26_00);
    expect($invoice->total_cents)->toBe(226_00);

    // 2. Post invoice
    $postedInvoice = $service->post($invoice->id, $this->user->id);

    // 3. Verify JournalEntry created for sales_invoice
    /** @var JournalEntry|null $journalEntry */
    $journalEntry = JournalEntry::where('source_type', 'sales_invoice')
        ->where('source_id', $postedInvoice->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($journalEntry->total_debit_cents)->toBe($journalEntry->total_credit_cents);
    // Debits: AR ($226.00) + COGS (2 widgets @ $30 cost = $60.00) = $286.00
    expect($journalEntry->total_debit_cents)->toBe(286_00);

    // Lines check: AR debit 226_00, Revenue credit 200_00, VAT credit 26_00, COGS debit 60_00, Inventory credit 60_00
    $lines = $journalEntry->lines()->with('account')->get();
    expect($lines)->toHaveCount(5);

    $arLine = $lines->firstWhere('account.code', '1130');
    expect($arLine)->not->toBeNull();
    expect($arLine->debit_cents)->toBe(226_00);
    expect($arLine->credit_cents)->toBe(0);

    $revLine = $lines->firstWhere('account.code', '4110');
    expect($revLine)->not->toBeNull();
    expect($revLine->credit_cents)->toBe(200_00);
    expect($revLine->debit_cents)->toBe(0);

    $vatLine = $lines->firstWhere('account.code', '2120');
    expect($vatLine)->not->toBeNull();
    expect($vatLine->credit_cents)->toBe(26_00);
    expect($vatLine->debit_cents)->toBe(0);

    $cogsLine = $lines->firstWhere('account.code', '5100');
    expect($cogsLine)->not->toBeNull();
    expect($cogsLine->debit_cents)->toBe(60_00);
    expect($cogsLine->credit_cents)->toBe(0);

    $invLine = $lines->firstWhere('account.code', '1150');
    expect($invLine)->not->toBeNull();
    expect($invLine->credit_cents)->toBe(60_00);
    expect($invLine->debit_cents)->toBe(0);

    // 4. Void invoice
    $service->void($postedInvoice->id, $this->user->id);

    // 5. Verify journal entry status is reversed
    $journalEntry->refresh();
    expect($journalEntry->isReversed())->toBeTrue();
    expect($journalEntry->reversed_by_id)->not->toBeNull();

    // 6. Verify reversal entry exists and is balanced with swapped lines
    /** @var JournalEntry $reversalEntry */
    $reversalEntry = JournalEntry::where('id', $journalEntry->reversed_by_id)->first();
    expect($reversalEntry)->not->toBeNull();
    expect($reversalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($reversalEntry->total_debit_cents)->toBe(286_00);
    expect($reversalEntry->total_credit_cents)->toBe(286_00);

    $reversalLines = $reversalEntry->lines()->with('account')->get();
    expect($reversalLines)->toHaveCount(5);

    $revArLine = $reversalLines->firstWhere('account.code', '1130');
    expect($revArLine->credit_cents)->toBe(226_00);
    expect($revArLine->debit_cents)->toBe(0);

    $revCogsLine = $reversalLines->firstWhere('account.code', '5100');
    expect($revCogsLine->credit_cents)->toBe(60_00);
    expect($revCogsLine->debit_cents)->toBe(0);
});

test('posting a supplier bill generates a balanced general ledger journal entry', function (): void {
    /** @var SupplierBillService $service */
    $service = app(SupplierBillService::class);

    $bill = $service->create(new CreateSupplierBillData(
        supplierId: $this->supplier->id,
        supplierInvoiceNumber: 'INV-SUPP-999',
        billDate: '2026-11-01',
        dueDate: '2026-11-30',
        currency: 'USD',
        items: [
            new SupplierBillItemData(
                description: 'Raw Materials',
                quantity: 10,
                unitCostCents: 50_00,
                taxRatePercent: 13,
                productId: $this->product->id,
            ),
        ],
    ));

    expect($bill->subtotal_cents)->toBe(500_00);
    expect($bill->tax_cents)->toBe(65_00);
    expect($bill->total_cents)->toBe(565_00);

    $postedBill = $service->postBill($bill->id, $this->user->id);

    $journalEntry = JournalEntry::where('source_type', 'supplier_bill')
        ->where('source_id', $postedBill->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($journalEntry->total_debit_cents)->toBe(565_00);
    expect($journalEntry->total_credit_cents)->toBe(565_00);

    $lines = $journalEntry->lines()->with('account')->get();
    expect($lines)->toHaveCount(3);

    // Inventory debit: 500_00
    $invLine = $lines->firstWhere('account.code', '1150');
    expect($invLine)->not->toBeNull();
    expect($invLine->debit_cents)->toBe(500_00);
    expect($invLine->credit_cents)->toBe(0);

    // Input VAT debit: 65_00
    $vatLine = $lines->firstWhere('account.code', '1160');
    expect($vatLine)->not->toBeNull();
    expect($vatLine->debit_cents)->toBe(65_00);
    expect($vatLine->credit_cents)->toBe(0);

    // AP credit: 565_00
    $apLine = $lines->firstWhere('account.code', '2110');
    expect($apLine)->not->toBeNull();
    expect($apLine->credit_cents)->toBe(565_00);
    expect($apLine->debit_cents)->toBe(0);
});

test('posting an inbound payment generates a balanced general ledger journal entry', function (): void {
    /** @var PaymentService $service */
    $service = app(PaymentService::class);

    $payment = $service->create(new CreatePaymentData(
        type: PaymentType::Inbound,
        contactId: $this->customer->id,
        amountCents: 150_00,
        paymentMethod: PaymentMethod::BankTransfer,
        paymentDate: '2026-11-02',
        currency: 'USD',
        postImmediately: true,
    ), $this->user->id);

    $journalEntry = JournalEntry::where('source_type', 'payment')
        ->where('source_id', $payment->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($journalEntry->total_debit_cents)->toBe(150_00);
    expect($journalEntry->total_credit_cents)->toBe(150_00);

    $lines = $journalEntry->lines()->with('account')->get();
    expect($lines)->toHaveCount(2);

    // Bank debit: 150_00
    $bankLine = $lines->firstWhere('account.code', '1120');
    expect($bankLine)->not->toBeNull();
    expect($bankLine->debit_cents)->toBe(150_00);
    expect($bankLine->credit_cents)->toBe(0);

    // AR credit: 150_00
    $arLine = $lines->firstWhere('account.code', '1130');
    expect($arLine)->not->toBeNull();
    expect($arLine->credit_cents)->toBe(150_00);
    expect($arLine->debit_cents)->toBe(0);
});

test('posting an outbound payment generates a balanced general ledger journal entry', function (): void {
    /** @var PaymentService $service */
    $service = app(PaymentService::class);

    $payment = $service->create(new CreatePaymentData(
        type: PaymentType::Outbound,
        contactId: $this->supplier->id,
        amountCents: 200_00,
        paymentMethod: PaymentMethod::BankTransfer,
        paymentDate: '2026-11-03',
        currency: 'USD',
        postImmediately: false,
    ), $this->user->id);

    // Draft should not have journal entry
    expect(JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->first())->toBeNull();

    // Post explicitly
    $postedPayment = $service->post($payment->id, $this->user->id);

    $journalEntry = JournalEntry::where('source_type', 'payment')
        ->where('source_id', $postedPayment->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($journalEntry->total_debit_cents)->toBe(200_00);
    expect($journalEntry->total_credit_cents)->toBe(200_00);

    $lines = $journalEntry->lines()->with('account')->get();
    expect($lines)->toHaveCount(2);

    // AP debit: 200_00
    $apLine = $lines->firstWhere('account.code', '2110');
    expect($apLine)->not->toBeNull();
    expect($apLine->debit_cents)->toBe(200_00);
    expect($apLine->credit_cents)->toBe(0);

    // Bank credit: 200_00
    $bankLine = $lines->firstWhere('account.code', '1120');
    expect($bankLine)->not->toBeNull();
    expect($bankLine->credit_cents)->toBe(200_00);
    expect($bankLine->debit_cents)->toBe(0);
});

test('positive stock adjustment generates a balanced general ledger journal entry for inventory gain', function (): void {
    /** @var StockAdjustmentService $service */
    $service = app(StockAdjustmentService::class);

    $adjustment = $service->create(new CreateStockAdjustmentData(
        warehouseId: $this->warehouse->id,
        reason: 'Annual inventory count surplus',
        items: [
            new StockAdjustmentItemData(
                productId: $this->product->id,
                quantityDelta: 5,
                unitCostCents: 30_00,
            ),
        ],
        userId: $this->user->id,
    ));

    expect($adjustment->total_cost_impact_cents)->toBe(150_00);

    $journalEntry = JournalEntry::where('source_type', 'stock_adjustment')
        ->where('source_id', $adjustment->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($journalEntry->total_debit_cents)->toBe(150_00);
    expect($journalEntry->total_credit_cents)->toBe(150_00);

    $lines = $journalEntry->lines()->with('account')->get();
    expect($lines)->toHaveCount(2);

    // Debit: Inventory Asset (1150)
    $invLine = $lines->firstWhere('account.code', '1150');
    expect($invLine)->not->toBeNull();
    expect($invLine->debit_cents)->toBe(150_00);
    expect($invLine->credit_cents)->toBe(0);

    // Credit: Inventory Adjustment (5200)
    $adjLine = $lines->firstWhere('account.code', '5200');
    expect($adjLine)->not->toBeNull();
    expect($adjLine->credit_cents)->toBe(150_00);
    expect($adjLine->debit_cents)->toBe(0);
});

test('negative stock adjustment generates a balanced general ledger journal entry for inventory shrinkage', function (): void {
    /** @var StockAdjustmentService $service */
    $service = app(StockAdjustmentService::class);

    $adjustment = $service->create(new CreateStockAdjustmentData(
        warehouseId: $this->warehouse->id,
        reason: 'Water damage shrinkage',
        items: [
            new StockAdjustmentItemData(
                productId: $this->product->id,
                quantityDelta: -2,
                unitCostCents: 30_00,
            ),
        ],
        userId: $this->user->id,
    ));

    expect($adjustment->total_cost_impact_cents)->toBe(-60_00);

    $journalEntry = JournalEntry::where('source_type', 'stock_adjustment')
        ->where('source_id', $adjustment->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    expect($journalEntry->total_debit_cents)->toBe(60_00);
    expect($journalEntry->total_credit_cents)->toBe(60_00);

    $lines = $journalEntry->lines()->with('account')->get();
    expect($lines)->toHaveCount(2);

    // Debit: Inventory Adjustment (5200)
    $adjLine = $lines->firstWhere('account.code', '5200');
    expect($adjLine)->not->toBeNull();
    expect($adjLine->debit_cents)->toBe(60_00);
    expect($adjLine->credit_cents)->toBe(0);

    // Credit: Inventory Asset (1150)
    $invLine = $lines->firstWhere('account.code', '1150');
    expect($invLine)->not->toBeNull();
    expect($invLine->credit_cents)->toBe(60_00);
    expect($invLine->debit_cents)->toBe(0);
});
