<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Models\SalesInvoice;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropOperationalReportsTestTenantDatabase')) {
    function dropOperationalReportsTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_reports_op_alpha', 'tenant_reports_op_beta'], true)) {
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

    dropOperationalReportsTestTenantDatabase('tenant_reports_op_alpha');
    dropOperationalReportsTestTenantDatabase('tenant_reports_op_beta');

    Domain::query()->whereIn('host', ['opalpha.nexuserp.test', 'opbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['opalpha', 'opbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Op Alpha Corp',
        'slug' => 'opalpha',
        'database_name' => 'tenant_reports_op_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'opalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@opalpha.nexuserp.test',
        ownerName: 'Op Alpha Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    TenantUser::where('email', 'owner@opalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->alphaAccountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@opalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaAccountant->assignRole(TenantRole::Accountant->value);

    $this->alphaWarehouseManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@opalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaWarehouseManager->assignRole(TenantRole::WarehouseManager->value);

    $this->alphaSalesRep = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'salesrep@opalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $this->alphaSalesRep->assignRole(TenantRole::SalesRep->value);

    // Warehouse and product
    $this->warehouse = Warehouse::create([
        'id' => (string) Str::ulid(),
        'name' => 'Kathmandu Central Warehouse',
        'code' => 'WH-KTM-01',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->product = Product::create([
        'id' => (string) Str::ulid(),
        'name' => 'Nepal Mountain Tea',
        'sku' => 'TEA-001',
        'cost_price_cents' => 5000,
        'selling_price_cents' => 12000,
        'tax_category' => 'standard',
        'is_active' => true,
    ]);

    StockLevel::create([
        'id' => (string) Str::ulid(),
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'quantity_on_hand' => 10,
        'quantity_reserved' => 0,
    ]);

    // Customer Contact & Invoices
    $this->customer = Contact::create([
        'id' => (string) Str::ulid(),
        'name' => 'Himalayan Organic Cafe',
        'code' => 'CUST-HOC-01',
        'type' => ContactType::Customer,
        'payment_terms_days' => 30,
        'is_active' => true,
    ]);

    // Sales Invoice: 50,000 cents ($500)
    $this->invoice = SalesInvoice::create([
        'id' => (string) Str::ulid(),
        'invoice_number' => 'INV-2026-00001',
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => Carbon::now()->subDays(10)->toDateString(),
        'due_date' => Carbon::now()->addDays(20)->toDateString(), // Current (not overdue)
        'subtotal_cents' => 50000,
        'tax_cents' => 0,
        'total_cents' => 50000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 50000,
        'currency' => 'NPR',
    ]);

    // Supplier Contact & Bill
    $this->supplier = Contact::create([
        'id' => (string) Str::ulid(),
        'name' => 'Everest Tea Gardens Ltd',
        'code' => 'SUPP-ETG-01',
        'type' => ContactType::Supplier,
        'payment_terms_days' => 30,
        'is_active' => true,
    ]);

    // Supplier Bill: 30,000 cents ($300)
    $this->bill = SupplierBill::create([
        'id' => (string) Str::ulid(),
        'bill_number' => 'BILL-2026-00001',
        'supplier_id' => $this->supplier->id,
        'status' => SupplierBillStatus::Posted,
        'bill_date' => Carbon::now()->subDays(45)->toDateString(),
        'due_date' => Carbon::now()->subDays(15)->toDateString(), // 1-30 days overdue
        'subtotal_cents' => 30000,
        'tax_cents' => 0,
        'total_cents' => 30000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 30000,
        'currency' => 'NPR',
    ]);

    // Reconcile GL Control Accounts 1130 (AR), 2110 (AP), and 1150 (Inventory)
    // AR 1130 = 50,000 debit
    // AP 2110 = 30,000 credit
    // Inventory 1150 = 10 units * 5,000 cents = 50,000 debit
    $arAccount = Account::where('code', '1130')->firstOrFail();
    $apAccount = Account::where('code', '2110')->firstOrFail();
    $invAccount = Account::where('code', '1150')->firstOrFail();
    $equityAccount = Account::where('code', '3200')->firstOrFail();

    $reconcileEntry = JournalEntry::create([
        'id' => (string) Str::ulid(),
        'entry_number' => 'JE-RECONCILE-01',
        'entry_date' => Carbon::now()->toDateString(),
        'status' => JournalEntryStatus::Posted->value,
        'total_debit_cents' => 100000,
        'total_credit_cents' => 100000,
        'currency' => 'NPR',
        'created_by_user_id' => $this->alphaAccountant->id,
        'posted_by_user_id' => $this->alphaAccountant->id,
        'posted_at' => now(),
    ]);

    // Debit AR 1130: 50,000 cents
    $reconcileEntry->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $arAccount->id,
        'line_number' => 1,
        'debit_cents' => 50000,
        'credit_cents' => 0,
    ]);

    // Debit Inventory 1150: 50,000 cents
    $reconcileEntry->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $invAccount->id,
        'line_number' => 2,
        'debit_cents' => 50000,
        'credit_cents' => 0,
    ]);

    // Credit AP 2110: 30,000 cents
    $reconcileEntry->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $apAccount->id,
        'line_number' => 3,
        'debit_cents' => 0,
        'credit_cents' => 30000,
    ]);

    // Credit Equity 3200: 70,000 cents
    $reconcileEntry->lines()->create([
        'id' => (string) Str::ulid(),
        'account_id' => $equityAccount->id,
        'line_number' => 4,
        'debit_cents' => 0,
        'credit_cents' => 70000,
    ]);

    // Record a stock movement
    StockMovement::create([
        'id' => (string) Str::ulid(),
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity' => 10,
        'unit_cost_cents' => 5000,
        'notes' => 'Initial stock intake',
        'created_at' => now(),
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    dropOperationalReportsTestTenantDatabase('tenant_reports_op_alpha');
    dropOperationalReportsTestTenantDatabase('tenant_reports_op_beta');
});

test('AR Aging: Generates brackets and successfully reconciles with Control Account 1130', function (): void {
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ar-aging');
    $response->assertOk()
        ->assertJsonPath('data.is_reconciled', true)
        ->assertJsonPath('data.reconciliation_difference_cents', 0)
        ->assertJsonPath('data.gl_control_account_cents', 50000)
        ->assertJsonPath('data.summary.total_due_cents', 50000)
        ->assertJsonPath('data.summary.current_cents', 50000);

    $customers = $response->json('data.customers');
    expect($customers)->toBeArray()->toHaveCount(1)
        ->and($customers[0]['customer_code'])->toBe('CUST-HOC-01')
        ->and($customers[0]['customer_name'])->toBe('Himalayan Organic Cafe')
        ->and($customers[0]['total_due_cents'])->toBe(50000);

    // Test with customer filter -> includes invoices
    $filtered = $this->getJson("http://opalpha.nexuserp.test/api/v1/reports/ar-aging?customer_id={$this->customer->id}");
    $filtered->assertOk()
        ->assertJsonPath('data.invoices.0.invoice_number', 'INV-2026-00001');
});

test('AP Aging: Generates brackets and successfully reconciles with Control Account 2110', function (): void {
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ap-aging');
    $response->assertOk()
        ->assertJsonPath('data.is_reconciled', true)
        ->assertJsonPath('data.reconciliation_difference_cents', 0)
        ->assertJsonPath('data.gl_control_account_cents', 30000)
        ->assertJsonPath('data.summary.total_due_cents', 30000)
        ->assertJsonPath('data.summary.days_1_30_cents', 30000);

    $suppliers = $response->json('data.suppliers');
    expect($suppliers)->toBeArray()->toHaveCount(1)
        ->and($suppliers[0]['supplier_code'])->toBe('SUPP-ETG-01')
        ->and($suppliers[0]['supplier_name'])->toBe('Everest Tea Gardens Ltd')
        ->and($suppliers[0]['total_due_cents'])->toBe(30000);

    // Test with supplier filter -> includes bills
    $filtered = $this->getJson("http://opalpha.nexuserp.test/api/v1/reports/ap-aging?supplier_id={$this->supplier->id}");
    $filtered->assertOk()
        ->assertJsonPath('data.bills.0.bill_number', 'BILL-2026-00001');
});

test('Stock Valuation: Computes moving weighted average valuation and reconciles with Control Account 1150', function (): void {
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'warehouse@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-valuation');
    $response->assertOk()
        ->assertJsonPath('data.summary.total_valuation_cents', 50000)
        ->assertJsonPath('data.summary.total_units_on_hand', 10)
        ->assertJsonPath('data.summary.gl_control_account_cents', 50000)
        ->assertJsonPath('data.summary.reconciliation_difference_cents', 0)
        ->assertJsonPath('data.summary.is_reconciled', true);

    $items = $response->json('data.items');
    expect($items)->toBeArray()->toHaveCount(1)
        ->and($items[0]['sku'])->toBe('TEA-001')
        ->and($items[0]['total_quantity_on_hand'])->toBe(10)
        ->and($items[0]['moving_average_cost_cents'])->toBe(5000)
        ->and($items[0]['total_valuation_cents'])->toBe(50000);
});

test('Stock Movements: Lists chronological movement records with filters', function (): void {
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'warehouse@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $response = $this->getJson("http://opalpha.nexuserp.test/api/v1/reports/stock-movements?warehouse_id={$this->warehouse->id}");
    $response->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.items.0.quantity', 10)
        ->assertJsonPath('data.items.0.unit_cost_cents', 5000)
        ->assertJsonPath('data.items.0.type', StockMovementType::OpeningBalance->value);
});

test('RBAC & Web Route: Warehouse Manager has stock access, Accountant has all, Sales Rep is restricted', function (): void {
    // 1. Warehouse Manager -> Stock OK, Financial 403
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'warehouse@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-valuation')->assertOk();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-movements')->assertOk();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ar-aging')->assertForbidden();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ap-aging')->assertForbidden();
    $this->get('http://opalpha.nexuserp.test/reports')->assertOk();

    $this->flushSession();

    // 2. Sales Rep -> 403 on everything
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'salesrep@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-valuation')->assertForbidden();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-movements')->assertForbidden();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ar-aging')->assertForbidden();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ap-aging')->assertForbidden();
    $this->get('http://opalpha.nexuserp.test/reports')->assertForbidden();

    $this->flushSession();

    // 3. Accountant -> 200 OK on everything
    $this->postJson('http://opalpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'accountant@opalpha.nexuserp.test',
        'password' => 'Password12345!',
    ])->assertOk();

    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-valuation')->assertOk();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/stock-movements')->assertOk();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ar-aging')->assertOk();
    $this->getJson('http://opalpha.nexuserp.test/api/v1/reports/ap-aging')->assertOk();
    $this->get('http://opalpha.nexuserp.test/reports')->assertOk();
});
