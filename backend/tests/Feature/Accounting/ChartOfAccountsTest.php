<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

if (! function_exists('dropCoaApiTestTenantDatabase')) {
    function dropCoaApiTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_coa_api_alpha', 'tenant_coa_api_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

function loginAsCoaTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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
    Carbon::setTestNow('2026-10-31 10:00:00');
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

    dropCoaApiTestTenantDatabase('tenant_coa_api_alpha');
    dropCoaApiTestTenantDatabase('tenant_coa_api_beta');

    Domain::query()->whereIn('host', ['coaalpha.nexuserp.test', 'coabeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['coaalpha', 'coabeta'])->delete();

    // 1. Provision Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'COA Alpha Corp',
        'slug' => 'coaalpha',
        'database_name' => 'tenant_coa_api_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'coaalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@coaalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta Tenant
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'COA Beta Corp',
        'slug' => 'coabeta',
        'database_name' => 'tenant_coa_api_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'coabeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@coabeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // Create custom users in Alpha Tenant
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'coaalpha',
        databaseName: 'tenant_coa_api_alpha',
    ));

    // Accountant user
    $this->accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@coaalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    // Warehouse manager user (no accounting permissions)
    $this->warehouseUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Warehouse',
        'email' => 'warehouse@coaalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->warehouseUser->syncRoles([TenantRole::WarehouseManager->value]);

    // Beta tenant setup
    $tenantManager->switchTo(new TenantContext(
        id: $betaId,
        slug: 'coabeta',
        databaseName: 'tenant_coa_api_beta',
    ));

    TenantUser::where('email', 'owner@coabeta.nexuserp.test')->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropCoaApiTestTenantDatabase('tenant_coa_api_alpha');
    dropCoaApiTestTenantDatabase('tenant_coa_api_beta');
});

test('accountant can list accounts with pagination and filtering', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'code',
                    'name',
                    'type',
                    'type_label',
                    'normal_balance',
                    'parent_id',
                    'is_system',
                    'system_name',
                    'is_active',
                    'currency',
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);

    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(30);

    // Test filtering by type=asset
    $assetResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?type=asset');

    $assetResponse->assertOk();
    $types = collect($assetResponse->json('data'))->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['asset']);

    // Test search by code or name
    $searchResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?search=Cash');

    $searchResponse->assertOk();
    expect(count($searchResponse->json('data')))->toBeGreaterThanOrEqual(1)
        ->and($searchResponse->json('data.0.name'))->toContain('Cash');
});

test('accountant can retrieve hierarchical tree structure with tree=true', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?tree=true');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $roots = $response->json('data');
    expect(count($roots))->toBeGreaterThanOrEqual(5); // Assets, Liabilities, Equity, Income, COGS, Expenses

    // Ensure all roots have parent_id = null
    foreach ($roots as $root) {
        expect($root['parent_id'])->toBeNull();
    }

    // Verify root 1000 has child 1100, which has child 1110
    $assetsRoot = collect($roots)->firstWhere('code', '1000');
    expect($assetsRoot)->not->toBeNull();
    expect($assetsRoot['children'])->not->toBeEmpty();

    $currentAssets = collect($assetsRoot['children'])->firstWhere('code', '1100');
    expect($currentAssets)->not->toBeNull();
    expect($currentAssets['children'])->not->toBeEmpty();

    $cash = collect($currentAssets['children'])->firstWhere('code', '1110');
    expect($cash)->not->toBeNull()
        ->and($cash['is_system'])->toBeTrue()
        ->and($cash['system_name'])->toBe('cash');
});

test('accountant can create a new custom account with valid data', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // 1. Fetch Current Assets parent (1100)
    $listResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $parentId = $listResponse->json('data.0.id');

    // 2. Create child asset account
    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '1125',
            'name' => 'Secondary Savings Bank Account',
            'type' => 'asset',
            'parent_id' => $parentId,
            'description' => 'Dedicated savings account for tax reserves',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', '1125')
        ->assertJsonPath('data.name', 'Secondary Savings Bank Account')
        ->assertJsonPath('data.type', 'asset')
        ->assertJsonPath('data.normal_balance', 'debit')
        ->assertJsonPath('data.parent_id', $parentId)
        ->assertJsonPath('data.is_system', false);
});

test('creating account with duplicate code is rejected with 422 validation failure', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '1110', // Already used by Cash on Hand
            'name' => 'Another Cash Account',
            'type' => 'asset',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['code']);
});

test('creating child account with mismatched type from parent is rejected with 400', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // Fetch parent Asset account (1000)
    $listResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?search=1000');
    $parentAssetId = $listResponse->json('data.0.id');

    // Attempt to create an Expense account with an Asset parent
    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '6999',
            'name' => 'Invalid Child Account',
            'type' => 'expense',
            'parent_id' => $parentAssetId,
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'ACCOUNT_TYPE_MISMATCH');
});

test('updating an account successfully modifies allowed fields', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // Create a custom account first
    $createResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '6195',
            'name' => 'Miscellaneous Travel',
            'type' => 'expense',
            'description' => 'Old description',
        ]);
    $accountId = $createResponse->json('data.id');

    // Update name and description
    $updateResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->putJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$accountId}", [
            'name' => 'Executive Travel & Lodging',
            'description' => 'Updated travel policy expenses',
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.name', 'Executive Travel & Lodging')
        ->assertJsonPath('data.description', 'Updated travel policy expenses');
});

test('setting account as its own parent or cyclic hierarchy is rejected with 400', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // Create Account A
    $aRes = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '6800',
            'name' => 'Group A Expense',
            'type' => 'expense',
        ]);
    $aId = $aRes->json('data.id');

    // Self-parenting attempt
    $selfResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->putJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$aId}", [
            'parent_id' => $aId,
        ]);
    $selfResponse->assertStatus(400)
        ->assertJsonPath('code', 'ACCOUNT_CANNOT_BE_OWN_PARENT');

    // Create Account B as child of A
    $bRes = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '6810',
            'name' => 'Group B Expense (Child of A)',
            'type' => 'expense',
            'parent_id' => $aId,
        ]);
    $bId = $bRes->json('data.id');

    // Cyclic hierarchy: Try to make A a child of its own child B
    $cyclicResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->putJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$aId}", [
            'parent_id' => $bId,
        ]);
    $cyclicResponse->assertStatus(400)
        ->assertJsonPath('code', 'ACCOUNT_CYCLIC_HIERARCHY');
});

test('system accounts are protected: cannot be deleted and cannot change type or code', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // Locate system account: Accounts Receivable (1130)
    $findResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?search=1130');
    $arId = $findResponse->json('data.0.id');
    expect($findResponse->json('data.0.is_system'))->toBeTrue();

    // 1. Attempt to delete system account
    $deleteResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->deleteJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$arId}");
    $deleteResponse->assertStatus(400)
        ->assertJsonPath('code', 'SYSTEM_ACCOUNT_CANNOT_BE_DELETED');

    // 2. Attempt to change account type of system account
    $typeResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->putJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$arId}", [
            'type' => 'liability',
        ]);
    $typeResponse->assertStatus(400)
        ->assertJsonPath('code', 'SYSTEM_ACCOUNT_PROTECTED');

    // 3. Attempt to change code of system account
    $codeResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->putJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$arId}", [
            'code' => '9999',
        ]);
    $codeResponse->assertStatus(400)
        ->assertJsonPath('code', 'SYSTEM_ACCOUNT_PROTECTED');
});

test('deleting an account with children is rejected with 400 ACCOUNT_HAS_CHILDREN', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // Find Assets group account 1000 (which has child 1100, 1500, etc.)
    $findResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts?search=1000');
    $assetGroupId = $findResponse->json('data.0.id');

    $deleteResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->deleteJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$assetGroupId}");

    $deleteResponse->assertStatus(400)
        ->assertJsonPath('code', 'ACCOUNT_HAS_CHILDREN');
});

test('deleting an unused custom leaf account succeeds with 200', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    // Create a custom account
    $createResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '6980',
            'name' => 'Temporary Event Catering',
            'type' => 'expense',
        ]);
    $accountId = $createResponse->json('data.id');

    // Delete it
    $deleteResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->deleteJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$accountId}");

    $deleteResponse->assertOk()
        ->assertJsonPath('success', true);

    // Verify it is gone
    $showResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson("http://coaalpha.nexuserp.test/api/v1/accounting/accounts/{$accountId}");
    $showResponse->assertStatus(404);
});

test('user without accounting permission cannot access accounting endpoints (403)', function (): void {
    /** @var TestCase $this */
    $sessionCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'warehouse@coaalpha.nexuserp.test');

    $getResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts');
    $getResponse->assertStatus(403);

    $postResponse = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '9999',
            'name' => 'Unauthorized Account',
            'type' => 'asset',
        ]);
    $postResponse->assertStatus(403);
});

test('multi-tenant data isolation: accounts in Tenant Alpha are not visible to Tenant Beta', function (): void {
    /** @var TestCase $this */
    // 1. Alpha logs in and creates a unique account
    $alphaCookie = loginAsCoaTenant($this, 'coaalpha.nexuserp.test', 'accountant@coaalpha.nexuserp.test');

    $alphaResponse = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://coaalpha.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '1199',
            'name' => 'Alpha Exclusive Secret Vault Account',
            'type' => 'asset',
        ]);
    $alphaResponse->assertStatus(201);
    $alphaAccountId = $alphaResponse->json('data.id');

    // 2. Beta logs in
    $betaCookie = loginAsCoaTenant($this, 'coabeta.nexuserp.test', 'owner@coabeta.nexuserp.test');

    // Verify Beta cannot see Alpha's unique account via list or show
    $betaListResponse = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://coabeta.nexuserp.test/api/v1/accounting/accounts?search=1199');
    $betaListResponse->assertOk();
    expect($betaListResponse->json('data'))->toBeEmpty();

    $betaShowResponse = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://coabeta.nexuserp.test/api/v1/accounting/accounts/{$alphaAccountId}");
    $betaShowResponse->assertStatus(404);

    // Verify Beta can create an account with code '1199' without colliding with Alpha's code
    $betaCreateResponse = $this->withCookie(config('session.cookie'), $betaCookie)
        ->postJson('http://coabeta.nexuserp.test/api/v1/accounting/accounts', [
            'code' => '1199',
            'name' => 'Beta Independent Asset Account',
            'type' => 'asset',
        ]);
    $betaCreateResponse->assertStatus(201);
});
