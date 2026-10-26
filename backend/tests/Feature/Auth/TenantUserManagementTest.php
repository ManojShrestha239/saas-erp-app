<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropUserMgmtTestTenantDatabase')) {
    function dropUserMgmtTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha', 'tenant_beta'], true)) {
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

    dropUserMgmtTestTenantDatabase('tenant_alpha');
    dropUserMgmtTestTenantDatabase('tenant_beta');

    DB::connection('central')->table('subscriptions')->delete();
    DB::connection('central')->table('packages')->delete();
    Domain::query()->whereIn('host', ['alpha.nexuserp.test', 'beta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha', 'beta'])->delete();

    // 1. Provision alpha tenant
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha Corp',
        'slug' => 'alpha',
        'database_name' => 'tenant_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision beta tenant
    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Corp',
        'slug' => 'beta',
        'database_name' => 'tenant_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // Set known password for alpha owner
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaTenant->id,
        slug: $alphaTenant->slug,
        databaseName: $alphaTenant->database_name,
    ));
    TenantUser::where('email', 'owner@alpha.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);

    // Create an accountant on alpha for authorization tests
    $accountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Staff',
        'email' => 'staff@alpha.nexuserp.test',
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $accountant->assignRole(TenantRole::Accountant);

    $tenantManager->reset();

    $betaContext = new TenantContext(id: $betaTenant->id, slug: $betaTenant->slug, databaseName: $betaTenant->database_name);
    $tenantManager->switchTo($betaContext);
    DB::connection('tenant')->table('users')->where('email', 'owner@beta.nexuserp.test')->update([
        'password' => Hash::make('BetaSecret123!'),
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

    dropUserMgmtTestTenantDatabase('tenant_alpha');
    dropUserMgmtTestTenantDatabase('tenant_beta');
});

function loginAsAlphaUser(mixed $testCase, string $email = 'owner@alpha.nexuserp.test', string $password = 'AlphaSecret123!'): string
{
    $response = $testCase->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

test('unauthenticated request to user management returns 401 UNAUTHENTICATED', function (): void {
    $response = $this->getJson('http://alpha.nexuserp.test/api/v1/users');
    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'code' => 'UNAUTHENTICATED',
        ]);

    $invite = $this->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
        'name' => 'Hacker',
        'email' => 'hacker@example.com',
        'role' => 'owner',
    ]);
    $invite->assertStatus(401);
});

test('unauthorized user without users.manage permission returns 403 FORBIDDEN', function (): void {
    $sessionCookie = loginAsAlphaUser($this, 'staff@alpha.nexuserp.test');

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://alpha.nexuserp.test/api/v1/users');

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'FORBIDDEN',
        ]);

    $invite = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'New Guy',
            'email' => 'newguy@alpha.nexuserp.test',
            'role' => 'accountant',
        ]);
    $invite->assertStatus(403);
});

test('owner can list paginated tenant users with roles and status', function (): void {
    $sessionCookie = loginAsAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://alpha.nexuserp.test/api/v1/users?per_page=10');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Users retrieved successfully.',
        ]);

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('data'))->toHaveCount(2);

    $ownerData = collect($response->json('data'))->firstWhere('email', 'owner@alpha.nexuserp.test');
    expect($ownerData)->not->toBeNull()
        ->and($ownerData['status'])->toBe('active')
        ->and($ownerData['roles'])->toContain('owner');
});

test('owner can invite a new tenant user and assign a role', function (): void {
    $sessionCookie = loginAsAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'Alice Warehouse',
            'email' => 'alice@alpha.nexuserp.test',
            'role' => 'warehouse_manager',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'User invited successfully.',
            'data' => [
                'name' => 'Alice Warehouse',
                'email' => 'alice@alpha.nexuserp.test',
                'status' => 'active',
                'roles' => ['warehouse_manager'],
            ],
        ]);

    // Verify stored on tenant database with role
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $user = TenantUser::where('email', 'alice@alpha.nexuserp.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole(TenantRole::WarehouseManager))->toBeTrue();
});

test('inviting a duplicate email fails with 422 VALIDATION_FAILED', function (): void {
    $sessionCookie = loginAsAlphaUser($this);

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'Duplicate Owner',
            'email' => 'owner@alpha.nexuserp.test',
            'role' => 'accountant',
        ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'code' => 'VALIDATION_FAILED',
        ]);
});

test('owner can update a tenant user role', function (): void {
    $sessionCookie = loginAsAlphaUser($this);

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $staff = TenantUser::where('email', 'staff@alpha.nexuserp.test')->firstOrFail();
    $tenantManager->reset();

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->patchJson("http://alpha.nexuserp.test/api/v1/users/{$staff->id}/role", [
            'role' => 'sales_rep',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'User role updated successfully.',
            'data' => [
                'email' => 'staff@alpha.nexuserp.test',
                'roles' => ['sales_rep'],
            ],
        ]);

    // Verify in tenant DB
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $staff->refresh();
    expect($staff->hasRole(TenantRole::SalesRep))->toBeTrue()
        ->and($staff->hasRole(TenantRole::Accountant))->toBeFalse();
});

test('cannot demote the sole active owner', function (): void {
    $sessionCookie = loginAsAlphaUser($this);

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $owner = TenantUser::where('email', 'owner@alpha.nexuserp.test')->firstOrFail();
    $tenantManager->reset();

    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->patchJson("http://alpha.nexuserp.test/api/v1/users/{$owner->id}/role", [
            'role' => 'accountant',
        ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'code' => 'CANNOT_DEMOTE_SOLE_OWNER',
        ]);
});

test('owner can deactivate a user, deactivated user cannot log in, and self-deactivation is blocked', function (): void {
    $sessionCookie = loginAsAlphaUser($this);

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $owner = TenantUser::where('email', 'owner@alpha.nexuserp.test')->firstOrFail();
    $staff = TenantUser::where('email', 'staff@alpha.nexuserp.test')->firstOrFail();
    $tenantManager->reset();

    // 1. Owner attempts to deactivate self -> blocked by policy
    $selfDeact = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->patchJson("http://alpha.nexuserp.test/api/v1/users/{$owner->id}/status", [
            'status' => 'deactivated',
        ]);
    $selfDeact->assertStatus(403);

    // 2. Owner deactivates staff -> succeeds
    $staffDeact = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->patchJson("http://alpha.nexuserp.test/api/v1/users/{$staff->id}/status", [
            'status' => 'deactivated',
        ]);
    $staffDeact->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'email' => 'staff@alpha.nexuserp.test',
                'status' => 'deactivated',
            ],
        ]);

    // 3. Deactivated staff attempts to login -> 403 ACCOUNT_DEACTIVATED
    $loginFail = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'staff@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);
    $loginFail->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'ACCOUNT_DEACTIVATED',
        ]);

    // 4. Reactivate staff -> succeeds and login works again
    $reactivate = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->patchJson("http://alpha.nexuserp.test/api/v1/users/{$staff->id}/status", [
            'status' => 'active',
        ]);
    $reactivate->assertStatus(200);

    $loginSuccess = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'staff@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);
    $loginSuccess->assertStatus(200);
});

test('subscription package user limit is enforced and blocks invitations exceeding limit', function (): void {
    // 1. Create a package on central with max_users = 2
    $packageId = DB::connection('central')->table('packages')->insertGetId([
        'name' => 'Starter',
        'slug' => 'starter',
        'max_users' => 2,
        'max_warehouses' => 1,
        'max_invoices_per_month' => 50,
        'features' => json_encode(['basic' => true]),
        'price_cents' => 200000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Assign active subscription to Alpha tenant
    DB::connection('central')->table('subscriptions')->insert([
        'tenant_id' => $this->alphaTenant->id,
        'package_id' => $packageId,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Alpha tenant currently has 2 active users: owner and staff (limit = 2)
    $sessionCookie = loginAsAlphaUser($this);

    // 3. Attempting to invite a 3rd user -> 403 PACKAGE_LIMIT_EXCEEDED
    $response = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'Third User',
            'email' => 'third@alpha.nexuserp.test',
            'role' => 'sales_rep',
        ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'code' => 'PACKAGE_LIMIT_EXCEEDED',
        ]);

    // 4. Deactivate staff -> active count drops to 1
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    $staff = TenantUser::where('email', 'staff@alpha.nexuserp.test')->firstOrFail();
    $tenantManager->reset();

    $this->withCookie(config('session.cookie'), $sessionCookie)
        ->patchJson("http://alpha.nexuserp.test/api/v1/users/{$staff->id}/status", [
            'status' => 'deactivated',
        ]);

    // 5. Now invite 3rd user -> succeeds (now 2 active users)
    $retryInvite = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'Third User',
            'email' => 'third@alpha.nexuserp.test',
            'role' => 'sales_rep',
        ]);

    $retryInvite->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'email' => 'third@alpha.nexuserp.test',
                'status' => 'active',
            ],
        ]);
});

test('user management is strictly isolated between tenants', function (): void {
    $alphaCookie = loginAsAlphaUser($this);

    // Invite user on alpha
    $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://alpha.nexuserp.test/api/v1/users/invite', [
            'name' => 'Alpha Specific',
            'email' => 'specific@alpha.nexuserp.test',
            'role' => 'sales_rep',
        ]);

    // Clear previous tenant session and cookies from test instance
    $this->flushSession();
    $this->defaultCookies = [];

    // Login as Beta owner
    $betaLogin = $this->postJson('http://beta.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@beta.nexuserp.test',
        'password' => 'BetaSecret123!',
    ]);
    $betaLogin->assertStatus(200);
    $betaCookie = $betaLogin->getCookie(config('session.cookie'))->getValue();

    // Beta owner lists users
    $betaUsers = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://beta.nexuserp.test/api/v1/users');

    $betaUsers->assertStatus(200);
    $emails = collect($betaUsers->json('data'))->pluck('email')->all();

    expect($emails)->toContain('owner@beta.nexuserp.test')
        ->and($emails)->not->toContain('specific@alpha.nexuserp.test')
        ->and($emails)->not->toContain('owner@alpha.nexuserp.test');
});
