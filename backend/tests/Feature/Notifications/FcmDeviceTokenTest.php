<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\FcmDeviceToken;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropFcmDeviceTokenTestDatabases')) {
    function dropFcmDeviceTokenTestDatabases(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_fcm_token_alpha', 'tenant_fcm_token_beta'], true)) {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

beforeEach(function (): void {
    app('cache')->flush();
    try {
        Cache::store('redis')->flush();
    } catch (Throwable) {
        // Redis store might not be available
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropFcmDeviceTokenTestDatabases('tenant_fcm_token_alpha');
    dropFcmDeviceTokenTestDatabases('tenant_fcm_token_beta');

    Domain::query()->whereIn('host', ['alpha-fcm.nexuserp.test', 'beta-fcm.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-fcm', 'beta-fcm'])->delete();

    // Tenant Alpha
    $tenantAlphaId = (string) Str::ulid();
    $tenantAlpha = Tenant::on('central')->create([
        'id' => $tenantAlphaId,
        'name' => 'Alpha FCM Corp',
        'slug' => 'alpha-fcm',
        'database_name' => 'tenant_fcm_token_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantAlphaId,
        'host' => 'alpha-fcm.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantAlphaId,
        ownerEmail: 'owner@alpha-fcm.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // Tenant Beta
    $tenantBetaId = (string) Str::ulid();
    $tenantBeta = Tenant::on('central')->create([
        'id' => $tenantBetaId,
        'name' => 'Beta FCM Corp',
        'slug' => 'beta-fcm',
        'database_name' => 'tenant_fcm_token_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantBetaId,
        'host' => 'beta-fcm.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantBetaId,
        ownerEmail: 'owner@beta-fcm.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // Setup Alpha
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenantAlpha->id,
        slug: $tenantAlpha->slug,
        databaseName: $tenantAlpha->database_name,
    ));

    TenantUser::where('email', 'owner@alpha-fcm.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->alphaUser = TenantUser::where('email', 'owner@alpha-fcm.nexuserp.test')->firstOrFail();
    $this->alphaTenant = $tenantAlpha;

    // Setup Beta
    $tenantManager->switchTo(new TenantContext(
        id: $tenantBeta->id,
        slug: $tenantBeta->slug,
        databaseName: $tenantBeta->database_name,
    ));

    TenantUser::where('email', 'owner@beta-fcm.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->betaUser = TenantUser::where('email', 'owner@beta-fcm.nexuserp.test')->firstOrFail();
    $this->betaTenant = $tenantBeta;

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropFcmDeviceTokenTestDatabases('tenant_fcm_token_alpha');
    dropFcmDeviceTokenTestDatabases('tenant_fcm_token_beta');
});

test('POST /api/v1/notifications/fcm-tokens registers a new device token for authenticated user', function (): void {
    $response = $this->actingAs($this->alphaUser, 'web')
        ->postJson('http://alpha-fcm.nexuserp.test/api/v1/notifications/fcm-tokens', [
            'token' => 'fcm_token_xyz_123',
            'platform' => 'web',
            'device_name' => 'Chrome on MacOS',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token', 'fcm_token_xyz_123')
        ->assertJsonPath('data.platform', 'web')
        ->assertJsonPath('data.device_name', 'Chrome on MacOS');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    expect(FcmDeviceToken::where('token', 'fcm_token_xyz_123')->exists())->toBeTrue()
        ->and(FcmDeviceToken::where('token', 'fcm_token_xyz_123')->first()->user_id)->toBe($this->alphaUser->id);
});

test('POST /api/v1/notifications/fcm-tokens re-registers token idempotently and updates details', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    FcmDeviceToken::create([
        'user_id' => $this->alphaUser->id,
        'token' => 'fcm_existing_token',
        'platform' => 'android',
        'device_name' => 'Old Device',
        'last_used_at' => now()->subDays(5),
    ]);

    $tenantManager->reset();

    $response = $this->actingAs($this->alphaUser, 'web')
        ->postJson('http://alpha-fcm.nexuserp.test/api/v1/notifications/fcm-tokens', [
            'token' => 'fcm_existing_token',
            'platform' => 'web',
            'device_name' => 'Updated MacBook Pro',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.platform', 'web')
        ->assertJsonPath('data.device_name', 'Updated MacBook Pro');

    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    expect(FcmDeviceToken::where('token', 'fcm_existing_token')->count())->toBe(1);
});

test('DELETE /api/v1/notifications/fcm-tokens deletes device token', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    FcmDeviceToken::create([
        'user_id' => $this->alphaUser->id,
        'token' => 'token_to_remove',
        'platform' => 'web',
        'last_used_at' => now(),
    ]);

    $tenantManager->reset();

    $response = $this->actingAs($this->alphaUser, 'web')
        ->deleteJson('http://alpha-fcm.nexuserp.test/api/v1/notifications/fcm-tokens', [
            'token' => 'token_to_remove',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    expect(FcmDeviceToken::where('token', 'token_to_remove')->exists())->toBeFalse();
});

test('unauthenticated request to register device token is rejected with 401', function (): void {
    $response = $this->postJson('http://alpha-fcm.nexuserp.test/api/v1/notifications/fcm-tokens', [
        'token' => 'token_anon',
    ]);

    $response->assertUnauthorized();
});

test('device tokens are strictly isolated per tenant database', function (): void {
    // Register token in Alpha
    $this->actingAs($this->alphaUser, 'web')
        ->postJson('http://alpha-fcm.nexuserp.test/api/v1/notifications/fcm-tokens', [
            'token' => 'alpha_exclusive_token',
            'platform' => 'web',
        ])
        ->assertCreated();

    // Verify token exists in Alpha
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));
    expect(FcmDeviceToken::where('token', 'alpha_exclusive_token')->exists())->toBeTrue();

    // Switch to Beta and verify token DOES NOT exist in Beta
    $tenantManager->switchTo(new TenantContext(
        id: $this->betaTenant->id,
        slug: $this->betaTenant->slug,
        databaseName: $this->betaTenant->database_name,
    ));
    expect(FcmDeviceToken::where('token', 'alpha_exclusive_token')->exists())->toBeFalse();
});
