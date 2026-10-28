<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

final class TenantAdminSeeder extends Seeder
{
    public const DEFAULT_ADMIN_EMAIL = 'admin@demo.nexuserp.test';

    public const DEFAULT_ADMIN_NAME = 'Demo Admin';

    public const DEFAULT_ADMIN_PASSWORD = 'TenantAdmin123!';

    public const DEFAULT_OWNER_EMAIL = 'owner@demo.nexuserp.test';

    public const DEFAULT_OWNER_NAME = 'Demo Owner';

    public const DEFAULT_OWNER_PASSWORD = 'TenantAdmin123!';

    public const DEFAULT_STAFF_PASSWORD = 'TenantUser123!';

    public function run(): void
    {
        // 1. Ensure central demo tenant exists
        /** @var Tenant|null $demoTenant */
        $demoTenant = Tenant::on('central')->find(DemoTenantSeeder::TENANT_ID);

        if ($demoTenant === null) {
            $this->call(DemoTenantSeeder::class);
            $demoTenant = Tenant::on('central')->find(DemoTenantSeeder::TENANT_ID);
        }

        if ($demoTenant !== null) {
            $this->seedTenant($demoTenant);
        }
    }

    public function seedTenant(Tenant $tenant): void
    {
        $tenantManager = app(TenantManager::class);

        try {
            // Step 1: Ensure database exists
            $this->ensureDatabaseExists((string) $tenant->database_name);

            // Step 2: Switch to tenant context
            $context = new TenantContext(
                id: (string) $tenant->id,
                slug: (string) $tenant->slug,
                databaseName: (string) $tenant->database_name,
            );
            $tenantManager->switchTo($context);

            // Step 3: Run tenant migrations
            $this->runTenantMigrations();

            // Step 4: Seed default roles & permissions
            (new TenantRolePermissionSeeder)->run();

            // Step 5: Seed default settings
            $this->seedDefaultSettings();

            // Step 6: Seed Tenant Owner
            $this->upsertTenantUser(
                name: self::DEFAULT_OWNER_NAME,
                email: self::DEFAULT_OWNER_EMAIL,
                password: self::DEFAULT_OWNER_PASSWORD,
                role: TenantRole::Owner->value,
            );

            // Step 7: Seed Tenant Admin
            $this->upsertTenantUser(
                name: self::DEFAULT_ADMIN_NAME,
                email: self::DEFAULT_ADMIN_EMAIL,
                password: self::DEFAULT_ADMIN_PASSWORD,
                role: TenantRole::Admin->value,
            );

            // Step 8: Seed additional standard demo users for ERP operations
            $this->upsertTenantUser(
                name: 'Demo Accountant',
                email: 'accountant@demo.nexuserp.test',
                password: self::DEFAULT_STAFF_PASSWORD,
                role: TenantRole::Accountant->value,
            );

            $this->upsertTenantUser(
                name: 'Demo Warehouse Mgr',
                email: 'warehouse@demo.nexuserp.test',
                password: self::DEFAULT_STAFF_PASSWORD,
                role: TenantRole::WarehouseManager->value,
            );

            $this->upsertTenantUser(
                name: 'Demo Sales Rep',
                email: 'sales@demo.nexuserp.test',
                password: self::DEFAULT_STAFF_PASSWORD,
                role: TenantRole::SalesRep->value,
            );

            // Step 9: Seed sample demo contacts
            $this->seedDemoContacts();

            $this->command?->info(sprintf(
                'Tenant [%s] admin and users seeded successfully:',
                $tenant->slug
            ));
            $this->command?->line(sprintf('  - Tenant Owner: %s | %s', self::DEFAULT_OWNER_EMAIL, self::DEFAULT_OWNER_PASSWORD));
            $this->command?->line(sprintf('  - Tenant Admin: %s | %s', self::DEFAULT_ADMIN_EMAIL, self::DEFAULT_ADMIN_PASSWORD));
            $this->command?->line(sprintf('  - Staff accounts password: %s', self::DEFAULT_STAFF_PASSWORD));
        } finally {
            $tenantManager->reset();
        }
    }

    private function upsertTenantUser(string $name, string $email, string $password, string $role): TenantUser
    {
        /** @var TenantUser|null $user */
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user === null) {
            $user = TenantUser::create([
                'id' => (string) Str::ulid(),
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        } else {
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
        }

        $user->syncRoles([$role]);

        // Support legacy user_roles table if present
        if (DB::connection('tenant')->getSchemaBuilder()->hasTable('user_roles')) {
            $roleId = DB::connection('tenant')->table('roles')->where('name', $role)->value('id');
            if ($roleId !== null) {
                DB::connection('tenant')->table('user_roles')->insertOrIgnore([
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                ]);
            }
        }

        return $user;
    }

    private function ensureDatabaseExists(string $databaseName): void
    {
        $driver = config('database.connections.central.driver');

        if ($driver !== 'pgsql') {
            return;
        }

        if (! preg_match('/^[a-z0-9_-]+$/', $databaseName)) {
            throw new \InvalidArgumentException("Invalid database name: {$databaseName}");
        }

        $config = config('database.connections.central');
        /** @var array{host: string, port: int|string, username: string, password: string} $config */
        $pdo = new \PDO(
            dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query(
            'SELECT COUNT(*) FROM pg_database WHERE datname = '.$pdo->quote($databaseName)
        );

        $exists = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        if ($exists === 0) {
            $quotedName = '"'.str_replace('"', '""', $databaseName).'"';
            $pdo->exec("CREATE DATABASE {$quotedName}");
        }
    }

    private function runTenantMigrations(): void
    {
        $paths = [
            base_path('Modules/Tenancy/Database/Migrations'),
            base_path('database/migrations/tenant'),
        ];

        $modulePaths = glob(base_path('Modules/*/Database/Migrations')) ?: [];
        foreach ($modulePaths as $p) {
            if ($p !== base_path('Modules/Tenancy/Database/Migrations')) {
                $paths[] = $p;
            }
        }

        /** @var list<string> $validPaths */
        $validPaths = array_values(array_filter(array_unique($paths), 'is_dir'));

        foreach ($validPaths as $path) {
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ]);
        }
    }

    private function seedDefaultSettings(): void
    {
        $settings = [
            ['key' => 'currency', 'value' => 'NPR', 'group' => 'finance'],
            ['key' => 'timezone', 'value' => 'Asia/Kathmandu', 'group' => 'general'],
            ['key' => 'date_format', 'value' => 'Y-m-d', 'group' => 'general'],
            ['key' => 'fiscal_year_start', 'value' => '07-16', 'group' => 'finance'],
            ['key' => 'invoice_prefix', 'value' => 'INV', 'group' => 'documents'],
            ['key' => 'bill_prefix', 'value' => 'BILL', 'group' => 'documents'],
        ];

        foreach ($settings as $setting) {
            DB::connection('tenant')
                ->table('settings')
                ->insertOrIgnore([
                    ...$setting,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function seedDemoContacts(): void
    {
        $contacts = [
            [
                'code' => 'CUST-000001',
                'type' => 'customer',
                'name' => 'Himalayan Mart Ltd',
                'tax_id' => 'PAN-109283746',
                'email' => 'purchasing@himalayanmart.com',
                'phone' => '+977-1-4412345',
                'payment_terms_days' => 30,
                'credit_limit_cents' => 50000000,
                'opening_balance_cents' => 1500000,
                'is_active' => true,
                'notes' => 'Primary retail distribution chain in Kathmandu.',
                'billing_address' => json_encode(['street' => 'Durbar Marg', 'city' => 'Kathmandu', 'country' => 'Nepal']),
                'shipping_address' => null,
            ],
            [
                'code' => 'SUPP-000001',
                'type' => 'supplier',
                'name' => 'Apex Packaging Industries',
                'tax_id' => 'PAN-209384751',
                'email' => 'orders@apexpackaging.np',
                'phone' => '+977-1-5523456',
                'payment_terms_days' => 15,
                'credit_limit_cents' => 25000000,
                'opening_balance_cents' => 0,
                'is_active' => true,
                'notes' => 'Bulk corrugated box and packaging vendor.',
                'billing_address' => json_encode(['street' => 'Patan Industrial Estate', 'city' => 'Lalitpur', 'country' => 'Nepal']),
                'shipping_address' => null,
            ],
            [
                'code' => 'CONT-000001',
                'type' => 'both',
                'name' => 'Sagarmatha Traders & Logistics',
                'tax_id' => 'PAN-309485762',
                'email' => 'contact@sagarmathatraders.com',
                'phone' => '+977-1-6634567',
                'payment_terms_days' => 45,
                'credit_limit_cents' => 100000000,
                'opening_balance_cents' => 4500000,
                'is_active' => true,
                'notes' => 'Wholesale client and freight partner.',
                'billing_address' => json_encode(['street' => 'New Road', 'city' => 'Kathmandu', 'country' => 'Nepal']),
                'shipping_address' => null,
            ],
        ];

        foreach ($contacts as $contact) {
            $existingId = DB::connection('tenant')->table('contacts')->where('code', $contact['code'])->value('id');
            $id = $existingId ?? (string) Str::ulid();

            DB::connection('tenant')->table('contacts')->updateOrInsert(
                ['code' => $contact['code']],
                [
                    'id' => $id,
                    ...$contact,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
