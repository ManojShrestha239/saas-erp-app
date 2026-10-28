<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\SuperAdminSeeder;
use Database\Seeders\TenantAdminSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\TenantUser;
use Modules\SuperAdmin\Models\SuperAdmin;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;

beforeEach(function (): void {
    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);
});

test('SuperAdminSeeder seeds super admin with valid credentials', function (): void {
    $seeder = new SuperAdminSeeder;
    $seeder->run();

    /** @var SuperAdmin|null $superAdmin */
    $superAdmin = SuperAdmin::query()->where('email', SuperAdminSeeder::DEFAULT_EMAIL)->first();

    expect($superAdmin)->not->toBeNull();
    assert($superAdmin instanceof SuperAdmin);

    expect($superAdmin->name)->toBe(SuperAdminSeeder::DEFAULT_NAME)
        ->and($superAdmin->email)->toBe('admin@nexuserp.test')
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check(SuperAdminSeeder::DEFAULT_PASSWORD, $superAdmin->password))->toBeTrue();
});

test('TenantAdminSeeder seeds tenant admin and owner with correct roles and credentials', function (): void {
    $seeder = new TenantAdminSeeder;
    $seeder->run();

    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: DemoTenantSeeder::TENANT_ID,
        slug: 'demo',
        databaseName: 'tenant_demo',
    ));

    try {
        /** @var TenantUser|null $owner */
        $owner = TenantUser::query()->where('email', TenantAdminSeeder::DEFAULT_OWNER_EMAIL)->first();
        expect($owner)->not->toBeNull();
        assert($owner instanceof TenantUser);

        expect($owner->name)->toBe(TenantAdminSeeder::DEFAULT_OWNER_NAME)
            ->and($owner->status)->toBe('active')
            ->and($owner->email_verified_at)->not->toBeNull()
            ->and(Hash::check(TenantAdminSeeder::DEFAULT_OWNER_PASSWORD, $owner->password))->toBeTrue()
            ->and($owner->hasRole('owner'))->toBeTrue();

        /** @var TenantUser|null $admin */
        $admin = TenantUser::query()->where('email', TenantAdminSeeder::DEFAULT_ADMIN_EMAIL)->first();
        expect($admin)->not->toBeNull();
        assert($admin instanceof TenantUser);

        expect($admin->name)->toBe(TenantAdminSeeder::DEFAULT_ADMIN_NAME)
            ->and($admin->status)->toBe('active')
            ->and($admin->email_verified_at)->not->toBeNull()
            ->and(Hash::check(TenantAdminSeeder::DEFAULT_ADMIN_PASSWORD, $admin->password))->toBeTrue()
            ->and($admin->hasRole('admin'))->toBeTrue();
    } finally {
        $tenantManager->reset();
    }
});

test('DatabaseSeeder runs idempotently and allows authentication for super admin and tenant admin', function (): void {
    /** @var TestCase $this */
    // Run twice to ensure idempotency
    $seeder = new DatabaseSeeder;
    $seeder->run();
    $seeder->run();

    // 1. Super admin authentication test on central domain
    $saResponse = $this->postJson('http://localhost/api/v1/super-admin/login', [
        'email' => SuperAdminSeeder::DEFAULT_EMAIL,
        'password' => SuperAdminSeeder::DEFAULT_PASSWORD,
    ]);

    $saResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', SuperAdminSeeder::DEFAULT_EMAIL);

    // 2. Tenant admin authentication test on tenant domain
    $adminResponse = $this->postJson('http://demo.localhost/api/v1/auth/login', [
        'email' => TenantAdminSeeder::DEFAULT_ADMIN_EMAIL,
        'password' => TenantAdminSeeder::DEFAULT_ADMIN_PASSWORD,
    ]);

    $adminResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', TenantAdminSeeder::DEFAULT_ADMIN_EMAIL)
        ->assertJsonPath('data.user.roles.0', 'admin');

    // 3. Tenant owner authentication test on tenant domain
    $ownerResponse = $this->postJson('http://demo.localhost/api/v1/auth/login', [
        'email' => TenantAdminSeeder::DEFAULT_OWNER_EMAIL,
        'password' => TenantAdminSeeder::DEFAULT_OWNER_PASSWORD,
    ]);

    $ownerResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', TenantAdminSeeder::DEFAULT_OWNER_EMAIL)
        ->assertJsonPath('data.user.roles.0', 'owner');
});
