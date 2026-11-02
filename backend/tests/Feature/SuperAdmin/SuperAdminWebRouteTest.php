<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Database\Seeders\SuperAdminSeeder;
use Modules\SuperAdmin\Models\SuperAdmin;

beforeEach(function (): void {
    $this->seed(SuperAdminSeeder::class);
});

test('unauthenticated visitor to /admin/dashboard is redirected to /admin/login', function (): void {
    $response = $this->get('http://nexuserp.test/admin/dashboard');
    $response->assertRedirect('/admin/login');
});

test('unauthenticated visitor can view /admin/login page', function (): void {
    $response = $this->get('http://nexuserp.test/admin/login');
    $response->assertStatus(200);
});

test('authenticated super admin can access /admin/dashboard and tabs', function (): void {
    /** @var SuperAdmin $admin */
    $admin = SuperAdmin::on('central')->where('email', SuperAdminSeeder::DEFAULT_EMAIL)->firstOrFail();
    $this->actingAs($admin, 'super_admin');

    $this->get('http://nexuserp.test/admin/dashboard')
        ->assertStatus(200);

    $this->get('http://nexuserp.test/admin/tenants')
        ->assertStatus(200);

    $this->get('http://nexuserp.test/admin/packages')
        ->assertStatus(200);

    $this->get('http://nexuserp.test/admin/subscriptions')
        ->assertStatus(200);

    $this->get('http://nexuserp.test/admin/feature-flags')
        ->assertStatus(200);
});

test('visitor from tenant subdomain to /admin/dashboard receives 404', function (): void {
    $response = $this->get('http://alpha.nexuserp.test/admin/dashboard');
    $response->assertStatus(404);
});
