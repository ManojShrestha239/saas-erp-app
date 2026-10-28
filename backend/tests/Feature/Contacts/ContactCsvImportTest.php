<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Services\ContactCsvImportService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Enums\CsvImportStatus;
use Shared\Models\CsvImport;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropContactImportTestTenantDatabase')) {
    function dropContactImportTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_cimp_alpha', 'tenant_cimp_beta'], true)) {
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

function loginAsContactImportTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
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

    dropContactImportTestTenantDatabase('tenant_cimp_alpha');
    dropContactImportTestTenantDatabase('tenant_cimp_beta');

    Domain::query()->whereIn('host', ['cimpalpha.nexuserp.test', 'cimpbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['cimpalpha', 'cimpbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Contact Import Alpha Corp',
        'slug' => 'cimpalpha',
        'database_name' => 'tenant_cimp_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'cimpalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@cimpalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Contact Import Beta Corp',
        'slug' => 'cimpbeta',
        'database_name' => 'tenant_cimp_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'cimpbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@cimpbeta.nexuserp.test',
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
    TenantUser::where('email', 'owner@cimpalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Warehouse Manager on Alpha (has NO customer or supplier manage permission)
    $whManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Manager',
        'email' => 'warehouse@cimpalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $whManager->assignRole(TenantRole::WarehouseManager->value);

    // Beta owner setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@cimpbeta.nexuserp.test')->update([
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

    dropContactImportTestTenantDatabase('tenant_cimp_alpha');
    dropContactImportTestTenantDatabase('tenant_cimp_beta');
});

test('authorized user can download contact import template CSV', function (): void {
    $session = loginAsContactImportTenant($this, 'cimpalpha.nexuserp.test', 'owner@cimpalpha.nexuserp.test');

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->get('http://cimpalpha.nexuserp.test/api/v1/contacts/import/template');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->getContent())->toContain('type,name,code,tax_id,email');
});

test('user without contacts permission cannot upload contact CSV', function (): void {
    $session = loginAsContactImportTenant($this, 'cimpalpha.nexuserp.test', 'warehouse@cimpalpha.nexuserp.test');

    $csvContent = "type,name,email\ncustomer,Acme Corp,acme@example.com";
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', [
            'file' => $file,
        ]);

    $response->assertStatus(403);
});

test('valid CSV imports customers and suppliers with sequential codes and minor units', function (): void {
    $session = loginAsContactImportTenant($this, 'cimpalpha.nexuserp.test', 'owner@cimpalpha.nexuserp.test');

    $csvContent = implode("\n", [
        'type,name,email,payment_terms_days,credit_limit,opening_balance,city,country',
        'customer,Apex Retail Solutions,apex@example.com,30,5000.00,0.00,Seattle,US',
        'supplier,Pacific Freight & Logistics,info@pacificfreight.com,45,0.00,1250.50,San Francisco,US',
    ]);
    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', [
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
    app(ContactCsvImportService::class)->process(
        $import,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    // Check status endpoint
    $statusResponse = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->get("http://cimpalpha.nexuserp.test/api/v1/contacts/import/{$importId}");

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

    $customer = Contact::where('email', 'apex@example.com')->first();
    expect($customer)->not->toBeNull();
    expect($customer?->type)->toBe(ContactType::Customer);
    expect($customer?->code)->toBe('CUST-000001');
    expect($customer?->credit_limit_cents)->toBe(500000);
    expect($customer?->billing_address)->toBeArray();
    expect($customer?->billing_address['city'] ?? null)->toBe('Seattle');

    $supplier = Contact::where('email', 'info@pacificfreight.com')->first();
    expect($supplier)->not->toBeNull();
    expect($supplier?->type)->toBe(ContactType::Supplier);
    expect($supplier?->code)->toBe('SUPP-000001');
    expect($supplier?->opening_balance_cents)->toBe(125050);
});

test('invalid contact rows are reported in errors array', function (): void {
    $session = loginAsContactImportTenant($this, 'cimpalpha.nexuserp.test', 'owner@cimpalpha.nexuserp.test');

    $csvContent = implode("\n", [
        'type,name,credit_limit',
        'customer,Valid Contact,500.00',
        'customer,,200.00',
        'customer,Bad Credit Contact,-100.00',
    ]);
    $file = UploadedFile::fake()->createWithContent('contacts_with_errors.csv', $csvContent);

    $response = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', [
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
    app(ContactCsvImportService::class)->process(
        $import,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    // Check status endpoint
    $statusResponse = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->get("http://cimpalpha.nexuserp.test/api/v1/contacts/import/{$importId}");

    $statusResponse->assertStatus(200)
        ->assertJsonPath('data.successful_rows', 1)
        ->assertJsonPath('data.failed_rows', 2);

    $errors = $statusResponse->json('data.errors');
    expect($errors)->toHaveCount(2);
});

test('duplicate action skip preserves existing contact while update modifies attributes', function (): void {
    $session = loginAsContactImportTenant($this, 'cimpalpha.nexuserp.test', 'owner@cimpalpha.nexuserp.test');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // 1. Initial import
    $initialCsv = "type,name,code,credit_limit\ncustomer,Original Name,CUST-STATIC-01,1000.00";
    $file1 = UploadedFile::fake()->createWithContent('initial_contacts.csv', $initialCsv);
    $res1 = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', ['file' => $file1]);
    $importId1 = (string) $res1->json('data.id');

    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $import1 = CsvImport::findOrFail($importId1);
    app(ContactCsvImportService::class)->process(
        $import1,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    // 2. Duplicate with skip
    $skipCsv = "type,name,code,credit_limit\ncustomer,Ignored Name,CUST-STATIC-01,9999.00";
    $file2 = UploadedFile::fake()->createWithContent('skip_contacts.csv', $skipCsv);
    $res2 = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', [
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
    app(ContactCsvImportService::class)->process(
        $import2,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    $contactAfterSkip = Contact::where('code', 'CUST-STATIC-01')->first();
    expect($contactAfterSkip?->name)->toBe('Original Name');
    expect($contactAfterSkip?->credit_limit_cents)->toBe(100000);

    // 3. Duplicate with update
    $updateCsv = "type,name,code,credit_limit\ncustomer,Updated Name,CUST-STATIC-01,2500.00";
    $file3 = UploadedFile::fake()->createWithContent('update_contacts.csv', $updateCsv);
    $res3 = $this->withUnencryptedCookie(config('session.cookie'), $session)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', [
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
    app(ContactCsvImportService::class)->process(
        $import3,
        (string) $this->alphaTenant->id,
        $this->alphaTenant->database_name
    );

    $contactAfterUpdate = Contact::where('code', 'CUST-STATIC-01')->first();
    expect($contactAfterUpdate?->name)->toBe('Updated Name');
    expect($contactAfterUpdate?->credit_limit_cents)->toBe(250000);
});

test('tenant isolation: contact import in Tenant A is invisible to Tenant B', function (): void {
    $sessionAlpha = loginAsContactImportTenant($this, 'cimpalpha.nexuserp.test', 'owner@cimpalpha.nexuserp.test');

    $csv = "type,name,code\ncustomer,Alpha Private Client,CUST-ALPHA-ONLY";
    $file = UploadedFile::fake()->createWithContent('alpha_contact.csv', $csv);

    $this->withUnencryptedCookie(config('session.cookie'), $sessionAlpha)
        ->post('http://cimpalpha.nexuserp.test/api/v1/contacts/import', ['file' => $file]);

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Check Beta tenant has zero contacts
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));

    expect(Contact::where('code', 'CUST-ALPHA-ONLY')->exists())->toBeFalse();
    expect(Contact::count())->toBe(0);
});
