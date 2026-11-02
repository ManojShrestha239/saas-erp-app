<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SuperAdmin\Models\FeatureFlag;
use Modules\SuperAdmin\Models\Package;

final class PackageSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Packages
        $packages = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'max_users' => 3,
                'max_warehouses' => 1,
                'max_invoices_per_month' => 100,
                'features' => [
                    'multi_warehouse' => false,
                    'api_access' => false,
                    'advanced_reports' => false,
                    'priority_support' => false,
                ],
                'price_cents' => 2900,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'max_users' => 10,
                'max_warehouses' => 3,
                'max_invoices_per_month' => 500,
                'features' => [
                    'multi_warehouse' => true,
                    'api_access' => false,
                    'advanced_reports' => true,
                    'priority_support' => false,
                ],
                'price_cents' => 7900,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'max_users' => 50,
                'max_warehouses' => 10,
                'max_invoices_per_month' => 5000,
                'features' => [
                    'multi_warehouse' => true,
                    'api_access' => true,
                    'advanced_reports' => true,
                    'priority_support' => true,
                ],
                'price_cents' => 19900,
            ],
        ];

        foreach ($packages as $pkg) {
            Package::on('central')->updateOrCreate(
                ['slug' => $pkg['slug']],
                $pkg,
            );
        }

        // 2. Seed Feature Flags
        $flags = [
            [
                'key' => 'public_registration',
                'name' => 'Public Self-Registration',
                'description' => 'Allow new customer companies to register and self-provision tenants.',
                'is_enabled' => true,
            ],
            [
                'key' => 'maintenance_mode',
                'name' => 'Global Maintenance Mode',
                'description' => 'Display maintenance notice across central domain during scheduled upgrades.',
                'is_enabled' => false,
            ],
            [
                'key' => 'beta_features',
                'name' => 'Early Access Beta Features',
                'description' => 'Enable preview access to experimental platform features.',
                'is_enabled' => false,
            ],
            [
                'key' => 'impersonation',
                'name' => 'Tenant Impersonation',
                'description' => 'Allow super admins to view tenant portals in read-only audit mode.',
                'is_enabled' => true,
            ],
        ];

        foreach ($flags as $flag) {
            FeatureFlag::on('central')->updateOrCreate(
                ['key' => $flag['key']],
                $flag,
            );
        }
    }
}
