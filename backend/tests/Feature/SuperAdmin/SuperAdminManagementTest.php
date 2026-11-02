<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Database\Seeders\PackageSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Modules\SuperAdmin\Models\FeatureFlag;
use Modules\SuperAdmin\Models\Package;
use Modules\SuperAdmin\Models\Subscription;
use Modules\SuperAdmin\Models\SuperAdmin;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;

beforeEach(function (): void {
    Subscription::query()->delete();
    Domain::query()->delete();
    Tenant::query()->delete();

    // Seed central super admin and packages
    $this->seed(SuperAdminSeeder::class);
    $this->seed(PackageSeeder::class);
});

afterEach(function (): void {
    Subscription::query()->delete();
    Domain::query()->delete();
    Tenant::query()->delete();
});

function loginSuperAdmin(): SuperAdmin
{
    /** @var SuperAdmin $admin */
    $admin = SuperAdmin::on('central')->where('email', SuperAdminSeeder::DEFAULT_EMAIL)->firstOrFail();
    test()->actingAs($admin, 'super_admin');

    return $admin;
}

test('super admin endpoints reject unauthenticated access with 401', function (): void {
    $response = $this->getJson('http://nexuserp.test/api/v1/super-admin/tenants');
    $response->assertStatus(401);

    $statsResponse = $this->getJson('http://nexuserp.test/api/v1/super-admin/dashboard/stats');
    $statsResponse->assertStatus(401);
});

test('super admin endpoints reject requests from tenant subdomains with 404', function (): void {
    loginSuperAdmin();

    $response = $this->getJson('http://alpha.nexuserp.test/api/v1/super-admin/tenants');
    $response->assertStatus(404)
        ->assertJsonPath('code', 'ROUTE_NOT_FOUND');
});

test('dashboard stats returns accurate metrics across tenants, packages, and subscriptions', function (): void {
    loginSuperAdmin();

    // Create test tenants in central DB
    $t1 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Active Tenant 1',
        'slug' => 'active-1',
        'database_name' => 'tenant_active_1',
        'status' => 'active',
    ]);
    $t2 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Suspended Tenant 2',
        'slug' => 'suspended-2',
        'database_name' => 'tenant_suspended_2',
        'status' => 'suspended',
    ]);
    $t3 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Failed Tenant 3',
        'slug' => 'failed-3',
        'database_name' => 'tenant_failed_3',
        'status' => 'provisioning_failed',
        'provisioning_error' => 'Database timeout',
    ]);

    /** @var Package $pkg */
    $pkg = Package::on('central')->where('slug', 'professional')->firstOrFail();

    Subscription::create([
        'tenant_id' => $t1->id,
        'package_id' => $pkg->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $response = $this->getJson('http://nexuserp.test/api/v1/super-admin/dashboard/stats');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.tenants.active', 1)
        ->assertJsonPath('data.tenants.suspended', 1)
        ->assertJsonPath('data.tenants.provisioning_failed', 1)
        ->assertJsonPath('data.active_subscriptions_count', 1);

    expect($response->json('data.packages_count'))->toBeGreaterThanOrEqual(3);
});

test('super admin can list tenants with search and status filtering', function (): void {
    loginSuperAdmin();

    $t1 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Acme Corporation',
        'slug' => 'acme-corp',
        'database_name' => 'tenant_acme',
        'status' => 'active',
    ]);
    Domain::create(['tenant_id' => $t1->id, 'host' => 'acme.nexuserp.test', 'is_primary' => true]);

    $t2 = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Beta Logistics',
        'slug' => 'beta-logistics',
        'database_name' => 'tenant_beta',
        'status' => 'suspended',
    ]);
    Domain::create(['tenant_id' => $t2->id, 'host' => 'beta.nexuserp.test', 'is_primary' => true]);

    // Filter by status = active
    $resActive = $this->getJson('http://nexuserp.test/api/v1/super-admin/tenants?status=active');
    $resActive->assertStatus(200)
        ->assertJsonPath('success', true);
    $slugsActive = collect($resActive->json('data'))->pluck('slug');
    expect($slugsActive)->toContain('acme-corp')
        ->and($slugsActive)->not->toContain('beta-logistics');

    // Search by name
    $resSearch = $this->getJson('http://nexuserp.test/api/v1/super-admin/tenants?search=Logistics');
    $resSearch->assertStatus(200);
    $slugsSearch = collect($resSearch->json('data'))->pluck('slug');
    expect($slugsSearch)->toContain('beta-logistics')
        ->and($slugsSearch)->not->toContain('acme-corp');
});

test('super admin can suspend and reactivate a tenant', function (): void {
    loginSuperAdmin();

    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Toggle Tenant',
        'slug' => 'toggle-tenant',
        'database_name' => 'tenant_toggle',
        'status' => 'active',
    ]);
    Domain::create(['tenant_id' => $tenant->id, 'host' => 'toggle.nexuserp.test', 'is_primary' => true]);

    // Suspend
    $suspendRes = $this->postJson("http://nexuserp.test/api/v1/super-admin/tenants/{$tenant->id}/suspend", [
        'reason' => 'Payment delinquency',
    ]);
    $suspendRes->assertStatus(200)
        ->assertJsonPath('data.status', 'suspended');

    $tenant->refresh();
    expect($tenant->status)->toBe('suspended');

    // Reactivate
    $reactivateRes = $this->postJson("http://nexuserp.test/api/v1/super-admin/tenants/{$tenant->id}/reactivate");
    $reactivateRes->assertStatus(200)
        ->assertJsonPath('data.status', 'active');

    $tenant->refresh();
    expect($tenant->status)->toBe('active');
});

test('super admin can retry failed provisioning on a tenant', function (): void {
    Bus::fake([ProvisionTenantDatabase::class]);
    loginSuperAdmin();

    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Failed Co',
        'slug' => 'failed-co',
        'owner_email' => 'founder@failedco.com',
        'database_name' => 'tenant_failed_co',
        'status' => 'provisioning_failed',
        'provisioning_error' => 'Connection to postgres timed out during migration',
    ]);

    $retryRes = $this->postJson("http://nexuserp.test/api/v1/super-admin/tenants/{$tenant->id}/retry-provisioning");

    $retryRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.provisioning_error', null);

    $tenant->refresh();
    expect($tenant->status)->toBe('provisioning')
        ->and($tenant->provisioning_error)->toBeNull();

    Bus::assertDispatched(ProvisionTenantDatabase::class, function (ProvisionTenantDatabase $job) use ($tenant) {
        return $job->tenantId === $tenant->id && $job->ownerEmail === 'founder@failedco.com';
    });
});

test('retrying provisioning on an active tenant is rejected with 400', function (): void {
    loginSuperAdmin();

    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Active Co',
        'slug' => 'active-co',
        'database_name' => 'tenant_active_co',
        'status' => 'active',
    ]);

    $retryRes = $this->postJson("http://nexuserp.test/api/v1/super-admin/tenants/{$tenant->id}/retry-provisioning");

    $retryRes->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'INVALID_TENANT_STATUS');
});

test('super admin can perform CRUD on packages with integer price_cents and limits', function (): void {
    loginSuperAdmin();

    // 1. Create
    $createRes = $this->postJson('http://nexuserp.test/api/v1/super-admin/packages', [
        'name' => 'Startup Special',
        'slug' => 'startup-special',
        'max_users' => 5,
        'max_warehouses' => 2,
        'max_invoices_per_month' => 250,
        'features' => ['multi_warehouse' => true, 'api_access' => false],
        'price_cents' => 4900,
    ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.name', 'Startup Special')
        ->assertJsonPath('data.slug', 'startup-special')
        ->assertJsonPath('data.price_cents', 4900)
        ->assertJsonPath('data.price_formatted', '49.00');

    $pkgId = $createRes->json('data.id');

    // 2. Read
    $getRes = $this->getJson("http://nexuserp.test/api/v1/super-admin/packages/{$pkgId}");
    $getRes->assertStatus(200)
        ->assertJsonPath('data.name', 'Startup Special');

    // 3. Update
    $updateRes = $this->putJson("http://nexuserp.test/api/v1/super-admin/packages/{$pkgId}", [
        'name' => 'Startup Deluxe',
        'price_cents' => 5900,
        'max_users' => 7,
    ]);
    $updateRes->assertStatus(200)
        ->assertJsonPath('data.name', 'Startup Deluxe')
        ->assertJsonPath('data.price_cents', 5900)
        ->assertJsonPath('data.max_users', 7);

    // 4. Delete
    $deleteRes = $this->deleteJson("http://nexuserp.test/api/v1/super-admin/packages/{$pkgId}");
    $deleteRes->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(Package::on('central')->find($pkgId))->toBeNull();
});

test('deleting a package with active subscriptions is blocked with 400', function (): void {
    loginSuperAdmin();

    /** @var Package $pkg */
    $pkg = Package::on('central')->where('slug', 'starter')->firstOrFail();

    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Subscribed Corp',
        'slug' => 'subscribed-corp',
        'database_name' => 'tenant_subscribed',
        'status' => 'active',
    ]);

    Subscription::create([
        'tenant_id' => $tenant->id,
        'package_id' => $pkg->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $deleteRes = $this->deleteJson("http://nexuserp.test/api/v1/super-admin/packages/{$pkg->id}");
    $deleteRes->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'PACKAGE_IN_USE');
});

test('super admin can assign and cancel subscriptions for a tenant', function (): void {
    loginSuperAdmin();

    $tenant = Tenant::create([
        'id' => (string) Str::ulid(),
        'name' => 'Subscription Test Corp',
        'slug' => 'sub-test-corp',
        'database_name' => 'tenant_sub_test',
        'status' => 'active',
    ]);

    /** @var Package $starterPkg */
    $starterPkg = Package::on('central')->where('slug', 'starter')->firstOrFail();
    /** @var Package $proPkg */
    $proPkg = Package::on('central')->where('slug', 'professional')->firstOrFail();

    // 1. Assign Starter Subscription
    $assignRes1 = $this->postJson('http://nexuserp.test/api/v1/super-admin/subscriptions', [
        'tenant_id' => $tenant->id,
        'package_id' => $starterPkg->id,
        'starts_at' => now()->toDateString(),
    ]);

    $assignRes1->assertStatus(201)
        ->assertJsonPath('data.tenant_id', $tenant->id)
        ->assertJsonPath('data.package_id', $starterPkg->id)
        ->assertJsonPath('data.status', 'active');

    $sub1Id = (int) $assignRes1->json('data.id');

    // 2. Upgrade to Professional: Starter should be automatically cancelled
    $assignRes2 = $this->postJson('http://nexuserp.test/api/v1/super-admin/subscriptions', [
        'tenant_id' => $tenant->id,
        'package_id' => $proPkg->id,
        'starts_at' => now()->toDateString(),
    ]);

    $assignRes2->assertStatus(201)
        ->assertJsonPath('data.package_id', $proPkg->id)
        ->assertJsonPath('data.status', 'active');

    $sub2Id = (int) $assignRes2->json('data.id');

    // Verify sub1 is now cancelled
    /** @var Subscription $sub1 */
    $sub1 = Subscription::on('central')->find($sub1Id);
    expect($sub1->status)->toBe('cancelled')
        ->and($sub1->ends_at)->not->toBeNull();

    // 3. Cancel Professional subscription
    $cancelRes = $this->postJson("http://nexuserp.test/api/v1/super-admin/subscriptions/{$sub2Id}/cancel");
    $cancelRes->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');

    /** @var Subscription $sub2 */
    $sub2 = Subscription::on('central')->find($sub2Id);
    expect($sub2->status)->toBe('cancelled');
});

test('super admin can list and toggle feature flags', function (): void {
    loginSuperAdmin();

    $listRes = $this->getJson('http://nexuserp.test/api/v1/super-admin/feature-flags');
    $listRes->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(count($listRes->json('data')))->toBeGreaterThanOrEqual(4);

    // Toggle public_registration from true to false
    $toggleRes = $this->putJson('http://nexuserp.test/api/v1/super-admin/feature-flags/public_registration/toggle', [
        'is_enabled' => false,
    ]);

    $toggleRes->assertStatus(200)
        ->assertJsonPath('data.key', 'public_registration')
        ->assertJsonPath('data.is_enabled', false);

    /** @var FeatureFlag $flag */
    $flag = FeatureFlag::on('central')->where('key', 'public_registration')->firstOrFail();
    expect($flag->is_enabled)->toBeFalse();

    // Toggle without explicit is_enabled (flips current value)
    $flipRes = $this->putJson('http://nexuserp.test/api/v1/super-admin/feature-flags/public_registration/toggle');
    $flipRes->assertStatus(200)
        ->assertJsonPath('data.is_enabled', true);

    $flag->refresh();
    expect($flag->is_enabled)->toBeTrue();
});
