<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Payments\Enums\PaymentMethod;
use Modules\Payments\Enums\PaymentStatus;
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
use Tests\TestCase;

function dropPaymentTestTenantDatabase(string $dbName): void
{
    $config = config('database.connections.central');
    if (is_array($config)) {
        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsPaymentTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
{
    $testCase->flushSession();

    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    if ($cookie === null) {
        throw new \RuntimeException('Session cookie not found in response');
    }

    return $cookie->getValue();
}

final class PaymentEngineTest extends TestCase
{
    private Tenant $alphaTenant;

    private Tenant $betaTenant;

    private TenantUser $alphaOwner;

    private TenantUser $alphaSalesRep;

    private TenantUser $alphaWarehouseMgr;

    private Contact $customerA;

    private Contact $supplierA;

    private string $alphaOwnerCookie;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-29 12:00:00');
        app('cache')->flush();

        dropPaymentTestTenantDatabase('tenant_payment_alpha');
        dropPaymentTestTenantDatabase('tenant_payment_beta');

        Domain::query()->whereIn('host', ['alpha-pay.nexuserp.test', 'beta-pay.nexuserp.test'])->delete();
        Tenant::query()->whereIn('slug', ['alpha-pay', 'beta-pay'])->delete();

        Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--realpath' => false,
            '--force' => true,
        ]);

        // Setup Tenant Alpha
        $alphaId = (string) Str::ulid();
        $this->alphaTenant = Tenant::on('central')->create([
            'id' => $alphaId,
            'name' => 'Alpha Payment Corp',
            'slug' => 'alpha-pay',
            'database_name' => 'tenant_payment_alpha',
            'status' => 'provisioning',
        ]);
        Domain::on('central')->create([
            'tenant_id' => $alphaId,
            'host' => 'alpha-pay.nexuserp.test',
            'is_primary' => true,
        ]);
        ProvisionTenantDatabase::dispatchSync(
            tenantId: $alphaId,
            ownerEmail: 'owner@alpha-pay.nexuserp.test',
            ownerName: 'Alpha Owner',
        );

        // Setup Tenant Beta
        $betaId = (string) Str::ulid();
        $this->betaTenant = Tenant::on('central')->create([
            'id' => $betaId,
            'name' => 'Beta Payment Corp',
            'slug' => 'beta-pay',
            'database_name' => 'tenant_payment_beta',
            'status' => 'provisioning',
        ]);
        Domain::on('central')->create([
            'tenant_id' => $betaId,
            'host' => 'beta-pay.nexuserp.test',
            'is_primary' => true,
        ]);
        ProvisionTenantDatabase::dispatchSync(
            tenantId: $betaId,
            ownerEmail: 'owner@beta-pay.nexuserp.test',
            ownerName: 'Beta Owner',
        );

        // Seed Alpha records
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $this->alphaOwner = TenantUser::where('email', 'owner@alpha-pay.nexuserp.test')->firstOrFail();
        $this->alphaOwner->update([
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);

        $this->alphaSalesRep = TenantUser::create([
            'id' => (string) Str::ulid(),
            'name' => 'Alpha Sales Rep',
            'email' => 'sales@alpha-pay.nexuserp.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->alphaSalesRep->assignRole(TenantRole::SalesRep->value);

        $this->alphaWarehouseMgr = TenantUser::create([
            'id' => (string) Str::ulid(),
            'name' => 'Alpha Warehouse Mgr',
            'email' => 'warehouse@alpha-pay.nexuserp.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->alphaWarehouseMgr->assignRole(TenantRole::WarehouseManager->value);

        $this->customerA = Contact::create([
            'code' => 'CUST-000001',
            'type' => ContactType::Customer,
            'name' => 'Everest Retailers Ltd',
            'tax_id' => 'PAN-99887766',
            'email' => 'billing@everest.com',
            'payment_terms_days' => 30,
            'credit_limit_cents' => 50000000,
            'opening_balance_cents' => 0,
            'is_active' => true,
        ]);

        $this->supplierA = Contact::create([
            'code' => 'SUPP-000001',
            'type' => ContactType::Supplier,
            'name' => 'Kathmandu Packaging Ltd',
            'tax_id' => 'PAN-11223344',
            'email' => 'accounts@ktmpack.com',
            'payment_terms_days' => 30,
            'credit_limit_cents' => 50000000,
            'opening_balance_cents' => 0,
            'is_active' => true,
        ]);

        $this->alphaOwnerCookie = loginAsPaymentTenant($this, 'alpha-pay.nexuserp.test', 'owner@alpha-pay.nexuserp.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        dropPaymentTestTenantDatabase('tenant_payment_alpha');
        dropPaymentTestTenantDatabase('tenant_payment_beta');
        parent::tearDown();
    }

    public function test_customer_receipt_creation_assigns_gap_free_numbering_and_tracks_unallocated_cents(): void
    {
        $response = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->postJson('http://alpha-pay.nexuserp.test/api/v1/payments', [
                'type' => 'inbound',
                'contact_id' => $this->customerA->id,
                'amount_cents' => 50000, // $500.00
                'payment_method' => 'bank_transfer',
                'payment_date' => '2026-10-29',
                'reference_number' => 'WIRE-998811',
                'notes' => 'Advance customer payment',
                'post_immediately' => true,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payment_number', 'REC-2026-000001');
        $response->assertJsonPath('data.type', 'inbound');
        $response->assertJsonPath('data.amount_cents', 50000);
        $response->assertJsonPath('data.allocated_amount_cents', 0);
        $response->assertJsonPath('data.unallocated_amount_cents', 50000);
        $response->assertJsonPath('data.status', 'posted');

        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $this->assertDatabaseHas('payments', [
            'payment_number' => 'REC-2026-000001',
            'type' => 'inbound',
            'contact_id' => $this->customerA->id,
            'amount_cents' => 50000,
            'allocated_amount_cents' => 0,
            'unallocated_amount_cents' => 50000,
            'status' => 'posted',
        ], 'tenant');
    }

    public function test_allocating_customer_receipt_to_sales_invoice_updates_balances_and_invoice_status(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        // Create a posted sales invoice for $300.00 (30000 cents)
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-2026-000001',
            'customer_id' => $this->customerA->id,
            'status' => SalesInvoiceStatus::Posted,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'currency' => 'USD',
            'subtotal_cents' => 30000,
            'tax_cents' => 0,
            'total_cents' => 30000,
            'amount_paid_cents' => 0,
            'amount_due_cents' => 30000,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        // Create posted customer receipt for $500.00
        /** @var Payment $receipt */
        $receipt = Payment::create([
            'payment_number' => 'REC-2026-000002',
            'type' => PaymentType::Inbound,
            'contact_id' => $this->customerA->id,
            'amount_cents' => 50000,
            'allocated_amount_cents' => 0,
            'unallocated_amount_cents' => 50000,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::BankTransfer,
            'payment_date' => '2026-10-29',
            'status' => PaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        // Partial allocation of $200.00 (20000 cents)
        $allocResponse1 = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->postJson("http://alpha-pay.nexuserp.test/api/v1/payments/{$receipt->id}/allocate", [
                'allocations' => [
                    [
                        'payable_type' => 'invoice',
                        'payable_id' => $invoice->id,
                        'amount_cents' => 20000,
                    ],
                ],
            ]);

        $allocResponse1->assertOk();
        $allocResponse1->assertJsonPath('data.allocated_amount_cents', 20000);
        $allocResponse1->assertJsonPath('data.unallocated_amount_cents', 30000);

        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $invoice->refresh();
        $this->assertSame(20000, $invoice->amount_paid_cents);
        $this->assertSame(10000, $invoice->amount_due_cents);
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->status);

        // Allocate remaining $100.00 (10000 cents) to close the invoice
        $allocResponse2 = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->postJson("http://alpha-pay.nexuserp.test/api/v1/payments/{$receipt->id}/allocate", [
                'allocations' => [
                    [
                        'payable_type' => 'invoice',
                        'payable_id' => $invoice->id,
                        'amount_cents' => 10000,
                    ],
                ],
            ]);

        $allocResponse2->assertOk();
        $allocResponse2->assertJsonPath('data.allocated_amount_cents', 30000);
        $allocResponse2->assertJsonPath('data.unallocated_amount_cents', 20000);

        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $invoice->refresh();
        $this->assertSame(30000, $invoice->amount_paid_cents);
        $this->assertSame(0, $invoice->amount_due_cents);
        $this->assertSame(SalesInvoiceStatus::Paid, $invoice->status);
    }

    public function test_supplier_payment_can_allocate_across_multiple_bills(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $billA = SupplierBill::create([
            'bill_number' => 'BILL-2026-000001',
            'supplier_id' => $this->supplierA->id,
            'purchase_order_id' => null,
            'status' => SupplierBillStatus::Posted,
            'currency' => 'USD',
            'bill_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'subtotal_cents' => 40000,
            'tax_cents' => 0,
            'total_cents' => 40000,
            'amount_paid_cents' => 0,
            'amount_due_cents' => 40000,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $billB = SupplierBill::create([
            'bill_number' => 'BILL-2026-000002',
            'supplier_id' => $this->supplierA->id,
            'purchase_order_id' => null,
            'status' => SupplierBillStatus::Posted,
            'currency' => 'USD',
            'bill_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'subtotal_cents' => 60000,
            'tax_cents' => 0,
            'total_cents' => 60000,
            'amount_paid_cents' => 0,
            'amount_due_cents' => 60000,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $response = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->postJson('http://alpha-pay.nexuserp.test/api/v1/payments', [
                'type' => 'outbound',
                'contact_id' => $this->supplierA->id,
                'amount_cents' => 100000, // $1000.00
                'payment_method' => 'cheque',
                'payment_date' => '2026-10-29',
                'reference_number' => 'CHQ-554433',
                'post_immediately' => true,
                'allocations' => [
                    [
                        'payable_type' => 'bill',
                        'payable_id' => $billA->id,
                        'amount_cents' => 40000,
                    ],
                    [
                        'payable_type' => 'bill',
                        'payable_id' => $billB->id,
                        'amount_cents' => 60000,
                    ],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payment_number', 'PAY-2026-000001');
        $response->assertJsonPath('data.type', 'outbound');
        $response->assertJsonPath('data.amount_cents', 100000);
        $response->assertJsonPath('data.allocated_amount_cents', 100000);
        $response->assertJsonPath('data.unallocated_amount_cents', 0);

        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $billA->refresh();
        $billB->refresh();
        $this->assertSame(SupplierBillStatus::Paid, $billA->status);
        $this->assertSame(0, $billA->amount_due_cents);
        $this->assertSame(SupplierBillStatus::Paid, $billB->status);
        $this->assertSame(0, $billB->amount_due_cents);
    }

    public function test_over_allocation_is_rejected_with_422_error(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-2026-000002',
            'customer_id' => $this->customerA->id,
            'status' => SalesInvoiceStatus::Posted,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'currency' => 'USD',
            'subtotal_cents' => 50000,
            'tax_cents' => 0,
            'total_cents' => 50000,
            'amount_paid_cents' => 0,
            'amount_due_cents' => 50000,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $receipt = Payment::create([
            'payment_number' => 'REC-2026-000003',
            'type' => PaymentType::Inbound,
            'contact_id' => $this->customerA->id,
            'amount_cents' => 20000,
            'allocated_amount_cents' => 0,
            'unallocated_amount_cents' => 20000,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::Cash,
            'payment_date' => '2026-10-29',
            'status' => PaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $response = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->postJson("http://alpha-pay.nexuserp.test/api/v1/payments/{$receipt->id}/allocate", [
                'allocations' => [
                    [
                        'payable_type' => 'invoice',
                        'payable_id' => $invoice->id,
                        'amount_cents' => 30000,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'EXCESS_ALLOCATION');
    }

    public function test_payable_type_mismatch_is_rejected(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        $receipt = Payment::create([
            'payment_number' => 'REC-2026-000004',
            'type' => PaymentType::Inbound,
            'contact_id' => $this->customerA->id,
            'amount_cents' => 20000,
            'allocated_amount_cents' => 0,
            'unallocated_amount_cents' => 20000,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::Cash,
            'payment_date' => '2026-10-29',
            'status' => PaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $response = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->postJson("http://alpha-pay.nexuserp.test/api/v1/payments/{$receipt->id}/allocate", [
                'allocations' => [
                    [
                        'payable_type' => 'bill',
                        'payable_id' => '01HNONEXISTENT0000000000001',
                        'amount_cents' => 10000,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'PAYABLE_TYPE_MISMATCH');
    }

    public function test_unallocated_credit_query_returns_accurate_customer_balance(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        Payment::create([
            'payment_number' => 'REC-2026-000005',
            'type' => PaymentType::Inbound,
            'contact_id' => $this->customerA->id,
            'amount_cents' => 30000,
            'allocated_amount_cents' => 10000,
            'unallocated_amount_cents' => 20000,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::BankTransfer,
            'payment_date' => '2026-10-29',
            'status' => PaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        Payment::create([
            'payment_number' => 'REC-2026-000006',
            'type' => PaymentType::Inbound,
            'contact_id' => $this->customerA->id,
            'amount_cents' => 15000,
            'allocated_amount_cents' => 0,
            'unallocated_amount_cents' => 15000,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::Cash,
            'payment_date' => '2026-10-29',
            'status' => PaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $response = $this->withCookie(config('session.cookie'), $this->alphaOwnerCookie)
            ->getJson("http://alpha-pay.nexuserp.test/api/v1/payments/contacts/{$this->customerA->id}/unallocated-credit?type=inbound");

        $response->assertOk();
        $response->assertJsonPath('data.unallocated_credit_cents', 35000);
    }

    public function test_rbac_sales_rep_can_record_receipt_but_not_supplier_payment_and_warehouse_rejected(): void
    {
        $salesRepCookie = loginAsPaymentTenant($this, 'alpha-pay.nexuserp.test', 'sales@alpha-pay.nexuserp.test');

        // Sales rep CAN create inbound customer receipt
        $repReceipt = $this->withCookie(config('session.cookie'), $salesRepCookie)
            ->postJson('http://alpha-pay.nexuserp.test/api/v1/payments', [
                'type' => 'inbound',
                'contact_id' => $this->customerA->id,
                'amount_cents' => 10000,
                'payment_method' => 'cash',
                'payment_date' => '2026-10-29',
            ]);
        $repReceipt->assertCreated();

        // Sales rep CANNOT create outbound supplier payment
        $repOutbound = $this->withCookie(config('session.cookie'), $salesRepCookie)
            ->postJson('http://alpha-pay.nexuserp.test/api/v1/payments', [
                'type' => 'outbound',
                'contact_id' => $this->supplierA->id,
                'amount_cents' => 10000,
                'payment_method' => 'cash',
                'payment_date' => '2026-10-29',
            ]);
        $repOutbound->assertForbidden();

        // Now login as Warehouse Manager
        $warehouseCookie = loginAsPaymentTenant($this, 'alpha-pay.nexuserp.test', 'warehouse@alpha-pay.nexuserp.test');

        // Warehouse manager CANNOT create payments
        $whReceipt = $this->withCookie(config('session.cookie'), $warehouseCookie)
            ->postJson('http://alpha-pay.nexuserp.test/api/v1/payments', [
                'type' => 'inbound',
                'contact_id' => $this->customerA->id,
                'amount_cents' => 10000,
                'payment_method' => 'cash',
                'payment_date' => '2026-10-29',
            ]);
        $whReceipt->assertForbidden();
    }

    public function test_multi_tenant_isolation_hides_payments_across_tenants(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->alphaTenant->id,
            slug: $this->alphaTenant->slug,
            databaseName: $this->alphaTenant->database_name,
        ));

        /** @var Payment $alphaPayment */
        $alphaPayment = Payment::create([
            'payment_number' => 'REC-2026-000099',
            'type' => PaymentType::Inbound,
            'contact_id' => $this->customerA->id,
            'amount_cents' => 50000,
            'allocated_amount_cents' => 0,
            'unallocated_amount_cents' => 50000,
            'currency' => 'USD',
            'payment_method' => PaymentMethod::Cash,
            'payment_date' => '2026-10-29',
            'status' => PaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => $this->alphaOwner->id,
        ]);

        $tenantManager->switchTo(new TenantContext(
            id: $this->betaTenant->id,
            slug: $this->betaTenant->slug,
            databaseName: $this->betaTenant->database_name,
        ));

        $betaOwner = TenantUser::where('email', 'owner@beta-pay.nexuserp.test')->firstOrFail();
        $betaOwner->update([
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);

        $betaCookie = loginAsPaymentTenant($this, 'beta-pay.nexuserp.test', 'owner@beta-pay.nexuserp.test');

        $betaResponse = $this->withCookie(config('session.cookie'), $betaCookie)
            ->getJson("http://beta-pay.nexuserp.test/api/v1/payments/{$alphaPayment->id}");
        $betaResponse->assertNotFound();
    }
}
