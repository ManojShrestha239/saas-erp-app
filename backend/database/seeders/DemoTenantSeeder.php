<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Tenant;
use Shared\Http\Middleware\IdentifyTenant;

final class DemoTenantSeeder extends Seeder
{
    public const TENANT_ID = '01M5WERPRYA9W846KK8ANQASJ5';

    public function run(): void
    {
        Tenant::on('central')->updateOrInsert(
            ['id' => self::TENANT_ID],
            [
                'name' => 'Demo Corp',
                'slug' => 'demo',
                'database_name' => 'tenant_demo',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::connection('central')->table('domains')->updateOrInsert(
            ['host' => 'demo.nexuserp.test'],
            [
                'tenant_id' => self::TENANT_ID,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::connection('central')->table('domains')->updateOrInsert(
            ['host' => 'demo.localhost'],
            [
                'tenant_id' => self::TENANT_ID,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        IdentifyTenant::invalidateCache('demo');
        IdentifyTenant::invalidateCache('demo.localhost');
        IdentifyTenant::invalidateCache('demo.nexuserp.test');
    }
}
