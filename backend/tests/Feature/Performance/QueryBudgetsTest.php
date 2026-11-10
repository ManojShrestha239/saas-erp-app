<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Services\AgingReportService;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Purchasing\Models\SupplierBillItem;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropPerformanceTestDatabase')) {
    function dropPerformanceTestDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_perf_budget_test') {
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

function loginAsPerformanceTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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
    Carbon::setTestNow('2026-11-10 12:00:00');
    app('cache')->flush();

    dropPerformanceTestDatabase('tenant_perf_budget_test');

    Domain::query()->where('host', 'perf-test.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'perf-test')->delete();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--realpath' => false,
        '--force' => true,
    ]);

    $tenantId = (string) Str::ulid();
    $this->tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Performance Test Corp',
        'slug' => 'perf-test',
        'database_name' => 'tenant_perf_budget_test',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'perf-test.nexuserp.test',
        'is_primary' => true,
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@perf-test.nexuserp.test',
        ownerName: 'Perf Owner',
    );

    $this->switchToTenant = function (): void {
        app(TenantManager::class)->switchTo(new TenantContext(
            id: $this->tenant->id,
            slug: $this->tenant->slug,
            databaseName: $this->tenant->database_name,
        ));
    };

    ($this->switchToTenant)();

    $this->owner = TenantUser::where('email', 'owner@perf-test.nexuserp.test')->firstOrFail();
    $this->owner->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->customer = Contact::create([
        'code' => 'CUST-PERF-001',
        'type' => ContactType::Customer,
        'name' => 'Perf Customer One',
        'payment_terms_days' => 30,
        'credit_limit_cents' => 10000000,
        'is_active' => true,
    ]);

    $this->supplier = Contact::create([
        'code' => 'SUPP-PERF-001',
        'type' => ContactType::Supplier,
        'name' => 'Perf Supplier One',
        'payment_terms_days' => 30,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'Perf Warehouse',
        'code' => 'WH-PERF',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'name' => 'Perf Product',
        'sku' => 'SKU-PERF-001',
        'cost_price_cents' => 5000,
        'selling_price_cents' => 10000,
        'tax_category' => 'standard',
        'is_active' => true,
    ]);
});

afterEach(function (): void {
    dropPerformanceTestDatabase('tenant_perf_budget_test');
});

test('performance indexes exist on core tenant tables', function (): void {
    ($this->switchToTenant)();

    $tables = [
        'sales_invoices' => [
            'sales_invoices_status_due_date_idx',
            'sales_invoices_status_issue_date_idx',
            'sales_invoices_status_amount_due_idx',
            'sales_invoices_customer_due_date_idx',
        ],
        'supplier_bills' => [
            'supplier_bills_status_due_date_idx',
            'supplier_bills_status_bill_date_idx',
            'supplier_bills_status_amount_due_idx',
            'supplier_bills_supplier_due_date_idx',
        ],
        'stock_movements' => [
            'stock_movements_source_idx',
            'stock_movements_warehouse_created_idx',
            'stock_movements_type_created_idx',
        ],
        'journal_entry_lines' => [
            'journal_entry_lines_entry_account_idx',
        ],
        'products' => [
            'products_active_name_idx',
            'products_category_active_idx',
        ],
        'contacts' => [
            'contacts_type_name_idx',
        ],
    ];

    foreach ($tables as $table => $expectedIndexes) {
        /** @var list<object{indexname: string}> $indexes */
        $indexes = DB::connection('tenant')->select("SELECT indexname FROM pg_indexes WHERE tablename = '{$table}'");
        $indexNames = array_map(static fn (object $row): string => $row->indexname, $indexes);

        foreach ($expectedIndexes as $idx) {
            expect($indexNames)->toContain($idx);
        }
    }
});

test('sales invoices listing has deterministic O(1) query budget regardless of invoice count', function (): void {
    ($this->switchToTenant)();

    // 1. Create 1 invoice with 3 items
    $inv1 = SalesInvoice::create([
        'invoice_number' => 'INV-2026-000001',
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Draft,
        'issue_date' => '2026-11-10',
        'due_date' => '2026-12-10',
        'subtotal_cents' => 30000,
        'tax_cents' => 3900,
        'total_cents' => 33900,
        'amount_due_cents' => 33900,
        'created_by_user_id' => $this->owner->id,
    ]);
    for ($i = 0; $i < 3; $i++) {
        SalesInvoiceItem::create([
            'sales_invoice_id' => $inv1->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price_cents' => 10000,
            'subtotal_cents' => 10000,
            'tax_cents' => 1300,
            'total_cents' => 11300,
        ]);
    }

    $cookie = loginAsPerformanceTenant($this, 'perf-test.nexuserp.test', 'owner@perf-test.nexuserp.test');

    ($this->switchToTenant)();
    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $res1 = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://perf-test.nexuserp.test/api/v1/sales/invoices');
    $res1->assertOk();

    $queries1 = DB::connection('tenant')->getQueryLog();
    $count1 = count($queries1);

    // Assert query count is bounded (pagination count + invoice select + eager loaded items)
    expect($count1)->toBeLessThanOrEqual(4);

    // 2. Create 5 additional invoices with items (total 6 invoices, 18 items)
    ($this->switchToTenant)();
    for ($j = 2; $j <= 6; $j++) {
        $inv = SalesInvoice::create([
            'invoice_number' => sprintf('INV-2026-%06d', $j),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => SalesInvoiceStatus::Draft,
            'issue_date' => '2026-11-10',
            'due_date' => '2026-12-10',
            'subtotal_cents' => 30000,
            'tax_cents' => 3900,
            'total_cents' => 33900,
            'amount_due_cents' => 33900,
            'created_by_user_id' => $this->owner->id,
        ]);
        for ($i = 0; $i < 3; $i++) {
            SalesInvoiceItem::create([
                'sales_invoice_id' => $inv->id,
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price_cents' => 10000,
                'subtotal_cents' => 10000,
                'tax_cents' => 1300,
                'total_cents' => 11300,
            ]);
        }
    }

    DB::connection('tenant')->flushQueryLog();

    $res2 = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://perf-test.nexuserp.test/api/v1/sales/invoices');
    $res2->assertOk();

    $queries2 = DB::connection('tenant')->getQueryLog();
    $count2 = count($queries2);

    // Constant query count: 6 invoices execute exactly the same number of queries as 1 invoice (O(1))
    expect($count2)->toBe($count1);
});

test('supplier bills listing has deterministic O(1) query budget regardless of bill count', function (): void {
    ($this->switchToTenant)();

    // 1. Create 1 supplier bill with items
    $bill1 = SupplierBill::create([
        'bill_number' => 'BILL-2026-000001',
        'supplier_id' => $this->supplier->id,
        'status' => SupplierBillStatus::Draft,
        'bill_date' => '2026-11-10',
        'due_date' => '2026-12-10',
        'subtotal_cents' => 20000,
        'tax_cents' => 2600,
        'total_cents' => 22600,
        'amount_due_cents' => 22600,
    ]);
    for ($i = 0; $i < 2; $i++) {
        SupplierBillItem::create([
            'supplier_bill_id' => $bill1->id,
            'product_id' => $this->product->id,
            'description' => 'Test component',
            'quantity' => 2,
            'unit_cost_cents' => 5000,
            'subtotal_cents' => 10000,
            'tax_cents' => 1300,
            'total_cents' => 11300,
        ]);
    }

    $cookie = loginAsPerformanceTenant($this, 'perf-test.nexuserp.test', 'owner@perf-test.nexuserp.test');

    ($this->switchToTenant)();
    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $res1 = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://perf-test.nexuserp.test/api/v1/purchasing/bills');
    $res1->assertOk();

    $queries1 = DB::connection('tenant')->getQueryLog();
    $count1 = count($queries1);

    expect($count1)->toBeLessThanOrEqual(4);

    // 2. Create 5 additional bills
    ($this->switchToTenant)();
    for ($j = 2; $j <= 6; $j++) {
        $bill = SupplierBill::create([
            'bill_number' => sprintf('BILL-2026-%06d', $j),
            'supplier_id' => $this->supplier->id,
            'status' => SupplierBillStatus::Draft,
            'bill_date' => '2026-11-10',
            'due_date' => '2026-12-10',
            'subtotal_cents' => 20000,
            'tax_cents' => 2600,
            'total_cents' => 22600,
            'amount_due_cents' => 22600,
        ]);
        for ($i = 0; $i < 2; $i++) {
            SupplierBillItem::create([
                'supplier_bill_id' => $bill->id,
                'product_id' => $this->product->id,
                'description' => 'Test component',
                'quantity' => 2,
                'unit_cost_cents' => 5000,
                'subtotal_cents' => 10000,
                'tax_cents' => 1300,
                'total_cents' => 11300,
            ]);
        }
    }

    DB::connection('tenant')->flushQueryLog();

    $res2 = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://perf-test.nexuserp.test/api/v1/purchasing/bills');
    $res2->assertOk();

    $queries2 = DB::connection('tenant')->getQueryLog();
    $count2 = count($queries2);

    expect($count2)->toBe($count1);
});

test('stock movements listing has deterministic O(1) query budget with eager loaded relationships', function (): void {
    ($this->switchToTenant)();
    $stockService = app(StockService::class);

    // 1. Record 1 movement
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehouse->id,
        productId: (string) $this->product->id,
        type: StockMovementType::OpeningBalance,
        quantity: 100,
        unitCostCents: 5000,
        sourceType: 'seed',
        sourceId: 'seed-1',
        userId: (string) $this->owner->id,
        notes: 'Initial stock',
    ));

    $cookie = loginAsPerformanceTenant($this, 'perf-test.nexuserp.test', 'owner@perf-test.nexuserp.test');

    ($this->switchToTenant)();
    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $res1 = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://perf-test.nexuserp.test/api/v1/inventory/stock-movements');
    $res1->assertOk();

    $count1 = count(DB::connection('tenant')->getQueryLog());
    expect($count1)->toBeLessThanOrEqual(5);

    // 2. Record 5 more movements
    ($this->switchToTenant)();
    for ($i = 2; $i <= 6; $i++) {
        $stockService->recordMovement(new RecordStockMovementData(
            warehouseId: (string) $this->warehouse->id,
            productId: (string) $this->product->id,
            type: StockMovementType::AdjustmentIn,
            quantity: 10,
            unitCostCents: 5000,
            sourceType: 'seed',
            sourceId: "seed-{$i}",
            userId: (string) $this->owner->id,
            notes: "Adjustment {$i}",
        ));
    }

    DB::connection('tenant')->flushQueryLog();

    $res2 = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://perf-test.nexuserp.test/api/v1/inventory/stock-movements');
    $res2->assertOk();

    $count2 = count(DB::connection('tenant')->getQueryLog());

    // Constant query count: 6 movements query exactly the same number of queries as 1 movement
    expect($count2)->toBe($count1);
});

test('Trial Balance calculation executes within fixed query budget (<= 3 queries) regardless of entry volume', function (): void {
    ($this->switchToTenant)();
    $reportService = app(FinancialReportService::class);
    $cashAccount = Account::where('code', '1110')->firstOrFail();
    $revenueAccount = Account::where('code', '4110')->firstOrFail();

    // 1. Create 1 journal entry with lines
    $entry1 = JournalEntry::create([
        'entry_number' => 'JRN-2026-000001',
        'entry_date' => '2026-11-10',
        'posting_date' => now(),
        'status' => JournalEntryStatus::Posted,
        'total_debit_cents' => 50000,
        'total_credit_cents' => 50000,
        'created_by_user_id' => $this->owner->id,
    ]);
    JournalEntryLine::create([
        'journal_entry_id' => $entry1->id,
        'account_id' => $cashAccount->id,
        'line_number' => 1,
        'debit_cents' => 50000,
        'credit_cents' => 0,
    ]);
    JournalEntryLine::create([
        'journal_entry_id' => $entry1->id,
        'account_id' => $revenueAccount->id,
        'line_number' => 2,
        'debit_cents' => 0,
        'credit_cents' => 50000,
    ]);

    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $data1 = $reportService->getTrialBalance('2026-11-10');
    expect($data1->isBalanced)->toBeTrue();

    $count1 = count(DB::connection('tenant')->getQueryLog());
    expect($count1)->toBeLessThanOrEqual(3);

    // 2. Create 10 more journal entries
    for ($i = 2; $i <= 11; $i++) {
        $entry = JournalEntry::create([
            'entry_number' => sprintf('JRN-2026-%06d', $i),
            'entry_date' => '2026-11-10',
            'posting_date' => now(),
            'status' => JournalEntryStatus::Posted,
            'total_debit_cents' => 10000,
            'total_credit_cents' => 10000,
            'created_by_user_id' => $this->owner->id,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'line_number' => 1,
            'debit_cents' => 10000,
            'credit_cents' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'line_number' => 2,
            'debit_cents' => 0,
            'credit_cents' => 10000,
        ]);
    }

    DB::connection('tenant')->flushQueryLog();

    $data2 = $reportService->getTrialBalance('2026-11-10');
    expect($data2->isBalanced)->toBeTrue();

    $count2 = count(DB::connection('tenant')->getQueryLog());

    // Query count remains constant (1 query for accounts, 1 aggregated query for lines)
    expect($count2)->toBe($count1);
});

test('AR Aging report queries contacts in a single batch (no N+1 contact queries)', function (): void {
    ($this->switchToTenant)();
    $agingService = app(AgingReportService::class);

    // Create 5 different customers with open posted invoices
    for ($i = 1; $i <= 5; $i++) {
        $customer = Contact::create([
            'code' => sprintf('CUST-AGING-%03d', $i),
            'type' => ContactType::Customer,
            'name' => "Customer {$i}",
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => sprintf('INV-AGING-%06d', $i),
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => SalesInvoiceStatus::Posted,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'subtotal_cents' => 10000,
            'tax_cents' => 0,
            'total_cents' => 10000,
            'amount_due_cents' => 10000,
            'posted_at' => now(),
            'created_by_user_id' => $this->owner->id,
        ]);
    }

    DB::connection('tenant')->flushQueryLog();
    DB::connection('tenant')->enableQueryLog();

    $report = $agingService->getArAging('2026-11-10');

    expect($report['customers'])->toHaveCount(5);

    $queries = DB::connection('tenant')->getQueryLog();

    // Exactly 2 queries:
    // 1. Select open posted sales invoices
    // 2. Select contacts WHERE id IN (customer_id_1, customer_id_2, ...)
    expect(count($queries))->toBe(2);
});
