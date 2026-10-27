<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Models\Contact;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropContactTestTenantDatabase')) {
    function dropContactTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_cnt_alpha', 'tenant_cnt_beta'], true)) {
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

    dropContactTestTenantDatabase('tenant_cnt_alpha');
    dropContactTestTenantDatabase('tenant_cnt_beta');

    Domain::query()->whereIn('host', ['cntalpha.nexuserp.test', 'cntbeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['cntalpha', 'cntbeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Contacts Alpha Corp',
        'slug' => 'cntalpha',
        'database_name' => 'tenant_cnt_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'cntalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@cntalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Contacts Beta Corp',
        'slug' => 'cntbeta',
        'database_name' => 'tenant_cnt_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'cntbeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@cntbeta.nexuserp.test',
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
    TenantUser::where('email', 'owner@cntalpha.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    // Create Accountant on Alpha
    $accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@cntalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $accountantUser->assignRole(TenantRole::Accountant);

    // Create Sales Rep on Alpha
    $salesUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Sales Rep',
        'email' => 'sales@cntalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $salesUser->assignRole(TenantRole::SalesRep);

    // Create Warehouse Manager on Alpha
    $warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse Mgr',
        'email' => 'warehouse@cntalpha.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $warehouseUser->assignRole(TenantRole::WarehouseManager);

    $tenantManager->reset();

    // Beta users setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaTenant->id,
        slug: $betaTenant->slug,
        databaseName: $betaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@cntbeta.nexuserp.test')->update([
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

    dropContactTestTenantDatabase('tenant_cnt_alpha');
    dropContactTestTenantDatabase('tenant_cnt_beta');
});

function loginAsContactTenant(mixed $testCase, string $host, string $email, string $password = 'Password12345!'): string
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

test('unauthenticated requests to contacts endpoints return 401 UNAUTHENTICATED', function (): void {
    $this->getJson('http://cntalpha.nexuserp.test/api/v1/contacts')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
        'type' => 'customer',
        'name' => 'Unauthenticated Customer',
    ])->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $dummyId = (string) Str::ulid();
    $this->getJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$dummyId}")
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->putJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$dummyId}", [
        'name' => 'Updated Name',
    ])->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');

    $this->deleteJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$dummyId}")
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('RBAC: warehouse manager cannot view or create contacts', function (): void {
    $whCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'warehouse@cntalpha.nexuserp.test');

    $this->withCookie(config('session.cookie'), $whCookie)
        ->getJson('http://cntalpha.nexuserp.test/api/v1/contacts')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->withCookie(config('session.cookie'), $whCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'WH Attempt',
        ])->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('RBAC: sales rep can manage customers but cannot manage suppliers', function (): void {
    $salesCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'sales@cntalpha.nexuserp.test');

    // 1. Sales Rep CAN create and view a customer
    $custResponse = $this->withCookie(config('session.cookie'), $salesCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Sales Created Customer',
        ]);
    $custResponse->assertStatus(201)
        ->assertJsonPath('data.name', 'Sales Created Customer')
        ->assertJsonPath('data.type', 'customer');

    /** @var string $custId */
    $custId = $custResponse->json('data.id');

    $this->withCookie(config('session.cookie'), $salesCookie)
        ->getJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$custId}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $custId);

    // 2. Sales Rep CANNOT create or delete a supplier (403 FORBIDDEN)
    $this->withCookie(config('session.cookie'), $salesCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'supplier',
            'name' => 'Sales Attempt Supplier',
        ])->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('RBAC: accountant can manage both customers and suppliers', function (): void {
    $accountantCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'accountant@cntalpha.nexuserp.test');

    // 1. Create customer
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Accountant Customer',
        ])->assertStatus(201);

    // 2. Create supplier
    $suppResponse = $this->withCookie(config('session.cookie'), $accountantCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'supplier',
            'name' => 'Accountant Supplier',
        ]);
    $suppResponse->assertStatus(201);

    /** @var string $suppId */
    $suppId = $suppResponse->json('data.id');

    // 3. Delete supplier
    $this->withCookie(config('session.cookie'), $accountantCookie)
        ->deleteJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$suppId}")
        ->assertStatus(200);
});

test('validation: enforces required fields, valid enum type, non-negative credit limit, and unique code', function (): void {
    $ownerCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'owner@cntalpha.nexuserp.test');

    // Missing type and name
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type', 'name']);

    // Invalid type enum
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'partner_invalid',
            'name' => 'Invalid Type Partner',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['type']);

    // Negative credit limit
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Negative Credit Customer',
            'credit_limit_cents' => -5000,
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['credit_limit_cents']);

    // Negative payment terms
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Negative Terms Customer',
            'payment_terms_days' => -10,
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['payment_terms_days']);

    // First create contact with explicit code
    $first = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'First Custom Code',
            'code' => 'CUSTOM-001',
        ]);
    $first->assertStatus(201);

    // Duplicate code must be rejected with 422
    $duplicate = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'supplier',
            'name' => 'Duplicate Code Partner',
            'code' => 'CUSTOM-001',
        ]);
    $duplicate->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('auto-generation: automatically generates sequential codes for customers, suppliers, and both', function (): void {
    $ownerCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'owner@cntalpha.nexuserp.test');

    // 1. Customer 1 without code -> CUST-000001
    $c1 = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Customer Alpha One',
        ]);
    $c1->assertStatus(201)
        ->assertJsonPath('data.code', 'CUST-000001');

    // 2. Customer 2 without code -> CUST-000002
    $c2 = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Customer Alpha Two',
        ]);
    $c2->assertStatus(201)
        ->assertJsonPath('data.code', 'CUST-000002');

    // 3. Supplier 1 without code -> SUPP-000001
    $s1 = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'supplier',
            'name' => 'Supplier Alpha One',
        ]);
    $s1->assertStatus(201)
        ->assertJsonPath('data.code', 'SUPP-000001');

    // 4. Both without code -> CONT-000001
    $b1 = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'both',
            'name' => 'Hybrid Partner Alpha One',
        ]);
    $b1->assertStatus(201)
        ->assertJsonPath('data.code', 'CONT-000001');
});

test('contact CRUD lifecycle: creates with all fields, retrieves, updates, and deletes', function (): void {
    $ownerCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'owner@cntalpha.nexuserp.test');

    $payload = [
        'type' => 'customer',
        'name' => 'Mega Retailers Ltd',
        'code' => 'MEGA-RET-01',
        'tax_id' => 'VAT-99887766',
        'email' => 'purchasing@megaretail.com',
        'phone' => '+1-555-0199',
        'payment_terms_days' => 30,
        'credit_limit_cents' => 5000000, // $50,000.00
        'opening_balance_cents' => 1250000, // $12,500.00 receivable
        'is_active' => true,
        'notes' => 'Preferred wholesale client',
        'billing_address' => [
            'street' => '100 Commerce Way',
            'city' => 'Metropolis',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ],
        'shipping_address' => [
            'street' => 'Warehouse Dock 4, 100 Commerce Way',
            'city' => 'Metropolis',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ],
    ];

    // 1. Create contact
    $createResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', $payload);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Mega Retailers Ltd')
        ->assertJsonPath('data.code', 'MEGA-RET-01')
        ->assertJsonPath('data.tax_id', 'VAT-99887766')
        ->assertJsonPath('data.email', 'purchasing@megaretail.com')
        ->assertJsonPath('data.phone', '+1-555-0199')
        ->assertJsonPath('data.payment_terms_days', 30)
        ->assertJsonPath('data.credit_limit_cents', 5000000)
        ->assertJsonPath('data.opening_balance_cents', 1250000)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.billing_address.city', 'Metropolis')
        ->assertJsonPath('data.shipping_address.country', 'US');

    /** @var string $contactId */
    $contactId = $createResponse->json('data.id');

    // 2. Retrieve contact by ID
    $showResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$contactId}");

    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $contactId)
        ->assertJsonPath('data.code', 'MEGA-RET-01');

    // 3. Update contact fields
    $updateResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->putJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$contactId}", [
            'name' => 'Mega Retailers International',
            'payment_terms_days' => 45,
            'credit_limit_cents' => 7500000,
            'is_active' => false,
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.name', 'Mega Retailers International')
        ->assertJsonPath('data.payment_terms_days', 45)
        ->assertJsonPath('data.credit_limit_cents', 7500000)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.code', 'MEGA-RET-01'); // unchanged

    // 4. Delete contact
    $deleteResponse = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->deleteJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$contactId}");

    $deleteResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    // 5. Subsequent show returns 404
    $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson("http://cntalpha.nexuserp.test/api/v1/contacts/{$contactId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'CONTACT_NOT_FOUND');
});

test('search, filter, and sort: filters by type and active status, searches text, and sorts', function (): void {
    $ownerCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'owner@cntalpha.nexuserp.test');

    // Seed 4 contacts
    $contacts = [
        ['type' => 'customer', 'name' => 'Acme Corporation', 'code' => 'C-001', 'is_active' => true, 'tax_id' => 'TAX-111'],
        ['type' => 'customer', 'name' => 'Zenith Tech', 'code' => 'C-002', 'is_active' => false, 'tax_id' => 'TAX-222'],
        ['type' => 'supplier', 'name' => 'Apex Logistics', 'code' => 'S-001', 'is_active' => true, 'tax_id' => 'TAX-333'],
        ['type' => 'both', 'name' => 'Global Distribution', 'code' => 'B-001', 'is_active' => true, 'tax_id' => 'TAX-444'],
    ];

    foreach ($contacts as $c) {
        $this->withCookie(config('session.cookie'), $ownerCookie)
            ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', $c)
            ->assertStatus(201);
    }

    // 1. Filter by customer (includes type=customer and type=both)
    $customerList = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://cntalpha.nexuserp.test/api/v1/contacts?type=customer');
    $customerList->assertStatus(200)
        ->assertJsonCount(3, 'data'); // Acme, Zenith, Global

    // 2. Filter by supplier (includes type=supplier and type=both)
    $supplierList = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://cntalpha.nexuserp.test/api/v1/contacts?type=supplier');
    $supplierList->assertStatus(200)
        ->assertJsonCount(2, 'data'); // Apex, Global

    // 3. Filter by inactive
    $inactiveList = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://cntalpha.nexuserp.test/api/v1/contacts?is_active=false');
    $inactiveList->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Zenith Tech');

    // 4. Search by tax_id
    $searchList = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://cntalpha.nexuserp.test/api/v1/contacts?search=TAX-333');
    $searchList->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Apex Logistics');

    // 5. Sort by name descending
    $sortDescList = $this->withCookie(config('session.cookie'), $ownerCookie)
        ->getJson('http://cntalpha.nexuserp.test/api/v1/contacts?sort_by=name&sort_direction=desc');
    $sortDescList->assertStatus(200)
        ->assertJsonPath('data.0.name', 'Zenith Tech');
});

test('multi-tenant data isolation: contacts are strictly isolated between tenants', function (): void {
    $alphaCookie = loginAsContactTenant($this, 'cntalpha.nexuserp.test', 'owner@cntalpha.nexuserp.test');

    // Alpha creates customer
    $alphaRes = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://cntalpha.nexuserp.test/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Confidential Alpha Partner',
            'code' => 'SHARED-CODE-01',
        ]);
    $alphaRes->assertStatus(201);
    /** @var string $alphaContactId */
    $alphaContactId = $alphaRes->json('data.id');

    $this->flushSession();

    // Beta logs in
    $betaCookie = loginAsContactTenant($this, 'cntbeta.nexuserp.test', 'owner@cntbeta.nexuserp.test');

    // Beta lists contacts -> empty
    $betaList = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://cntbeta.nexuserp.test/api/v1/contacts');
    $betaList->assertStatus(200)
        ->assertJsonCount(0, 'data');

    // Beta tries to access Alpha contact by ID -> 404
    $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://cntbeta.nexuserp.test/api/v1/contacts/{$alphaContactId}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'CONTACT_NOT_FOUND');

    // Beta CAN create contact with the SAME code (SHARED-CODE-01) without collision
    $betaCreate = $this->withCookie(config('session.cookie'), $betaCookie)
        ->postJson('http://cntbeta.nexuserp.test/api/v1/contacts', [
            'type' => 'supplier',
            'name' => 'Beta Independent Partner',
            'code' => 'SHARED-CODE-01',
        ]);
    $betaCreate->assertStatus(201)
        ->assertJsonPath('data.code', 'SHARED-CODE-01')
        ->assertJsonPath('data.name', 'Beta Independent Partner');
});
