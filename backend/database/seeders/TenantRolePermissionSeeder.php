<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Enums\TenantPermission;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;

class TenantRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed all permissions
        foreach (TenantPermission::cases() as $permissionEnum) {
            Permission::firstOrCreate([
                'name' => $permissionEnum->value,
                'guard_name' => 'web',
            ]);
        }

        // 2. Seed all roles and sync their permission matrix
        foreach (TenantRole::cases() as $roleEnum) {
            /** @var Role $role */
            $role = Role::firstOrCreate(
                [
                    'name' => $roleEnum->value,
                    'guard_name' => 'web',
                ],
                [
                    'display_name' => $roleEnum->label(),
                    'description' => "System role for {$roleEnum->label()}",
                ]
            );

            if (empty($role->display_name)) {
                $role->update([
                    'display_name' => $roleEnum->label(),
                    'description' => "System role for {$roleEnum->label()}",
                ]);
            }

            $permissionNames = array_map(
                static fn (TenantPermission $p): string => $p->value,
                $roleEnum->permissions()
            );

            $role->syncPermissions($permissionNames);
        }

        // Alias 'warehouse' role to match Phase 1 legacy references
        /** @var Role $warehouseRole */
        $warehouseRole = Role::firstOrCreate(
            [
                'name' => 'warehouse',
                'guard_name' => 'web',
            ],
            [
                'display_name' => 'Warehouse Manager',
                'description' => 'System role for Warehouse Manager (legacy alias)',
            ]
        );

        $warehousePermissions = array_map(
            static fn (TenantPermission $p): string => $p->value,
            TenantRole::WarehouseManager->permissions()
        );
        $warehouseRole->syncPermissions($warehousePermissions);
    }
}
