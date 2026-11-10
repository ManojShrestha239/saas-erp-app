<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Database\Seeders\PackageSeeder;
use Database\Seeders\SuperAdminSeeder;
use Modules\SuperAdmin\Models\FeatureFlag;
use Modules\SuperAdmin\Models\GlobalAuditLog;
use Modules\SuperAdmin\Models\SuperAdmin;
use Shared\Exceptions\ApiException;

beforeEach(function (): void {
    GlobalAuditLog::query()->delete();

    $this->seed(SuperAdminSeeder::class);
    $this->seed(PackageSeeder::class);
});

afterEach(function (): void {
    GlobalAuditLog::query()->delete();
});

function loginSuperAdminUser(): SuperAdmin
{
    /** @var SuperAdmin $admin */
    $admin = SuperAdmin::on('central')->where('email', SuperAdminSeeder::DEFAULT_EMAIL)->firstOrFail();
    test()->actingAs($admin, 'super_admin');

    return $admin;
}

test('central global audit logs are strictly immutable and cannot be updated or deleted', function (): void {
    $log = GlobalAuditLog::create([
        'event' => 'platform.initialized',
        'user_id' => 'admin-001',
        'ip_address' => '127.0.0.1',
    ]);

    expect($log->id)->not->toBeNull();

    // Updating MUST fail
    expect(fn () => $log->update(['event' => 'tampered.event']))
        ->toThrow(ApiException::class, 'Audit logs are immutable and cannot be updated.');

    expect($log->fresh()->event)->toBe('platform.initialized');

    // Deleting MUST fail
    expect(fn () => $log->delete())
        ->toThrow(ApiException::class, 'Audit logs are immutable and cannot be deleted.');

    expect(GlobalAuditLog::find($log->id))->not->toBeNull();
});

test('super admin package and feature flag management automatically records global audit log', function (): void {
    loginSuperAdminUser();

    $uniqueSlug = 'audited-plan-'.uniqid();

    // 1. Create a Package
    $pkgResp = $this->postJson('http://nexuserp.test/api/v1/super-admin/packages', [
        'name' => 'Audited Enterprise Plan',
        'slug' => $uniqueSlug,
        'max_users' => 100,
        'max_warehouses' => 10,
        'max_invoices_per_month' => 5000,
        'features' => ['audit_logs', 'multi_currency'],
        'price_cents' => 99900,
    ]);
    $pkgResp->assertCreated();

    $packageLog = GlobalAuditLog::where('event', 'package.created')->first();
    expect($packageLog)->not->toBeNull()
        ->and($packageLog->new_values)->toHaveKey('slug', $uniqueSlug);

    // 2. Toggle Feature Flag
    FeatureFlag::query()->updateOrCreate(
        ['key' => 'central_audit_toggle_test'],
        ['name' => 'Audit Toggle Test', 'is_enabled' => false]
    );

    $toggleResp = $this->putJson('http://nexuserp.test/api/v1/super-admin/feature-flags/central_audit_toggle_test/toggle');
    $toggleResp->assertOk();

    $flagLog = GlobalAuditLog::where('event', 'feature_flag.toggled')
        ->where('auditable_id', 'central_audit_toggle_test')
        ->first();

    expect($flagLog)->not->toBeNull()
        ->and($flagLog->new_values)->toHaveKey('is_enabled', true);
});

test('super admin audit logs API endpoint requires super_admin auth and supports filtering', function (): void {
    // Unauthenticated -> 401
    $this->getJson('http://nexuserp.test/api/v1/super-admin/audit-logs')
        ->assertUnauthorized();

    // Authenticated
    loginSuperAdminUser();

    GlobalAuditLog::create([
        'event' => 'platform.setting_changed',
        'auditable_type' => 'App\Setting',
        'auditable_id' => '1',
    ]);
    GlobalAuditLog::create([
        'event' => 'package.deleted',
        'auditable_id' => '99',
    ]);

    $resp = $this->getJson('http://nexuserp.test/api/v1/super-admin/audit-logs');
    $resp->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'event', 'tenant_id', 'created_at'],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

    // Filter event
    $respFiltered = $this->getJson('http://nexuserp.test/api/v1/super-admin/audit-logs?event=package.deleted');
    $respFiltered->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'package.deleted');
});
