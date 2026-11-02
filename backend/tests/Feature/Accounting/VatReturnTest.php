<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\FiscalPeriodStatus;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Purchasing\DTOs\CreateDebitNoteData;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\DebitNoteItemData;
use Modules\Purchasing\DTOs\SupplierBillItemData;
use Modules\Purchasing\Services\DebitNoteService;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Sales\DTOs\CreateCreditNoteData;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\DTOs\CreditNoteItemData;
use Modules\Sales\DTOs\SalesInvoiceItemData;
use Modules\Sales\Services\CreditNoteService;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

if (! function_exists('dropVatApiTestTenantDatabase')) {
    function dropVatApiTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_vat_api_alpha', 'tenant_vat_api_beta'], true)) {
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

function loginAsVatTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
{
    $testCase->flushSession();

    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertOk();

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

beforeEach(function (): void {
    /** @var TestCase $this */
    dropVatApiTestTenantDatabase('tenant_vat_api_alpha');
    dropVatApiTestTenantDatabase('tenant_vat_api_beta');

    Cache::flush();
    Domain::query()->delete();
    Tenant::query()->delete();
    Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);

    // Create Tenant Alpha
    $this->tenantAlpha = Tenant::on('central')->create([
        'id' => (string) Str::ulid(),
        'name' => 'VAT Tenant Alpha',
        'slug' => 'vatalpha',
        'database_name' => 'tenant_vat_api_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'id' => (string) Str::ulid(),
        'tenant_id' => $this->tenantAlpha->id,
        'host' => 'vatalpha.nexuserp.test',
    ]);

    // Create Tenant Beta
    $this->tenantBeta = Tenant::on('central')->create([
        'id' => (string) Str::ulid(),
        'name' => 'VAT Tenant Beta',
        'slug' => 'vatbeta',
        'database_name' => 'tenant_vat_api_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'id' => (string) Str::ulid(),
        'tenant_id' => $this->tenantBeta->id,
        'host' => 'vatbeta.nexuserp.test',
    ]);

    // Provision both databases
    ProvisionTenantDatabase::dispatchSync($this->tenantAlpha->id, 'owner@vatalpha.test', 'Alpha Owner');
    ProvisionTenantDatabase::dispatchSync($this->tenantBeta->id, 'owner@vatbeta.test', 'Beta Owner');

    // Seed Tenant Alpha users
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->tenantAlpha->id,
        slug: $this->tenantAlpha->slug,
        databaseName: $this->tenantAlpha->database_name,
    ));

    $this->accountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alice Accountant',
        'email' => 'alice@vatalpha.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountant->syncRoles([TenantRole::Accountant->value]);

    $this->warehouseManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Bob Warehouse',
        'email' => 'bob@vatalpha.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->warehouseManager->syncRoles([TenantRole::WarehouseManager->value]);

    // Create warehouse, products, and contacts
    $this->warehouse = Warehouse::create([
        'id' => (string) Str::ulid(),
        'code' => 'WH-01',
        'name' => 'Main Warehouse',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->productA = Product::create([
        'id' => (string) Str::ulid(),
        'sku' => 'PROD-A',
        'name' => 'Product A (13% VAT)',
        'cost_price_cents' => 50000,
        'selling_price_cents' => 100000,
        'is_active' => true,
    ]);

    $this->productZero = Product::create([
        'id' => (string) Str::ulid(),
        'sku' => 'PROD-ZERO',
        'name' => 'Product Zero (0% VAT)',
        'cost_price_cents' => 25000,
        'selling_price_cents' => 50000,
        'is_active' => true,
    ]);

    $this->customer = Contact::create([
        'id' => (string) Str::ulid(),
        'code' => 'CUST-001',
        'name' => 'Acme Client Corp',
        'type' => ContactType::Customer,
        'tax_id' => 'VAT-123456789',
        'is_active' => true,
    ]);

    $this->supplier = Contact::create([
        'id' => (string) Str::ulid(),
        'code' => 'SUPP-001',
        'name' => 'Global Vendor Ltd',
        'type' => ContactType::Supplier,
        'tax_id' => 'VAT-987654321',
        'is_active' => true,
    ]);

    // Fiscal period for 2026-01
    $this->periodJan = FiscalPeriod::create([
        'id' => (string) Str::ulid(),
        'name' => 'January 2026',
        'fiscal_year' => 2026,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => FiscalPeriodStatus::Open,
    ]);

    app(TenantManager::class)->reset();
});

afterEach(function (): void {
    app(TenantManager::class)->reset();
    dropVatApiTestTenantDatabase('tenant_vat_api_alpha');
    dropVatApiTestTenantDatabase('tenant_vat_api_beta');
});

test('accountant can calculate live vat return with rate breakdown, gl reconciliation, and document drilldown', function (): void {
    /** @var TestCase $this */
    app(TenantManager::class)->switchTo(new TenantContext(
        id: $this->tenantAlpha->id,
        slug: $this->tenantAlpha->slug,
        databaseName: $this->tenantAlpha->database_name,
    ));

    $salesService = app(SalesInvoiceService::class);
    $cnService = app(CreditNoteService::class);
    $billService = app(SupplierBillService::class);
    $dnService = app(DebitNoteService::class);
    $stockService = app(StockService::class);

    // Initial stock
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehouse->id,
        productId: (string) $this->productA->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 50000,
        sourceType: 'test',
        sourceId: 'init-stock-a',
    ));
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->warehouse->id,
        productId: (string) $this->productZero->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 25000,
        sourceType: 'test',
        sourceId: 'init-stock-zero',
    ));

    // 1. Post Sales Invoice: 1 line at 13% ($1,000 subtotal, $130 tax) + 1 line at 0% ($500 subtotal, $0 tax)
    $invoice = $salesService->create(new CreateSalesInvoiceData(
        customerId: $this->customer->id,
        issueDate: '2026-01-10',
        dueDate: '2026-01-25',
        warehouseId: $this->warehouse->id,
        items: [
            new SalesInvoiceItemData(
                productId: $this->productA->id,
                quantity: 1,
                unitPriceCents: 100000,
                taxRatePercent: 13,
                taxCategory: 'standard',
            ),
            new SalesInvoiceItemData(
                productId: $this->productZero->id,
                quantity: 1,
                unitPriceCents: 50000,
                taxRatePercent: 0,
                taxCategory: 'zero_rated',
            ),
        ],
    ), $this->accountant->id);
    $salesService->post($invoice->id, $this->accountant->id);

    // 2. Post Customer Credit Note: return $200 of Product A at 13% ($26 tax)
    $cn = $cnService->create(new CreateCreditNoteData(
        customerId: $this->customer->id,
        warehouseId: $this->warehouse->id,
        issueDate: '2026-01-15',
        salesInvoiceId: $invoice->id,
        restock: false,
        reason: 'Defective unit',
        items: [
            new CreditNoteItemData(
                productId: $this->productA->id,
                quantity: 1,
                unitPriceCents: 20000,
                taxRatePercent: 13,
                taxCategory: 'standard',
            ),
        ],
    ), $this->accountant->id);
    $cnService->post($cn->id, $this->accountant->id);

    // 3. Post Supplier Bill: $600 at 13% ($78 tax)
    $bill = $billService->create(new CreateSupplierBillData(
        supplierId: $this->supplier->id,
        billDate: '2026-01-12',
        dueDate: '2026-01-27',
        supplierInvoiceNumber: 'VEND-991',
        items: [
            new SupplierBillItemData(
                description: 'Raw Materials Batch 1',
                quantity: 1,
                unitCostCents: 60000,
                taxRatePercent: 13,
                productId: $this->productA->id,
            ),
        ],
    ), $this->accountant->id);
    $billService->post($bill->id, $this->accountant->id);

    // 4. Post Supplier Debit Note: $100 return at 13% ($13 tax)
    $dn = $dnService->create(new CreateDebitNoteData(
        supplierId: $this->supplier->id,
        warehouseId: $this->warehouse->id,
        issueDate: '2026-01-18',
        supplierBillId: $bill->id,
        reason: 'Overcharged item',
        items: [
            new DebitNoteItemData(
                productId: $this->productA->id,
                quantity: 1,
                unitCostCents: 10000,
                taxRatePercent: 13,
                taxCategory: 'standard',
            ),
        ],
    ), $this->accountant->id);
    $dnService->post($dn->id, $this->accountant->id);

    app(TenantManager::class)->reset();

    $cookie = loginAsVatTenant($this, 'vatalpha.nexuserp.test', 'alice@vatalpha.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-return/calculate?from_date=2026-01-01&to_date=2026-01-31');

    $response->assertOk();
    $data = $response->json('data');

    // Net Output VAT:
    // Sales @ 13%: 100,000 subtotal, 13,000 tax
    // Credit Note @ 13%: 20,000 subtotal, 2,600 tax
    // Net Sales @ 13%: 80,000 cents. Net Output Tax @ 13%: 10,400 cents.
    // Sales @ 0%: 50,000 subtotal, 0 tax.
    // Total Net Sales: 130,000 cents.
    // Total Output VAT: 10,400 cents ($104.00).
    expect($data['summary']['total_sales_taxable_cents'])->toBe(130000);
    expect($data['summary']['total_output_vat_cents'])->toBe(10400);

    // Net Input VAT:
    // Bill @ 13%: 60,000 subtotal, 7,800 tax
    // Debit Note @ 13%: 10,000 subtotal, 1,300 tax
    // Net Purchases @ 13%: 50,000 cents. Net Input Tax @ 13%: 6,500 cents.
    // Total Net Purchases: 50,000 cents.
    // Total Input VAT: 6,500 cents ($65.00).
    expect($data['summary']['total_purchases_taxable_cents'])->toBe(50000);
    expect($data['summary']['total_input_vat_cents'])->toBe(6500);

    // Net VAT Position:
    // 10,400 - 6,500 = 3,900 cents ($39.00 payable)
    expect($data['summary']['net_vat_payable_cents'])->toBe(3900);

    // Rate breakdown
    $breakdown = collect($data['rate_breakdown'])->keyBy('tax_rate_percent');
    expect($breakdown->has(13))->toBeTrue();
    expect($breakdown[13]['sales_taxable_cents'])->toBe(80000);
    expect($breakdown[13]['output_vat_cents'])->toBe(10400);
    expect($breakdown[13]['purchases_taxable_cents'])->toBe(50000);
    expect($breakdown[13]['input_vat_cents'])->toBe(6500);
    expect($breakdown[13]['net_vat_cents'])->toBe(3900);

    expect($breakdown->has(0))->toBeTrue();
    expect($breakdown[0]['sales_taxable_cents'])->toBe(50000);
    expect($breakdown[0]['output_vat_cents'])->toBe(0);

    // General Ledger Reconciliation:
    // Account 2120 credit = 13,000, debit = 2,600 -> net credit 10,400
    // Account 1160 debit = 7,800, credit = 1,300 -> net debit 6,500
    // GL Net VAT = 10,400 - 6,500 = 3,900
    expect($data['ledger_reconciliation']['is_reconciled'])->toBeTrue();
    expect($data['ledger_reconciliation']['gl_output_vat_cents'])->toBe(10400);
    expect($data['ledger_reconciliation']['gl_input_vat_cents'])->toBe(6500);
    expect($data['ledger_reconciliation']['output_vat_discrepancy_cents'])->toBe(0);
    expect($data['ledger_reconciliation']['input_vat_discrepancy_cents'])->toBe(0);
    expect($data['ledger_reconciliation']['net_vat_discrepancy_cents'])->toBe(0);

    // Document Drilldown: 4 participating documents
    expect(count($data['documents']))->toBe(4);
    $types = collect($data['documents'])->pluck('document_type')->all();
    expect($types)->toContain('sales_invoice', 'credit_note', 'supplier_bill', 'debit_note');

    // Customer details populated
    $invoiceDoc = collect($data['documents'])->firstWhere('document_type', 'sales_invoice');
    expect($invoiceDoc['contact_name'])->toBe('Acme Client Corp');
    expect($invoiceDoc['contact_tax_id'])->toBe('VAT-123456789');
    expect($invoiceDoc['direction'])->toBe('output');
});

test('calculating by fiscal_period_id automatically sets date range and includes period metadata', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsVatTenant($this, 'vatalpha.nexuserp.test', 'alice@vatalpha.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson("http://vatalpha.nexuserp.test/api/v1/accounting/vat-return/calculate?fiscal_period_id={$this->periodJan->id}");

    $response->assertOk();
    $data = $response->json('data');

    expect($data['from_date'])->toBe('2026-01-01');
    expect($data['to_date'])->toBe('2026-01-31');
    expect($data['fiscal_period']['id'])->toBe($this->periodJan->id);
    expect($data['fiscal_period']['name'])->toBe('January 2026');
});

test('accountant can file a vat return and filed return is immutable', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsVatTenant($this, 'vatalpha.nexuserp.test', 'alice@vatalpha.test');

    // File return
    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-returns', [
            'fiscal_period_id' => $this->periodJan->id,
            'notes' => 'Filed with inland revenue portal ref #IR-88219',
        ]);

    $response->assertCreated();
    $return = $response->json('data');

    expect($return['return_number'])->toStartWith('VAT-');
    expect($return['status'])->toBe('filed');
    expect($return['from_date'])->toBe('2026-01-01');
    expect($return['to_date'])->toBe('2026-01-31');
    expect($return['notes'])->toBe('Filed with inland revenue portal ref #IR-88219');
    expect($return['is_reconciled'])->toBeTrue();

    // Listing returns includes the newly filed return
    $listResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-returns');
    $listResponse->assertOk();
    expect($listResponse->json('meta.total'))->toBe(1);

    // Retrieve single filed return
    $showResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson("http://vatalpha.nexuserp.test/api/v1/accounting/vat-returns/{$return['id']}");
    $showResponse->assertOk();
    expect($showResponse->json('data.id'))->toBe($return['id']);

    // Attempting to delete a filed VAT return fails
    $deleteResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->deleteJson("http://vatalpha.nexuserp.test/api/v1/accounting/vat-returns/{$return['id']}");
    $deleteResponse->assertBadRequest();
    $deleteResponse->assertJsonPath('code', 'FILED_VAT_RETURN_CANNOT_BE_DELETED');

    // Attempting to file duplicate return for same period fails
    $dupResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-returns', [
            'fiscal_period_id' => $this->periodJan->id,
        ]);
    $dupResponse->assertBadRequest();
    $dupResponse->assertJsonPath('code', 'VAT_RETURN_ALREADY_FILED');
});

test('unauthorized user cannot calculate or file vat return', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsVatTenant($this, 'vatalpha.nexuserp.test', 'bob@vatalpha.test');

    $calcResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-return/calculate?from_date=2026-01-01&to_date=2026-01-31');
    $calcResponse->assertForbidden();

    $fileResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-returns', [
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
        ]);
    $fileResponse->assertForbidden();
});

test('multi-tenant data isolation: vat return only includes tenant documents', function (): void {
    /** @var TestCase $this */
    // Create an invoice in Tenant Beta
    app(TenantManager::class)->switchTo(new TenantContext(
        id: $this->tenantBeta->id,
        slug: $this->tenantBeta->slug,
        databaseName: $this->tenantBeta->database_name,
    ));

    $betaAccountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Beta Accountant',
        'email' => 'beta@vatbeta.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $betaAccountant->syncRoles([TenantRole::Accountant->value]);

    $betaWarehouse = Warehouse::create([
        'id' => (string) Str::ulid(),
        'code' => 'WH-B1',
        'name' => 'Beta Warehouse',
        'is_default' => true,
        'is_active' => true,
    ]);

    $betaProduct = Product::create([
        'id' => (string) Str::ulid(),
        'sku' => 'BETA-PROD',
        'name' => 'Beta Product',
        'cost_price_cents' => 10000,
        'selling_price_cents' => 20000,
        'is_active' => true,
    ]);

    $betaCustomer = Contact::create([
        'id' => (string) Str::ulid(),
        'code' => 'BETA-CUST-1',
        'name' => 'Beta Customer',
        'type' => ContactType::Customer,
        'is_active' => true,
    ]);

    app(StockService::class)->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $betaWarehouse->id,
        productId: (string) $betaProduct->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 10,
        unitCostCents: 10000,
        sourceType: 'test',
        sourceId: 'init-stock-beta',
    ));

    $betaInvoice = app(SalesInvoiceService::class)->create(new CreateSalesInvoiceData(
        customerId: $betaCustomer->id,
        issueDate: '2026-01-10',
        dueDate: '2026-01-25',
        warehouseId: $betaWarehouse->id,
        items: [
            new SalesInvoiceItemData(
                productId: $betaProduct->id,
                quantity: 1,
                unitPriceCents: 999900,
                taxRatePercent: 13,
            ),
        ],
    ), $betaAccountant->id);
    app(SalesInvoiceService::class)->post($betaInvoice->id, $betaAccountant->id);

    app(TenantManager::class)->reset();

    // Query VAT Return in Tenant Alpha
    $cookie = loginAsVatTenant($this, 'vatalpha.nexuserp.test', 'alice@vatalpha.test');
    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://vatalpha.nexuserp.test/api/v1/accounting/vat-return/calculate?from_date=2026-01-01&to_date=2026-01-31');

    $response->assertOk();
    $data = $response->json('data');

    // Tenant Alpha has zero documents for this period so totals must be 0
    expect($data['summary']['total_sales_taxable_cents'])->toBe(0);
    expect($data['summary']['total_output_vat_cents'])->toBe(0);
    expect($data['documents'])->toBeEmpty();
});

test('accountant can export vat return to pdf', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsVatTenant($this, 'vatalpha.nexuserp.test', 'alice@vatalpha.test');

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->get('http://vatalpha.nexuserp.test/api/v1/accounting/vat-return/pdf?from_date=2026-01-01&to_date=2026-01-31');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($response->getContent(), '%PDF-'))->toBeTrue();
});
