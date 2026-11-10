<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Models\Contact;
use Modules\Inventory\Models\Product;
use Modules\Sales\Models\SalesQuotation;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropPackageLimitTestTenantDatabase')) {
    function dropPackageLimitTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_pkg_alpha', 'tenant_pkg_beta'], true)) {
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

    dropPackageLimitTestTenantDatabase('tenant_pkg_alpha');
    dropPackageLimitTestTenantDatabase('tenant_pkg_beta');

    DB::connection('central')->table('subscriptions')->delete();
    DB::connection('central')->table('packages')->delete();
    Domain::query()->whereIn('host', ['pkgalpha.nexuserp.test', 'pkgbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['pkgalpha', 'pkgbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Pkg Alpha Corp',
        'slug' => 'pkgalpha',
        'database_name' => 'tenant_pkg_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'pkgalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@pkgalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Pkg Beta Corp',
        'slug' => 'pkgbeta',
        'database_name' => 'tenant_pkg_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'pkgbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@pkgbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Configure Alpha tenant user credentials
    $tenantManager->switchTo(new TenantContext(
        id: $alphaTenant->id,
        slug: $alphaTenant->slug,
        databaseName: $alphaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@pkgalpha.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);

    // Create a customer and product on Alpha for invoice tests
    $customer = Contact::create([
        'type' => 'customer',
        'name' => 'Test Customer',
        'code' => 'CUST-0001',
        'email' => 'customer@test.com',
        'credit_limit_cents' => 10000000,
        'opening_balance_cents' => 0,
        'payment_terms_days' => 30,
        'is_active' => true,
    ]);

    $product = Product::create([
        'name' => 'Test Item',
        'sku' => 'ITEM-001',
        'cost_price_cents' => 5000,
        'selling_price_cents' => 10000,
        'tax_category' => 'standard',
        'tax_rate_percent' => 13,
        'is_active' => true,
    ]);

    $tenantManager->reset();

    // Configure Beta tenant user credentials
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@pkgbeta.nexuserp.test')->update([
        'password' => Hash::make('BetaSecret123!'),
        'email_verified_at' => now(),
    ]);
    $tenantManager->reset();

    $this->alphaTenant = $alphaTenant;
    $this->betaTenant = $betaTenant;
    $this->customer = $customer;
    $this->product = $product;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropPackageLimitTestTenantDatabase('tenant_pkg_alpha');
    dropPackageLimitTestTenantDatabase('tenant_pkg_beta');
});

function loginAsPkgTenant(mixed $testCase, string $host, string $email, string $password = 'AlphaSecret123!'): string
{
    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

test('warehouse creation is allowed up to package limit and blocked with 403 PACKAGE_LIMIT_EXCEEDED when reached', function (): void {
    // 1. Assign Starter package with max_warehouses = 1 to Alpha
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Starter Tier',
        'slug' => 'starter-tier',
        'max_users' => 5,
        'max_warehouses' => 1,
        'max_invoices_per_month' => 50,
        'features' => json_encode(['multi_warehouse' => false]),
        'price_cents' => 2900,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cookie = loginAsPkgTenant($this, 'pkgalpha.nexuserp.test', 'owner@pkgalpha.nexuserp.test');

    // 2. Create 1st warehouse -> succeeds (count = 1 <= max 1)
    $firstResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'is_default' => true,
        ]);

    $firstResponse->assertStatus(201)
        ->assertJsonPath('data.code', 'WH-MAIN');

    // 3. Attempt to create 2nd warehouse -> blocked by package.limit:warehouses middleware with 403
    $secondResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Overflow Warehouse',
            'code' => 'WH-OVERFLOW',
            'is_default' => false,
        ]);

    $secondResponse->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'PACKAGE_LIMIT_EXCEEDED',
        ]);

    // 4. Upgrade package max_warehouses to 2 -> now allowed
    DB::connection('central')->table('packages')->where('id', $packageId)->update([
        'max_warehouses' => 2,
    ]);

    $allowedResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Overflow Warehouse',
            'code' => 'WH-OVERFLOW',
            'is_default' => false,
        ]);

    $allowedResponse->assertStatus(201)
        ->assertJsonPath('data.code', 'WH-OVERFLOW');
});

test('monthly sales invoice creation and quotation conversion are blocked when max_invoices_per_month is reached', function (): void {
    // 1. Assign package with max_invoices_per_month = 2
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Invoice Limited',
        'slug' => 'invoice-limited',
        'max_users' => 5,
        'max_warehouses' => 5,
        'max_invoices_per_month' => 2,
        'features' => json_encode([]),
        'price_cents' => 1900,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cookie = loginAsPkgTenant($this, 'pkgalpha.nexuserp.test', 'owner@pkgalpha.nexuserp.test');

    $invoicePayload = [
        'customer_id' => $this->customer->id,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'items' => [
            [
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price_cents' => 10000,
                'tax_rate_percent' => 13,
            ],
        ],
    ];

    // 2. Create invoice 1 -> succeeds
    $inv1Res = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/sales/invoices', $invoicePayload);
    $inv1Res->assertStatus(201);

    // 3. Create invoice 2 -> succeeds (count = 2)
    $inv2Res = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/sales/invoices', $invoicePayload);
    $inv2Res->assertStatus(201);

    // 4. Attempt to create 3rd invoice -> blocked with 403 PACKAGE_LIMIT_EXCEEDED
    $inv3Res = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/sales/invoices', $invoicePayload);
    $inv3Res->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'PACKAGE_LIMIT_EXCEEDED',
        ]);

    // 5. Create a quotation and attempt to convert to invoice -> also blocked
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $quotation = SalesQuotation::create([
        'quotation_number' => 'QUO-2026-000001',
        'customer_id' => $this->customer->id,
        'issue_date' => now()->toDateString(),
        'expiry_date' => now()->addDays(14)->toDateString(),
        'status' => 'sent',
        'currency' => 'NPR',
        'subtotal_cents' => 20000,
        'tax_cents' => 2600,
        'total_cents' => 22600,
    ]);
    $tenantManager->reset();

    $convertRes = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://pkgalpha.nexuserp.test/api/v1/sales/quotations/{$quotation->id}/convert");

    $convertRes->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'PACKAGE_LIMIT_EXCEEDED',
        ]);
});

test('user invitation is blocked when package max_users is reached', function (): void {
    // 1. Assign package with max_users = 1 (Alpha owner is already user 1)
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Single User Plan',
        'slug' => 'single-user-plan',
        'max_users' => 1,
        'max_warehouses' => 2,
        'max_invoices_per_month' => 50,
        'features' => json_encode([]),
        'price_cents' => 900,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cookie = loginAsPkgTenant($this, 'pkgalpha.nexuserp.test', 'owner@pkgalpha.nexuserp.test');

    // 2. Inviting user 2 -> blocked by package.limit:users with 403
    $inviteRes = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'New Staff',
            'email' => 'newstaff@pkgalpha.nexuserp.test',
            'role' => 'sales_rep',
        ]);

    $inviteRes->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'PACKAGE_LIMIT_EXCEEDED',
        ]);
});

test('tenant isolation: Alpha hitting limit does not block Beta tenant with higher limit', function (): void {
    // 1. Package A (Starter: 1 warehouse) for Alpha
    $packageA = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Starter A',
        'slug' => 'starter-a',
        'max_users' => 5,
        'max_warehouses' => 1,
        'max_invoices_per_month' => 50,
        'features' => json_encode([]),
        'price_cents' => 2000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageA,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Package B (Pro: 5 warehouses) for Beta
    $packageB = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Pro B',
        'slug' => 'pro-b',
        'max_users' => 10,
        'max_warehouses' => 5,
        'max_invoices_per_month' => 500,
        'features' => json_encode([]),
        'price_cents' => 5000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->betaTenant->id,
        'package_id' => $packageB,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Alpha logs in
    $alphaCookie = loginAsPkgTenant($this, 'pkgalpha.nexuserp.test', 'owner@pkgalpha.nexuserp.test');

    // Alpha creates 1 warehouse -> hits limit
    $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Alpha WH 1',
            'code' => 'A-WH-1',
            'is_default' => true,
        ])->assertStatus(201);

    // Alpha attempts 2nd warehouse -> rejected
    $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://pkgalpha.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Alpha WH 2',
            'code' => 'A-WH-2',
            'is_default' => false,
        ])->assertStatus(403);

    // Flush session before Beta logs in
    $this->flushSession();
    $betaCookie = loginAsPkgTenant($this, 'pkgbeta.nexuserp.test', 'owner@pkgbeta.nexuserp.test', 'BetaSecret123!');

    // Beta creates 2 warehouses -> both succeed
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->postJson('http://pkgbeta.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Beta WH 1',
            'code' => 'B-WH-1',
            'is_default' => true,
        ])->assertStatus(201);

    $this->withCookie(config('session.cookie'), $betaCookie)
        ->postJson('http://pkgbeta.nexuserp.test/api/v1/inventory/warehouses', [
            'name' => 'Beta WH 2',
            'code' => 'B-WH-2',
            'is_default' => false,
        ])->assertStatus(201);
});
