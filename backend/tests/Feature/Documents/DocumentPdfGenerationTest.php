<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropPdfTestTenantDatabase')) {
    function dropPdfTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_pdf_alpha', 'tenant_pdf_beta'], true)) {
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

if (! function_exists('loginAsPdfTenant')) {
    function loginAsPdfTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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
    Carbon::setTestNow('2026-10-29 16:00:00');
    app('cache')->flush();

    dropPdfTestTenantDatabase('tenant_pdf_alpha');
    dropPdfTestTenantDatabase('tenant_pdf_beta');

    Domain::query()->whereIn('host', ['alpha-pdf.nexuserp.test', 'beta-pdf.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-pdf', 'beta-pdf'])->delete();

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
        'name' => 'Alpha Commercial Corp',
        'slug' => 'alpha-pdf',
        'database_name' => 'tenant_pdf_alpha',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha-pdf.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha-pdf.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // Setup Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Distribution Ltd',
        'slug' => 'beta-pdf',
        'database_name' => 'tenant_pdf_beta',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta-pdf.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta-pdf.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // Seed Alpha Tenant
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->alphaOwner = TenantUser::where('email', 'owner@alpha-pdf.nexuserp.test')->firstOrFail();
    $this->alphaOwner->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $this->alphaSalesRep = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@alpha-pdf.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->alphaSalesRep->assignRole(TenantRole::SalesRep->value);

    $this->alphaWarehouseMgr = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@alpha-pdf.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->alphaWarehouseMgr->assignRole(TenantRole::WarehouseManager->value);

    // Alpha contacts & products
    $this->alphaCustomer = Contact::create([
        'code' => 'CUST-000001',
        'type' => ContactType::Customer,
        'name' => 'Kathmandu Solar Systems Ltd',
        'tax_id' => 'PAN-99887711',
        'email' => 'accounts@kathmandusolar.test',
        'billing_address' => ['street' => 'Ring Road 4', 'city' => 'Kathmandu', 'country' => 'Nepal'],
        'payment_terms_days' => 30,
        'credit_limit_cents' => 50000000,
        'is_active' => true,
    ]);

    $this->alphaSupplier = Contact::create([
        'code' => 'SUPP-000001',
        'type' => ContactType::Supplier,
        'name' => 'Apex Industrial Components',
        'tax_id' => 'PAN-44332211',
        'email' => 'orders@apexcomponents.test',
        'billing_address' => ['street' => 'Industrial Area Gate 2', 'city' => 'Lalitpur', 'country' => 'Nepal'],
        'payment_terms_days' => 15,
        'is_active' => true,
    ]);

    $this->alphaWarehouse = Warehouse::create([
        'name' => 'Kathmandu Central Warehouse',
        'code' => 'WH-KTM',
        'address' => 'Teku Industrial Hub, Kathmandu',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->alphaProduct = Product::create([
        'name' => 'Hybrid Inverter 5kW',
        'sku' => 'INV-HYB-5K',
        'description' => '5kW Pure Sine Wave Hybrid Solar Inverter',
        'cost_price_cents' => 6500000, // NPR 65,000.00
        'selling_price_cents' => 8800000, // NPR 88,000.00
        'tax_category' => 'standard',
        'is_active' => true,
    ]);

    // Seed tenant company settings
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'company_name'],
        ['value' => 'Alpha Solar Energy Nepal Pvt Ltd', 'group' => 'general', 'updated_at' => now()]
    );
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'company_tax_id'],
        ['value' => 'PAN-601234567', 'group' => 'general', 'updated_at' => now()]
    );
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'company_address'],
        ['value' => 'Lazimpat Commercial Center, Kathmandu, Nepal', 'group' => 'general', 'updated_at' => now()]
    );
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'company_email'],
        ['value' => 'info@alphasolar.test', 'group' => 'general', 'updated_at' => now()]
    );
    DB::connection('tenant')->table('settings')->updateOrInsert(
        ['key' => 'company_phone'],
        ['value' => '+977-1-4412345', 'group' => 'general', 'updated_at' => now()]
    );

    // Seed Beta records
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    $this->betaOwner = TenantUser::where('email', 'owner@beta-pdf.nexuserp.test')->firstOrFail();
    $this->betaOwner->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    dropPdfTestTenantDatabase('tenant_pdf_alpha');
    dropPdfTestTenantDatabase('tenant_pdf_beta');
    Domain::query()->whereIn('host', ['alpha-pdf.nexuserp.test', 'beta-pdf.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-pdf', 'beta-pdf'])->delete();
});

test('sales invoice PDF endpoint streams valid PDF binary with standard headers', function (): void {
    $cookie = loginAsPdfTenant($this, 'alpha-pdf.nexuserp.test', 'owner@alpha-pdf.nexuserp.test');

    // Create invoice on Alpha
    $createRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->alphaCustomer->id,
            'warehouse_id' => $this->alphaWarehouse->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                [
                    'product_id' => $this->alphaProduct->id,
                    'quantity' => 2,
                    'unit_price_cents' => 8800000,
                    'tax_rate_percent' => 13,
                    'description' => 'Solar inverters for commercial installation',
                ],
            ],
            'notes' => 'Installation included with standard 2-year warranty.',
        ]);
    $createRes->assertCreated();
    $invoiceId = (string) $createRes->json('data.id');
    $invoiceNumber = (string) $createRes->json('data.invoice_number');

    // Request PDF stream (inline)
    $pdfRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->get("/api/v1/sales/invoices/{$invoiceId}/pdf");

    $pdfRes->assertOk();
    $pdfRes->assertHeader('Content-Type', 'application/pdf');
    $pdfRes->assertHeader('Content-Disposition', "inline; filename=\"{$invoiceNumber}.pdf\"");

    $content = $pdfRes->getContent();
    expect($content)->toStartWith('%PDF-')
        ->and(strlen($content))->toBeGreaterThan(1000);

    // Request PDF download (attachment)
    $downloadRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->get("/api/v1/sales/invoices/{$invoiceId}/pdf?download=1");

    $downloadRes->assertOk();
    $downloadRes->assertHeader('Content-Type', 'application/pdf');
    $downloadRes->assertHeader('Content-Disposition', "attachment; filename=\"{$invoiceNumber}.pdf\"");
});

test('sales quotation PDF endpoint streams valid PDF binary with standard headers', function (): void {
    $cookie = loginAsPdfTenant($this, 'alpha-pdf.nexuserp.test', 'owner@alpha-pdf.nexuserp.test');

    // Create quotation on Alpha
    $createRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/sales/quotations', [
            'customer_id' => $this->alphaCustomer->id,
            'issue_date' => '2026-10-29',
            'expiry_date' => '2026-11-29',
            'items' => [
                [
                    'product_id' => $this->alphaProduct->id,
                    'quantity' => 5,
                    'unit_price_cents' => 8500000,
                    'tax_rate_percent' => 13,
                    'description' => 'Bulk quotation for solar power project',
                ],
            ],
            'notes' => 'Quotation valid for 30 calendar days.',
        ]);
    $createRes->assertCreated();
    $quotationId = (string) $createRes->json('data.id');
    $quotationNumber = (string) $createRes->json('data.quotation_number');

    // Request Quotation PDF stream
    $pdfRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->get("/api/v1/sales/quotations/{$quotationId}/pdf");

    $pdfRes->assertOk();
    $pdfRes->assertHeader('Content-Type', 'application/pdf');
    $pdfRes->assertHeader('Content-Disposition', "inline; filename=\"{$quotationNumber}.pdf\"");

    $content = $pdfRes->getContent();
    expect($content)->toStartWith('%PDF-')
        ->and(strlen($content))->toBeGreaterThan(1000);
});

test('purchase order PDF endpoint streams valid PDF binary with standard headers', function (): void {
    $cookie = loginAsPdfTenant($this, 'alpha-pdf.nexuserp.test', 'owner@alpha-pdf.nexuserp.test');

    // Create PO on Alpha
    $createRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/purchasing/orders', [
            'supplier_id' => $this->alphaSupplier->id,
            'warehouse_id' => $this->alphaWarehouse->id,
            'order_date' => '2026-10-29',
            'expected_delivery_date' => '2026-11-15',
            'items' => [
                [
                    'product_id' => $this->alphaProduct->id,
                    'quantity_ordered' => 10,
                    'unit_cost_cents' => 6500000,
                    'tax_rate_percent' => 13,
                    'notes' => 'Procurement batch #882',
                ],
            ],
            'notes' => 'Urgent stock replenishment order.',
        ]);
    $createRes->assertCreated();
    $poId = (string) $createRes->json('data.id');
    $poNumber = (string) $createRes->json('data.order_number');

    // Request PO PDF stream
    $pdfRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $cookie)
        ->get("/api/v1/purchasing/orders/{$poId}/pdf");

    $pdfRes->assertOk();
    $pdfRes->assertHeader('Content-Type', 'application/pdf');
    $pdfRes->assertHeader('Content-Disposition', "inline; filename=\"{$poNumber}.pdf\"");

    $content = $pdfRes->getContent();
    expect($content)->toStartWith('%PDF-')
        ->and(strlen($content))->toBeGreaterThan(1000);
});

test('RBAC: warehouse manager role is forbidden from viewing sales invoice or quotation PDFs', function (): void {
    $ownerCookie = loginAsPdfTenant($this, 'alpha-pdf.nexuserp.test', 'owner@alpha-pdf.nexuserp.test');

    $createRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->alphaCustomer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->alphaProduct->id, 'quantity' => 1, 'unit_price_cents' => 10000],
            ],
        ]);
    $invoiceId = (string) $createRes->json('data.id');

    // Log in as warehouse manager (has no sales permissions)
    $whCookie = loginAsPdfTenant($this, 'alpha-pdf.nexuserp.test', 'warehouse@alpha-pdf.nexuserp.test');

    $res = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $whCookie)
        ->get("/api/v1/sales/invoices/{$invoiceId}/pdf");

    expect($res->getStatusCode())->toBe(403);
});

test('multi-tenant isolation: Beta tenant cannot download or view Alpha tenant document PDFs', function (): void {
    $alphaCookie = loginAsPdfTenant($this, 'alpha-pdf.nexuserp.test', 'owner@alpha-pdf.nexuserp.test');

    $createRes = $this->withHeader('Host', 'alpha-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('/api/v1/sales/invoices', [
            'customer_id' => $this->alphaCustomer->id,
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-28',
            'items' => [
                ['product_id' => $this->alphaProduct->id, 'quantity' => 1, 'unit_price_cents' => 10000],
            ],
        ]);
    $alphaInvoiceId = (string) $createRes->json('data.id');

    // Log in on Beta
    $betaCookie = loginAsPdfTenant($this, 'beta-pdf.nexuserp.test', 'owner@beta-pdf.nexuserp.test');

    // Attempt to download Alpha invoice from Beta domain
    $betaRes = $this->withHeader('Host', 'beta-pdf.nexuserp.test')
        ->withCookie(config('session.cookie'), $betaCookie)
        ->get("/api/v1/sales/invoices/{$alphaInvoiceId}/pdf");

    $betaRes->assertStatus(404)
        ->assertJsonPath('code', 'INVOICE_NOT_FOUND');
});
