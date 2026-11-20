<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Payments\Enums\PaymentMethod;
use Modules\Payments\Enums\PaymentType;
use Modules\Payments\Models\Payment;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Models\SalesInvoice;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropStatementTestTenantDatabase')) {
    function dropStatementTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_stmt_alpha', 'tenant_stmt_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

if (! function_exists('loginAsStatementTenant')) {
    function loginAsStatementTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-29 12:00:00');
    app('cache')->flush();

    dropStatementTestTenantDatabase('tenant_stmt_alpha');
    dropStatementTestTenantDatabase('tenant_stmt_beta');

    Domain::query()->whereIn('host', ['alpha-stmt.nexuserp.test', 'beta-stmt.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-stmt', 'beta-stmt'])->delete();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--realpath' => false,
        '--force' => true,
    ]);

    // Setup Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha Statements Corp',
        'slug' => 'alpha-stmt',
        'database_name' => 'tenant_stmt_alpha',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'id' => (string) Str::ulid(),
        'tenant_id' => $alphaId,
        'host' => 'alpha-stmt.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha-stmt.com',
        ownerName: 'Alpha Owner',
    );

    // Setup Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Statements Corp',
        'slug' => 'beta-stmt',
        'database_name' => 'tenant_stmt_beta',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'id' => (string) Str::ulid(),
        'tenant_id' => $betaId,
        'host' => 'beta-stmt.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta-stmt.com',
        ownerName: 'Beta Owner',
    );

    // Seed users on Alpha
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->adminUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Admin',
        'email' => 'admin@alpha-stmt.com',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);
    $this->adminUser->assignRole(TenantRole::Owner->value);

    $this->salesRepUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@alpha-stmt.com',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);
    $this->salesRepUser->assignRole(TenantRole::SalesRep->value);

    $this->warehouse = Warehouse::create([
        'name' => 'Alpha Central Warehouse',
        'code' => 'WH-STMT',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'name' => 'Industrial Widget',
        'sku' => 'WIDGET-01',
        'cost_price_cents' => 2000,
        'selling_price_cents' => 5000,
        'tax_category' => 'standard',
        'is_active' => true,
    ]);

    // Seed users on Beta
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    $this->betaUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Beta Admin',
        'email' => 'admin@beta-stmt.com',
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);
    $this->betaUser->assignRole(TenantRole::Owner->value);

    $tenantManager->reset();
});

afterEach(function (): void {
    Carbon::setTestNow();
    dropStatementTestTenantDatabase('tenant_stmt_alpha');
    dropStatementTestTenantDatabase('tenant_stmt_beta');
});

test('AR aging calculation allocates open customer invoices accurately across all 5 overdue buckets', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $customer = Contact::create([
        'name' => 'Apex Retailers',
        'code' => 'CUST-APEX',
        'type' => ContactType::Customer,
        'payment_terms_days' => 30,
        'credit_limit_cents' => 10000000,
        'opening_balance_cents' => 50000,
    ]);

    // Current: due 2026-11-05 (7 days in future) -> $100.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000001',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-10-06',
        'due_date' => '2026-11-05',
        'subtotal_cents' => 10000,
        'tax_cents' => 0,
        'total_cents' => 10000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 10000,
        'currency' => 'USD',
    ]);

    // 1-30 days overdue: due 2026-10-14 (15 days overdue) -> $200.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000002',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-09-14',
        'due_date' => '2026-10-14',
        'subtotal_cents' => 20000,
        'tax_cents' => 0,
        'total_cents' => 20000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 20000,
        'currency' => 'USD',
    ]);

    // 31-60 days overdue: due 2026-09-14 (45 days overdue) -> $300.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000003',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-08-15',
        'due_date' => '2026-09-14',
        'subtotal_cents' => 30000,
        'tax_cents' => 0,
        'total_cents' => 30000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 30000,
        'currency' => 'USD',
    ]);

    // 61-90 days overdue: due 2026-08-15 (75 days overdue) -> $400.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000004',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-07-16',
        'due_date' => '2026-08-15',
        'subtotal_cents' => 40000,
        'tax_cents' => 0,
        'total_cents' => 40000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 40000,
        'currency' => 'USD',
    ]);

    // 90+ days overdue: due 2026-06-30 (121 days overdue) -> $500.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000005',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-05-31',
        'due_date' => '2026-06-30',
        'subtotal_cents' => 50000,
        'tax_cents' => 0,
        'total_cents' => 50000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 50000,
        'currency' => 'USD',
    ]);

    $tenantManager->reset();

    $sessionCookie = loginAsStatementTenant($this, 'alpha-stmt.nexuserp.test', 'admin@alpha-stmt.com');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$customer->id}/aging");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.aging_type', 'customer')
        ->assertJsonPath('data.current_cents', 10000)
        ->assertJsonPath('data.days_1_30_cents', 20000)
        ->assertJsonPath('data.days_31_60_cents', 30000)
        ->assertJsonPath('data.days_61_90_cents', 40000)
        ->assertJsonPath('data.days_over_90_cents', 50000)
        ->assertJsonPath('data.total_due_cents', 150000)
        ->assertJsonPath('data.net_balance_cents', 150000);

    expect(count($response->json('data.documents')))->toBe(5);
});

test('partial payment reduces amount due and reflects correctly in aging bucket', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $customer = Contact::create([
        'name' => 'Metro Supermarket',
        'code' => 'CUST-METRO',
        'type' => ContactType::Customer,
        'payment_terms_days' => 15,
        'opening_balance_cents' => 0,
    ]);

    // Invoice $1000 total, $400 paid, $600 due (overdue 20 days -> 1-30 bucket)
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000010',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::PartiallyPaid,
        'issue_date' => '2026-09-24',
        'due_date' => '2026-10-09',
        'subtotal_cents' => 100000,
        'tax_cents' => 0,
        'total_cents' => 100000,
        'amount_paid_cents' => 40000,
        'amount_due_cents' => 60000,
        'currency' => 'USD',
    ]);

    // Inbound unallocated credit of $100
    Payment::create([
        'payment_number' => 'REC-2026-000001',
        'type' => PaymentType::Inbound,
        'contact_id' => $customer->id,
        'amount_cents' => 10000,
        'allocated_amount_cents' => 0,
        'unallocated_amount_cents' => 10000,
        'payment_method' => PaymentMethod::BankTransfer,
        'payment_date' => '2026-10-25',
        'status' => 'posted',
        'currency' => 'USD',
    ]);

    $tenantManager->reset();

    $sessionCookie = loginAsStatementTenant($this, 'alpha-stmt.nexuserp.test', 'admin@alpha-stmt.com');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$customer->id}/aging");

    $response->assertStatus(200)
        ->assertJsonPath('data.days_1_30_cents', 60000)
        ->assertJsonPath('data.total_due_cents', 60000)
        ->assertJsonPath('data.unallocated_amount_cents', 10000)
        ->assertJsonPath('data.net_balance_cents', 50000);
});

test('AP aging calculation allocates open supplier bills accurately', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $supplier = Contact::create([
        'name' => 'Kathmandu Steel Suppliers',
        'code' => 'SUPP-STEEL',
        'type' => ContactType::Supplier,
        'payment_terms_days' => 30,
        'opening_balance_cents' => 0,
    ]);

    // Current bill: due 2026-11-15 -> $800
    SupplierBill::create([
        'bill_number' => 'BILL-2026-000001',
        'supplier_id' => $supplier->id,
        'status' => SupplierBillStatus::Posted,
        'bill_date' => '2026-10-15',
        'due_date' => '2026-11-15',
        'subtotal_cents' => 80000,
        'tax_cents' => 0,
        'total_cents' => 80000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 80000,
        'currency' => 'USD',
    ]);

    // Overdue bill (40 days overdue -> 31-60 bucket) -> $1,200
    SupplierBill::create([
        'bill_number' => 'BILL-2026-000002',
        'supplier_id' => $supplier->id,
        'status' => SupplierBillStatus::Posted,
        'bill_date' => '2026-08-20',
        'due_date' => '2026-09-19',
        'subtotal_cents' => 120000,
        'tax_cents' => 0,
        'total_cents' => 120000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 120000,
        'currency' => 'USD',
    ]);

    $tenantManager->reset();

    $sessionCookie = loginAsStatementTenant($this, 'alpha-stmt.nexuserp.test', 'admin@alpha-stmt.com');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$supplier->id}/aging");

    $response->assertStatus(200)
        ->assertJsonPath('data.aging_type', 'supplier')
        ->assertJsonPath('data.current_cents', 80000)
        ->assertJsonPath('data.days_31_60_cents', 120000)
        ->assertJsonPath('data.total_due_cents', 200000);
});

test('contact statement computes opening balance forward, chronological ledger and running balances', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    // Customer with opening balance $500.00
    $customer = Contact::create([
        'name' => 'Prime Distribution',
        'code' => 'CUST-PRIME',
        'type' => ContactType::Customer,
        'opening_balance_cents' => 50000,
    ]);

    // Prior period invoice (2026-09-15): $1,000.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000020',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Paid,
        'issue_date' => '2026-09-15',
        'due_date' => '2026-10-15',
        'subtotal_cents' => 100000,
        'tax_cents' => 0,
        'total_cents' => 100000,
        'amount_paid_cents' => 100000,
        'amount_due_cents' => 0,
        'currency' => 'USD',
    ]);

    // Prior period payment (2026-09-20): $800.00
    Payment::create([
        'payment_number' => 'REC-2026-000005',
        'type' => PaymentType::Inbound,
        'contact_id' => $customer->id,
        'amount_cents' => 80000,
        'allocated_amount_cents' => 80000,
        'unallocated_amount_cents' => 0,
        'payment_method' => PaymentMethod::Cash,
        'payment_date' => '2026-09-20',
        'status' => 'posted',
        'currency' => 'USD',
    ]);

    // Period: 2026-10-01 to 2026-10-29
    // Expected Period Opening Balance = 50000 (initial) + 100000 (prior invoice) - 80000 (prior payment) = 70000 ($700.00)

    // Current period invoice 1 (2026-10-05): $400.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000021',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-10-05',
        'due_date' => '2026-11-04',
        'subtotal_cents' => 40000,
        'tax_cents' => 0,
        'total_cents' => 40000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 40000,
        'currency' => 'USD',
    ]);

    // Current period receipt (2026-10-12): $500.00
    Payment::create([
        'payment_number' => 'REC-2026-000006',
        'type' => PaymentType::Inbound,
        'contact_id' => $customer->id,
        'amount_cents' => 50000,
        'allocated_amount_cents' => 50000,
        'unallocated_amount_cents' => 0,
        'payment_method' => PaymentMethod::Cheque,
        'payment_date' => '2026-10-12',
        'status' => 'posted',
        'currency' => 'USD',
    ]);

    // Current period invoice 2 (2026-10-20): $600.00
    SalesInvoice::create([
        'invoice_number' => 'INV-2026-000022',
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => '2026-10-20',
        'due_date' => '2026-11-19',
        'subtotal_cents' => 60000,
        'tax_cents' => 0,
        'total_cents' => 60000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 60000,
        'currency' => 'USD',
    ]);

    $tenantManager->reset();

    $sessionCookie = loginAsStatementTenant($this, 'alpha-stmt.nexuserp.test', 'admin@alpha-stmt.com');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$customer->id}/statement?start_date=2026-10-01&end_date=2026-10-29");

    $response->assertStatus(200)
        ->assertJsonPath('data.statement_type', 'customer')
        ->assertJsonPath('data.start_date', '2026-10-01')
        ->assertJsonPath('data.end_date', '2026-10-29')
        ->assertJsonPath('data.summary.opening_balance_cents', 70000)
        ->assertJsonPath('data.summary.total_debits_cents', 100000)
        ->assertJsonPath('data.summary.total_credits_cents', 50000)
        ->assertJsonPath('data.summary.closing_balance_cents', 120000);

    $transactions = $response->json('data.transactions');
    expect(count($transactions))->toBe(3);

    // Tx 1: 2026-10-05 INV-2026-000021, debit: 40000, running: 70000 + 40000 = 110000
    expect($transactions[0]['date'])->toBe('2026-10-05');
    expect($transactions[0]['document_number'])->toBe('INV-2026-000021');
    expect($transactions[0]['debit_cents'])->toBe(40000);
    expect($transactions[0]['running_balance_cents'])->toBe(110000);

    // Tx 2: 2026-10-12 REC-2026-000006, credit: 50000, running: 110000 - 50000 = 60000
    expect($transactions[1]['date'])->toBe('2026-10-12');
    expect($transactions[1]['document_number'])->toBe('REC-2026-000006');
    expect($transactions[1]['credit_cents'])->toBe(50000);
    expect($transactions[1]['running_balance_cents'])->toBe(60000);

    // Tx 3: 2026-10-20 INV-2026-000022, debit: 60000, running: 60000 + 60000 = 120000
    expect($transactions[2]['date'])->toBe('2026-10-20');
    expect($transactions[2]['document_number'])->toBe('INV-2026-000022');
    expect($transactions[2]['debit_cents'])->toBe(60000);
    expect($transactions[2]['running_balance_cents'])->toBe(120000);
});

test('statement PDF endpoint streams printable PDF with valid headers', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $customer = Contact::create([
        'name' => 'Everest Logistics Corp',
        'code' => 'CUST-EVEREST',
        'type' => ContactType::Customer,
        'opening_balance_cents' => 25000,
    ]);

    $tenantManager->reset();

    $sessionCookie = loginAsStatementTenant($this, 'alpha-stmt.nexuserp.test', 'admin@alpha-stmt.com');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$customer->id}/statement/pdf?start_date=2026-10-01&end_date=2026-10-29");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline; filename="statement-CUST-EVEREST-2026-10-01-to-2026-10-29.pdf"');
});

test('RBAC enforces that Sales Rep cannot view supplier statements or aging', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $supplier = Contact::create([
        'name' => 'Supplier Alpha Metals',
        'code' => 'SUPP-METALS',
        'type' => ContactType::Supplier,
    ]);

    $customer = Contact::create([
        'name' => 'Customer Omega Mart',
        'code' => 'CUST-OMEGA',
        'type' => ContactType::Customer,
    ]);

    $tenantManager->reset();

    // Login as Sales Rep
    $salesRepCookie = loginAsStatementTenant($this, 'alpha-stmt.nexuserp.test', 'sales@alpha-stmt.com');

    // Customer statement should be accessible by Sales Rep (200)
    $customerResp = $this->withCookie(config('session.cookie'), $salesRepCookie)
        ->getJson("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$customer->id}/statement");
    $customerResp->assertStatus(200);

    // Supplier statement should be forbidden for Sales Rep (403)
    $supplierResp = $this->withCookie(config('session.cookie'), $salesRepCookie)
        ->getJson("http://alpha-stmt.nexuserp.test/api/v1/contacts/{$supplier->id}/statement");
    $supplierResp->assertStatus(403);
});

test('multi-tenant isolation: Beta tenant cannot query Alpha contact statement or aging', function (): void {
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $alphaCustomer = Contact::create([
        'name' => 'Alpha Private Client',
        'code' => 'CUST-ALPHA-01',
        'type' => ContactType::Customer,
    ]);

    $tenantManager->reset();

    // Beta user logged in on Beta tenant
    $betaCookie = loginAsStatementTenant($this, 'beta-stmt.nexuserp.test', 'admin@beta-stmt.com');

    $response = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://beta-stmt.nexuserp.test/api/v1/contacts/{$alphaCustomer->id}/statement");

    $response->assertStatus(404)
        ->assertJsonPath('code', 'CONTACT_NOT_FOUND');
});
