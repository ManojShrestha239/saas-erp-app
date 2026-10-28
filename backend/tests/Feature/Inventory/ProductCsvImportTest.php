<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\ProductCsvImportService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Enums\CsvImportStatus;
use Shared\Models\CsvImport;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropProductImportTestTenantDatabase')) {
    function dropProductImportTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_pimp_alpha', 'tenant_pimp_beta'], true)) {
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

function loginAsProductImportTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
{
    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

beforeEach(function (): void {
    app('cache')->flush();
    Cache::store('redis')->flush();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropProductImportTestTenantDatabase('tenant_pimp_alpha');
    dropProductImportTestTenantDatabase('tenant_pimp_beta');

    Domain::query()->whereIn('host', ['pimpalpha.nexuserp.test', 'pimpbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['pimpalpha', 'pimpbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Product Import Alpha Corp',
        'slug' => 'pimpalpha',
        'database_name' => 'tenant_pimp_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'pimpalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@pimpalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Product Import Beta Corp',
        'slug' => 'pimpbeta',
        'database_name' => 'tenant_pimp_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'pimpbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@pimpbeta.nexuserp.test',
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
    TenantUser::where('email', 'owner@pimpalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Warehouse Manager on Alpha (has products.manage via role)
    $whManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Manager',
        'email' => 'warehouse@pimpalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $whManager->assignRole(TenantRole::WarehouseManager->value);

    // Create Sales Rep on Alpha (only has products.view, cannot products.manage)
    $salesUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@pimpalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $salesUser->assignRole(TenantRole::SalesRep->value);

    // Beta owner setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@pimpbeta.nexuserp.test')->update([
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

    dropProductImportTestTenantDatabase('tenant_pimp_alpha');
    dropProductImportTestTenantDatabase('tenant_pimp_beta');
});

test('authorized user can download product import template CSV', function (): void {
    $session = loginAsProductImportTenant($this, 'pimpalpha.nexuserp.test', 'owner@pimpalpha.nexuserp.test');

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->get('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import/template');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->getContent())->toContain('sku,name,cost_price,selling_price');
});

test('user without products.manage permission cannot upload product CSV', function (): void {
    $session = loginAsProductImportTenant($this, 'pimpalpha.nexuserp.test', 'sales@pimpalpha.nexuserp.test');

    $csvContent = "sku,name,cost_price,selling_price\nSKU-001,Hammer,10.00,15.00";
    $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', [
            'file' => $file,
        ]);

    $response->assertStatus(403);
});

test('valid CSV imports products with exact minor unit cents, categories, and UoMs', function (): void {
    $session = loginAsProductImportTenant($this, 'pimpalpha.nexuserp.test', 'owner@pimpalpha.nexuserp.test');

    $csvContent = implode("\n", [
        'sku,name,cost_price,selling_price,category,unit_of_measure,low_stock_threshold',
        'SKU-TEST-01,Heavy Duty Drill,45.50,89.99,Power Tools,PCS,5',
        'SKU-TEST-02,Screwdriver Set,12.00,24.50,Hand Tools,SET,10',
    ]);
    $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', [
            'file' => $file,
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status.value', CsvImportStatus::Pending->value);

    $importId = (string) $response->json('data.id');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $import = CsvImport::findOrFail($importId);
    app(ProductCsvImportService::class)->process(
        $import,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    // Check status endpoint
    $statusResponse = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->get("http://pimpalpha.nexuserp.test/api/v1/inventory/products/import/{$importId}");

    $statusResponse->assertStatus(200)
        ->assertJsonPath('data.status.value', CsvImportStatus::Completed->value)
        ->assertJsonPath('data.successful_rows', 2)
        ->assertJsonPath('data.failed_rows', 0);

    // Verify database state on Alpha
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $drill = Product::where('sku', 'SKU-TEST-01')->first();
    expect($drill)->not->toBeNull();
    expect($drill?->name)->toBe('Heavy Duty Drill');
    expect($drill?->cost_price_cents)->toBe(4550);
    expect($drill?->selling_price_cents)->toBe(8999);
    expect($drill?->low_stock_threshold)->toBe(5);
    expect($drill?->category?->name)->toBe('Power Tools');
    expect($drill?->unitOfMeasure?->symbol)->toBe('PCS');

    $screwdriver = Product::where('sku', 'SKU-TEST-02')->first();
    expect($screwdriver)->not->toBeNull();
    expect($screwdriver?->cost_price_cents)->toBe(1200);
    expect($screwdriver?->selling_price_cents)->toBe(2450);
});

test('invalid rows are collected in errors without aborting valid rows', function (): void {
    $session = loginAsProductImportTenant($this, 'pimpalpha.nexuserp.test', 'owner@pimpalpha.nexuserp.test');

    $csvContent = implode("\n", [
        'sku,name,cost_price,selling_price',
        'SKU-VALID-01,Valid Product,10.00,20.00',
        ',Missing SKU Product,5.00,10.00',
        'SKU-INVALID-PRICE,Bad Price Product,-15.00,20.00',
    ]);
    $file = UploadedFile::fake()->createWithContent('mixed_products.csv', $csvContent);

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', [
            'file' => $file,
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status.value', CsvImportStatus::Pending->value);

    $importId = (string) $response->json('data.id');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $import = CsvImport::findOrFail($importId);
    app(ProductCsvImportService::class)->process(
        $import,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    // Check status endpoint
    $statusResponse = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->get("http://pimpalpha.nexuserp.test/api/v1/inventory/products/import/{$importId}");

    $statusResponse->assertStatus(200)
        ->assertJsonPath('data.successful_rows', 1)
        ->assertJsonPath('data.failed_rows', 2);

    $errors = $statusResponse->json('data.errors');
    expect($errors)->toHaveCount(2);
});

test('duplicate action skip preserves existing product while update modifies it', function (): void {
    $session = loginAsProductImportTenant($this, 'pimpalpha.nexuserp.test', 'owner@pimpalpha.nexuserp.test');

    // 1. Initial product import
    $initialCsv = "sku,name,cost_price,selling_price\nSKU-DUP-01,Original Name,10.00,20.00";
    $file1 = UploadedFile::fake()->createWithContent('initial.csv', $initialCsv);
    $res1 = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', ['file' => $file1]);
    $importId1 = (string) $res1->json('data.id');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $import1 = CsvImport::findOrFail($importId1);
    app(ProductCsvImportService::class)->process(
        $import1,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    // 2. Import same SKU with duplicate_action: skip
    $skipCsv = "sku,name,cost_price,selling_price\nSKU-DUP-01,New Ignored Name,99.00,199.00";
    $file2 = UploadedFile::fake()->createWithContent('skip.csv', $skipCsv);
    $res2 = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', [
            'file' => $file2,
            'duplicate_action' => 'skip',
        ]);
    $importId2 = (string) $res2->json('data.id');

    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $import2 = CsvImport::findOrFail($importId2);
    app(ProductCsvImportService::class)->process(
        $import2,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    $productAfterSkip = Product::where('sku', 'SKU-DUP-01')->first();
    expect($productAfterSkip?->name)->toBe('Original Name');
    expect($productAfterSkip?->cost_price_cents)->toBe(1000);

    // 3. Import same SKU with duplicate_action: update
    $updateCsv = "sku,name,cost_price,selling_price\nSKU-DUP-01,Updated Name,30.00,50.00";
    $file3 = UploadedFile::fake()->createWithContent('update.csv', $updateCsv);
    $res3 = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', [
            'file' => $file3,
            'duplicate_action' => 'update',
        ]);
    $importId3 = (string) $res3->json('data.id');

    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $import3 = CsvImport::findOrFail($importId3);
    app(ProductCsvImportService::class)->process(
        $import3,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    $productAfterUpdate = Product::where('sku', 'SKU-DUP-01')->first();
    expect($productAfterUpdate?->name)->toBe('Updated Name');
    expect($productAfterUpdate?->cost_price_cents)->toBe(3000);
    expect($productAfterUpdate?->selling_price_cents)->toBe(5000);
});

test('tenant isolation: product import in Tenant A is isolated from Tenant B', function (): void {
    $sessionAlpha = loginAsProductImportTenant($this, 'pimpalpha.nexuserp.test', 'owner@pimpalpha.nexuserp.test');

    $csv = "sku,name,cost_price,selling_price\nSKU-ALPHA-99,Alpha Exclusive Tool,15.00,30.00";
    $file = UploadedFile::fake()->createWithContent('alpha.csv', $csv);

    $this->withUnencryptedCookie(config('session.cookie'), $sessionAlpha)
        ->post('http://pimpalpha.nexuserp.test/api/v1/inventory/products/import', ['file' => $file]);

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Check Beta tenant has zero products
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    expect(Product::where('sku', 'SKU-ALPHA-99')->exists())->toBeFalse();
    expect(Product::count())->toBe(0);
});
