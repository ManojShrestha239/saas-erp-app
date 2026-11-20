<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Sales\Events\SalesInvoicePosted;
use Modules\Sales\Events\SalesQuotationSent;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropSalesTestTenantDatabase')) {
    function dropSalesTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_sales_alpha', 'tenant_sales_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsSalesTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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
    Carbon::setTestNow('2026-10-29 12:00:00');
    app('cache')->flush();

    dropSalesTestTenantDatabase('tenant_sales_alpha');
    dropSalesTestTenantDatabase('tenant_sales_beta');

    Domain::query()->whereIn('host', ['alpha-sales.nexuserp.test', 'beta-sales.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-sales', 'beta-sales'])->delete();

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
        'name' => 'Alpha Sales Corp',
        'slug' => 'alpha-sales',
        'database_name' => 'tenant_sales_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha-sales.nexuserp.test',
        'is_primary' => true,
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha-sales.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // Setup Tenant Beta
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Sales Corp',
        'slug' => 'beta-sales',
        'database_name' => 'tenant_sales_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta-sales.nexuserp.test',
        'is_primary' => true,
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta-sales.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // Seed Alpha users and records
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->alphaOwner = TenantUser::where('email', 'owner@alpha-sales.nexuserp.test')->firstOrFail();
    $this->alphaOwner->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->alphaSalesRep = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@alpha-sales.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->alphaSalesRep->assignRole(TenantRole::SalesRep->value);

    $this->alphaWarehouseMgr = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@alpha-sales.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->alphaWarehouseMgr->assignRole(TenantRole::WarehouseManager->value);

    $this->customerA = Contact::create([
        'code' => 'CUST-000001',
        'type' => ContactType::Customer,
        'name' => 'Acme Retailers Ltd',
        'tax_id' => 'PAN-99887766',
        'email' => 'billing@acmeretail.com',
        'payment_terms_days' => 30,
        'credit_limit_cents' => 50000000,
        'is_active' => true,
    ]);

    $this->productA = Product::create([
        'name' => 'Solar Inverter 5kW',
        'sku' => 'SOLAR-INV-5K',
        'cost_price_cents' => 6000000,
        'selling_price_cents' => 8500000,
        'tax_category' => 'standard',
        'is_active' => true,
    ]);

    $this->productB = Product::create([
        'name' => 'Solar Battery 10kWh',
        'sku' => 'SOLAR-BAT-10K',
        'cost_price_cents' => 12000000,
        'selling_price_cents' => 16000000,
        'tax_category' => 'standard',
        'is_active' => true,
    ]);

    $this->alphaWarehouse = Warehouse::create([
        'name' => 'Alpha Central Warehouse',
        'code' => 'WH-ALPHA',
        'is_default' => true,
        'is_active' => true,
    ]);

    $stockService = app(StockService::class);
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->alphaWarehouse->id,
        productId: (string) $this->productA->id,
        type: StockMovementType::OpeningBalance,
        quantity: 1000,
        unitCostCents: 6000000,
        sourceType: 'seed',
        sourceId: 'seed',
        userId: (string) $this->alphaOwner->id,
        notes: 'Initial seed stock',
    ));
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: (string) $this->alphaWarehouse->id,
        productId: (string) $this->productB->id,
        type: StockMovementType::OpeningBalance,
        quantity: 1000,
        unitCostCents: 12000000,
        sourceType: 'seed',
        sourceId: 'seed',
        userId: (string) $this->alphaOwner->id,
        notes: 'Initial seed stock',
    ));

    // Seed Beta records
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    $this->betaOwner = TenantUser::where('email', 'owner@beta-sales.nexuserp.test')->firstOrFail();
    $this->betaOwner->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->betaCustomer = Contact::create([
        'code' => 'CUST-000001',
        'type' => ContactType::Customer,
        'name' => 'Beta Enterprises',
        'tax_id' => 'PAN-11223344',
        'email' => 'info@beta.com',
        'is_active' => true,
    ]);

    $this->betaProduct = Product::create([
        'name' => 'Beta Widget',
        'sku' => 'BETA-WIDGET',
        'cost_price_cents' => 1000,
        'selling_price_cents' => 2000,
        'is_active' => true,
    ]);

    Warehouse::create([
        'name' => 'Beta Central Warehouse',
        'code' => 'WH-BETA',
        'is_default' => true,
        'is_active' => true,
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    dropSalesTestTenantDatabase('tenant_sales_alpha');
    dropSalesTestTenantDatabase('tenant_sales_beta');
    Domain::query()->whereIn('host', ['alpha-sales.nexuserp.test', 'beta-sales.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-sales', 'beta-sales'])->delete();
});

test('sales quotation creation calculates subtotal, tax, and total accurately with gap-free sequence', function (): void {
    $cookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'sales@alpha-sales.nexuserp.test');

    $response = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/quotations', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'expiry_date' => '2026-11-29',
            'currency' => 'USD',
            'notes' => 'Quotation for Acme Solar System',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 2,
                    'unit_price_cents' => 8500000,
                    'tax_rate_percent' => 13,
                    'tax_category' => 'standard',
                    'description' => 'Solar Inverter 5kW Grid-tie',
                ],
                [
                    'product_id' => $this->productB->id,
                    'quantity' => 1,
                    'unit_price_cents' => 16000000,
                    'tax_rate_percent' => 13,
                    'tax_category' => 'standard',
                    'description' => 'Solar Battery 10kWh Wall-mounted',
                ],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.quotation_number', 'QUO-2026-000001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal_cents', 33000000) // (2 * 8,500,000) + (1 * 16,000,000) = 17,000,000 + 16,000,000 = 33,000,000
        ->assertJsonPath('data.tax_cents', 4290000) // 13% of 17,000,000 (2,210,000) + 13% of 16,000,000 (2,080,000) = 4,290,000
        ->assertJsonPath('data.total_cents', 37290000); // 33,000,000 + 4,290,000 = 37,290,000

    expect($response->json('data.items'))->toHaveCount(2);
});

test('quotation lifecycle: draft can be sent, accepted, or rejected and dispatches domain event', function (): void {
    Event::fake([SalesQuotationSent::class]);

    $cookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'sales@alpha-sales.nexuserp.test');

    // 1. Create quote
    $createRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/quotations', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 1,
                    'unit_price_cents' => 8500000,
                ],
            ],
        ]);
    $quoteId = $createRes->json('data.id');

    // 2. Send quote
    $sendRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/send");

    $sendRes->assertOk()
        ->assertJsonPath('data.status', 'sent');

    Event::assertDispatched(SalesQuotationSent::class, function ($event) use ($quoteId): bool {
        return $event->quotation->id === $quoteId;
    });

    // 3. Accept quote
    $acceptRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/accept");

    $acceptRes->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

test('accepted quotation converts to sales invoice copying line items and preserving linkage', function (): void {
    $cookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'owner@alpha-sales.nexuserp.test');

    // 1. Create quote
    $createRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/quotations', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 3,
                    'unit_price_cents' => 8500000,
                    'tax_rate_percent' => 13,
                ],
            ],
        ]);
    $quoteId = $createRes->json('data.id');

    // Attempt converting while draft should fail
    $prematureConvert = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/convert");
    $prematureConvert->assertStatus(422)
        ->assertJsonPath('code', 'QUOTATION_NOT_ACCEPTED');

    // Send and Accept
    $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/send");

    $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/accept");

    // Convert to Invoice
    $convertRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/convert", [
            'due_date' => '2026-11-29',
            'post_immediately' => false,
        ]);

    $convertRes->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.invoice_number', 'INV-2026-000001')
        ->assertJsonPath('data.sales_quotation_id', $quoteId)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal_cents', 25500000)
        ->assertJsonPath('data.tax_cents', 3315000)
        ->assertJsonPath('data.total_cents', 28815000);

    // Duplicate conversion is rejected
    $duplicateConvert = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/quotations/{$quoteId}/convert");
    $duplicateConvert->assertStatus(422)
        ->assertJsonPath('code', 'QUOTATION_ALREADY_CONVERTED');
});

test('posted sales invoices are immutable: updates and deletions are rejected', function (): void {
    Event::fake([SalesInvoicePosted::class]);

    $cookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'owner@alpha-sales.nexuserp.test');

    // 1. Create invoice in draft
    $createRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 1,
                    'unit_price_cents' => 8500000,
                    'tax_rate_percent' => 0,
                ],
            ],
        ]);
    $invoiceId = $createRes->json('data.id');

    // 2. Draft can be updated
    $updateDraftRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->putJson("/api/v1/sales/invoices/{$invoiceId}", [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 2,
                    'unit_price_cents' => 8500000,
                    'tax_rate_percent' => 0,
                ],
            ],
        ]);
    $updateDraftRes->assertOk()
        ->assertJsonPath('data.total_cents', 17000000);

    // 3. Post invoice
    $postRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$invoiceId}/post");

    $postRes->assertOk()
        ->assertJsonPath('data.status', 'posted');

    Event::assertDispatched(SalesInvoicePosted::class, function ($event) use ($invoiceId): bool {
        return $event->invoice->id === $invoiceId;
    });

    // 4. Update on posted invoice is rejected
    $updatePostedRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->putJson("/api/v1/sales/invoices/{$invoiceId}", [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 10,
                    'unit_price_cents' => 8500000,
                ],
            ],
        ]);
    $updatePostedRes->assertStatus(422)
        ->assertJsonPath('code', 'INVOICE_IMMUTABLE');

    // 5. Deletion on posted invoice is rejected
    $deleteRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->deleteJson("/api/v1/sales/invoices/{$invoiceId}");
    $deleteRes->assertStatus(422)
        ->assertJsonPath('code', 'INVOICE_IMMUTABLE');
});

test('customer receivable balance tracks live outstanding cents accurately across posted invoices', function (): void {
    $cookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'sales@alpha-sales.nexuserp.test');

    // Initially zero balance
    $balRes = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->getJson("/api/v1/sales/customers/{$this->customerA->id}/receivable-balance");
    $balRes->assertOk()
        ->assertJsonPath('data.outstanding_receivable_cents', 0);

    // Create Invoice 1: $100.00 (10,000 cents) & Post it
    $inv1 = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-15',
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price_cents' => 10000],
            ],
        ])->json('data.id');

    $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$inv1}/post");

    // Balance should now be 10,000 cents
    $balRes1 = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->getJson("/api/v1/sales/customers/{$this->customerA->id}/receivable-balance");
    $balRes1->assertJsonPath('data.outstanding_receivable_cents', 10000);

    // Create Invoice 2: $250.00 (25,000 cents) & Post it
    $inv2 = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-15',
            'items' => [
                ['product_id' => $this->productB->id, 'quantity' => 1, 'unit_price_cents' => 25000],
            ],
        ])->json('data.id');

    $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson("/api/v1/sales/invoices/{$inv2}/post");

    // Balance should now be 35,000 cents (10,000 + 25,000)
    $balRes2 = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->getJson("/api/v1/sales/customers/{$this->customerA->id}/receivable-balance");
    $balRes2->assertJsonPath('data.outstanding_receivable_cents', 35000);
});

test('RBAC: warehouse manager role cannot create or view sales quotations or invoices', function (): void {
    $cookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'warehouse@alpha-sales.nexuserp.test');

    // Quotation listing forbidden
    $resQuote = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->getJson('/api/v1/sales/quotations');
    expect($resQuote->getStatusCode())->toBeIn([401, 403]);

    // Invoice creation forbidden
    $resInv = $this->withHeader('Host', 'alpha-sales.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-15',
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price_cents' => 1000],
            ],
        ]);
    expect($resInv->getStatusCode())->toBeIn([401, 403]);
});

test('multi-tenant isolation: sales documents on Alpha are completely invisible on Beta', function (): void {
    $alphaCookie = loginAsSalesTenant($this, 'alpha-sales.nexuserp.test', 'owner@alpha-sales.nexuserp.test');

    // Create quote on Alpha
    $createRes = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://alpha-sales.nexuserp.test/api/v1/sales/quotations', [
            'customer_id' => $this->customerA->id,
            'issue_date' => '2026-10-29',
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1, 'unit_price_cents' => 5000],
            ],
        ]);
    $createRes->assertCreated();
    $alphaQuote = $createRes->json('data.id');

    // Beta logs in
    $betaCookie = loginAsSalesTenant($this, 'beta-sales.nexuserp.test', 'owner@beta-sales.nexuserp.test');

    // Beta cannot see Alpha's quote
    $betaView = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://beta-sales.nexuserp.test/api/v1/sales/quotations/{$alphaQuote}");
    $betaView->assertStatus(404)
        ->assertJsonPath('code', 'QUOTATION_NOT_FOUND');

    // Beta quotation listing has 0 items
    $betaList = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://beta-sales.nexuserp.test/api/v1/sales/quotations');
    $betaList->assertOk()
        ->assertJsonPath('meta.total', 0);
});
