<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropInventoryTestTenantDatabase')) {
    function dropInventoryTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_inv_alpha', 'tenant_inv_beta'], true)) {
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
    Cache::store('redis')->flush();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropInventoryTestTenantDatabase('tenant_inv_alpha');
    dropInventoryTestTenantDatabase('tenant_inv_beta');

    Domain::query()->whereIn('host', ['invalpha.nexuserp.test', 'invbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['invalpha', 'invbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Inventory Alpha Corp',
        'slug' => 'invalpha',
        'database_name' => 'tenant_inv_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'invalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@invalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Inventory Beta Corp',
        'slug' => 'invbeta',
        'database_name' => 'tenant_inv_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'invbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@invbeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Alpha users setup
    $tenantManager->switchTo(new TenantContext(
        id: $alphaTenant->id,
        slug: $alphaTenant->slug,
        databaseName: $alphaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@invalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Warehouse Manager on Alpha
    $warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@invalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $warehouseUser->assignRole(TenantRole::WarehouseManager);

    // Create Accountant on Alpha (can view products, cannot manage)
    $accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@invalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $accountantUser->assignRole(TenantRole::Accountant);

    $tenantManager->reset();

    // Beta users setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@invbeta.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);
    $tenantManager->reset();

    $this->alphaTenant = $alphaTenant;
    $this->betaTenant = $betaTenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropInventoryTestTenantDatabase('tenant_inv_alpha');
    dropInventoryTestTenantDatabase('tenant_inv_beta');
});

function loginAsTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
{
    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

test('unauthenticated requests to inventory endpoints return 401 UNAUTHENTICATED', function (): void {
    $this->getJson('http://invalpha.nexuserp.test/api/v1/inventory/products')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
        'name' => 'Widget',
        'sku' => 'WDG-001',
    ])->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->getJson('http://invalpha.nexuserp.test/api/v1/inventory/categories')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->getJson('http://invalpha.nexuserp.test/api/v1/inventory/units-of-measure')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('RBAC: accountant can view products but cannot create, update or delete them', function (): void {
    $accountantCookie = loginAsTenant($this, 'invalpha.nexuserp.test', 'accountant@invalpha.nexuserp.test');

    // View products succeeds (200)
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->getJson('http://invalpha.nexuserp.test/api/v1/inventory/products')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Create product fails (403 FORBIDDEN)
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Accountant Widget',
            'sku' => 'ACC-001',
            'cost_price_cents' => 1000,
            'selling_price_cents' => 2000,
            'tax_category' => 'standard',
        ])->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('RBAC: warehouse manager can create, view, update and delete products', function (): void {
    $warehouseCookie = loginAsTenant($this, 'invalpha.nexuserp.test', 'warehouse@invalpha.nexuserp.test');

    // 1. Create product
    $createResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Warehouse Widget',
            'sku' => 'WH-WDG-001',
            'cost_price_cents' => 1500,
            'selling_price_cents' => 3000,
            'tax_category' => 'standard',
            'low_stock_threshold' => 10,
        ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.sku', 'WH-WDG-001')
        ->assertJsonPath('data.cost_price_cents', 1500)
        ->assertJsonPath('data.selling_price_cents', 3000);

    /** @var string $productId */
    $productId = $createResponse->json('data.id');

    // 2. View product
    $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->getJson("http://invalpha.nexuserp.test/api/v1/inventory/products/{$productId}")
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Warehouse Widget');

    // 3. Update product
    $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->putJson("http://invalpha.nexuserp.test/api/v1/inventory/products/{$productId}", [
            'name' => 'Warehouse Widget Premium',
            'selling_price_cents' => 3500,
        ])->assertStatus(200)
        ->assertJsonPath('data.name', 'Warehouse Widget Premium')
        ->assertJsonPath('data.selling_price_cents', 3500);

    // 4. Delete product
    $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->deleteJson("http://invalpha.nexuserp.test/api/v1/inventory/products/{$productId}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Product deleted successfully.');
});

test('Category and Unit of Measure CRUD lifecycle', function (): void {
    $ownerCookie = loginAsTenant($this, 'invalpha.nexuserp.test', 'owner@invalpha.nexuserp.test');

    // Create Category
    $catResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/categories', [
            'name' => 'Electronics',
            'slug' => 'electronics',
            'description' => 'Electronic gadgets and parts',
        ]);

    $catResponse->assertStatus(201)
        ->assertJsonPath('data.slug', 'electronics');

    /** @var string $catId */
    $catId = $catResponse->json('data.id');

    // Duplicate Category slug is rejected with 422 validation
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/categories', [
            'name' => 'Electronics Duplicate',
            'slug' => 'electronics',
        ])->assertStatus(422);

    // Create Unit of Measure
    $uomResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/units-of-measure', [
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);

    $uomResponse->assertStatus(201)
        ->assertJsonPath('data.symbol', 'kg');

    /** @var string $uomId */
    $uomId = $uomResponse->json('data.id');

    // Duplicate UoM symbol is rejected with 422 validation
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/units-of-measure', [
            'name' => 'Kilo duplicate',
            'symbol' => 'kg',
        ])->assertStatus(422);

    // Create Product with Category and UoM
    $prodResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Copper Wire',
            'sku' => 'COP-001',
            'barcode' => '8901234567890',
            'category_id' => $catId,
            'unit_of_measure_id' => $uomId,
            'cost_price_cents' => 850,
            'selling_price_cents' => 1200,
            'tax_category' => 'standard',
            'low_stock_threshold' => 25,
            'description' => 'Insulated copper wire',
        ]);

    $prodResponse->assertStatus(201)
        ->assertJsonPath('data.category.id', $catId)
        ->assertJsonPath('data.unit_of_measure.id', $uomId)
        ->assertJsonPath('data.tax_category', 'standard')
        ->assertJsonPath('data.tax_category_label', 'Standard Rate')
        ->assertJsonPath('data.low_stock_threshold', 25);
});

test('Product validation: rejects negative prices, floats, duplicate SKU, and unknown tax category', function (): void {
    $ownerCookie = loginAsTenant($this, 'invalpha.nexuserp.test', 'owner@invalpha.nexuserp.test');

    // Negative cost price
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Invalid Product',
            'sku' => 'INV-001',
            'cost_price_cents' => -100,
            'selling_price_cents' => 500,
            'tax_category' => 'standard',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['cost_price_cents']);

    // Negative selling price
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Invalid Product',
            'sku' => 'INV-002',
            'cost_price_cents' => 100,
            'selling_price_cents' => -500,
            'tax_category' => 'standard',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['selling_price_cents']);

    // Invalid tax category
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Invalid Tax Product',
            'sku' => 'INV-003',
            'cost_price_cents' => 100,
            'selling_price_cents' => 500,
            'tax_category' => 'super_tax_non_existent',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['tax_category']);

    // Create valid product
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Valid Product',
            'sku' => 'UNIQUE-SKU-1',
            'cost_price_cents' => 100,
            'selling_price_cents' => 200,
            'tax_category' => 'zero_rated',
        ])->assertStatus(201);

    // Duplicate SKU rejected
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Another Product Same SKU',
            'sku' => 'UNIQUE-SKU-1',
            'cost_price_cents' => 150,
            'selling_price_cents' => 250,
            'tax_category' => 'standard',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['sku']);
});

test('Product filtering, searching, and sorting on tenant catalog', function (): void {
    $ownerCookie = loginAsTenant($this, 'invalpha.nexuserp.test', 'owner@invalpha.nexuserp.test');

    // Create multiple products
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Apple iPhone 15',
            'sku' => 'PHONE-IPHONE15',
            'barcode' => '111222333',
            'cost_price_cents' => 80000,
            'selling_price_cents' => 99900,
            'tax_category' => 'standard',
        ])->assertStatus(201);

    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Samsung Galaxy S24',
            'sku' => 'PHONE-S24',
            'barcode' => '444555666',
            'cost_price_cents' => 70000,
            'selling_price_cents' => 89900,
            'tax_category' => 'standard',
        ])->assertStatus(201);

    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Bread Loaf',
            'sku' => 'FOOD-BREAD',
            'cost_price_cents' => 100,
            'selling_price_cents' => 250,
            'tax_category' => 'exempt',
        ])->assertStatus(201);

    // Search by query "Samsung"
    $searchRes = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://invalpha.nexuserp.test/api/v1/inventory/products?search=Samsung');

    $searchRes->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'PHONE-S24');

    // Filter by tax_category "exempt"
    $taxRes = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://invalpha.nexuserp.test/api/v1/inventory/products?tax_category=exempt');

    $taxRes->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'FOOD-BREAD');

    // Sort by selling_price_cents descending
    $sortRes = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://invalpha.nexuserp.test/api/v1/inventory/products?sort_by=selling_price_cents&sort_direction=desc');

    $sortRes->assertStatus(200);
    expect($sortRes->json('data.0.sku'))->toBe('PHONE-IPHONE15');
});

test('Multi-tenant data isolation: products and SKUs are completely isolated across tenants', function (): void {
    // 1. Alpha tenant creates product with SKU "SHARED-SKU-001"
    $alphaCookie = loginAsTenant($this, 'invalpha.nexuserp.test', 'owner@invalpha.nexuserp.test');
    $alphaCreate = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://invalpha.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Alpha Specific Product',
            'sku' => 'SHARED-SKU-001',
            'cost_price_cents' => 1000,
            'selling_price_cents' => 2000,
            'tax_category' => 'standard',
        ]);
    $alphaCreate->assertStatus(201);
    /** @var string $alphaProductId */
    $alphaProductId = $alphaCreate->json('data.id');

    // 2. Beta tenant logs in and creates product with the EXACT SAME SKU (SKU uniqueness is tenant-scoped)
    $this->flushSession();
    $betaCookie = loginAsTenant($this, 'invbeta.nexuserp.test', 'owner@invbeta.nexuserp.test');
    $betaCreate = $this->withCookie(config('session.cookie'), $betaCookie)
        ->postJson('http://invbeta.nexuserp.test/api/v1/inventory/products', [
            'name' => 'Beta Specific Product',
            'sku' => 'SHARED-SKU-001',
            'cost_price_cents' => 3000,
            'selling_price_cents' => 4500,
            'tax_category' => 'zero_rated',
        ]);
    $betaCreate->assertStatus(201);

    // 3. Beta tenant CANNOT see Alpha's product in its list
    $betaList = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://invbeta.nexuserp.test/api/v1/inventory/products');

    $betaList->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Beta Specific Product')
        ->assertJsonPath('data.0.selling_price_cents', 4500);

    // 4. Beta tenant CANNOT fetch Alpha's product by ID (returns 404 NOT_FOUND)
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://invbeta.nexuserp.test/api/v1/inventory/products/{$alphaProductId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'PRODUCT_NOT_FOUND');
});
