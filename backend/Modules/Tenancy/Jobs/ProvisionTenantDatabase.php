<?php

declare(strict_types=1);

namespace Modules\Tenancy\Jobs;

use Database\Seeders\TenantRolePermissionSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

final class ProvisionTenantDatabase implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $ownerEmail,
        public readonly string $ownerName,
    ) {}

    public function handle(TenantManager $tenantManager, DatabaseManager $databaseManager): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::on('central')->find($this->tenantId);

        if ($tenant === null) {
            Log::error('ProvisionTenantDatabase: tenant not found', ['tenant_id' => $this->tenantId]);

            return;
        }

        // Already active — idempotent exit
        if ($tenant->status === 'active') {
            Log::info('ProvisionTenantDatabase: tenant already active, skipping', ['tenant_id' => $this->tenantId]);

            return;
        }

        try {
            // Step 1: Create database if it doesn't exist
            $this->createDatabaseIfNotExists($databaseManager, $tenant->database_name);

            // Step 2: Set up tenant connection and run migrations
            $context = new TenantContext(
                id: $tenant->id,
                slug: $tenant->slug,
                databaseName: $tenant->database_name,
            );

            $tenantManager->switchTo($context);

            // Step 3: Run tenant migrations (idempotent — migrations track their own state)
            $this->runTenantMigrations();

            // Step 4: Seed default roles (idempotent)
            $this->seedDefaultRoles($databaseManager);

            // Step 5: Seed default settings (idempotent)
            $this->seedDefaultSettings($databaseManager);

            // Step 6: Create owner user (idempotent — skip if already exists)
            $this->createOwnerUser($databaseManager);

            // Step 7: Mark tenant as active
            $tenant->update([
                'status' => 'active',
                'provisioning_error' => null,
            ]);

            Log::info('ProvisionTenantDatabase: tenant provisioned successfully', [
                'tenant_id' => $this->tenantId,
                'slug' => $tenant->slug,
            ]);
        } catch (Throwable $e) {
            $tenant->update([
                'status' => 'provisioning_failed',
                'provisioning_error' => $e->getMessage(),
            ]);

            Log::error('ProvisionTenantDatabase: provisioning failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $tenantManager->reset();
        }
    }

    private function createDatabaseIfNotExists(DatabaseManager $databaseManager, string $databaseName): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $databaseName)) {
            throw new \InvalidArgumentException("Invalid database name: {$databaseName}");
        }

        // Connect to postgres system db via central connection config (same host/credentials)
        $config = config('database.connections.central');
        /** @var array{host: string, port: int|string, username: string, password: string} $config */
        $pdo = new \PDO(
            dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Safe: database_name is validated against [a-z0-9_-] and safely quoted
        $quotedName = '"'.str_replace('"', '""', $databaseName).'"';

        $stmt = $pdo->query(
            'SELECT COUNT(*) FROM pg_database WHERE datname = '.
            $pdo->quote($databaseName)
        );

        $exists = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        if ($exists === 0) {
            $pdo->exec("CREATE DATABASE {$quotedName}");

            Log::info('ProvisionTenantDatabase: created database', ['database' => $databaseName]);
        }
    }

    private function runTenantMigrations(): void
    {
        // Run all tenant migrations — migrations are idempotent by design
        $pattern = base_path('Modules/*/Database/Migrations');
        $modulePaths = glob($pattern) ?: [];

        $tenancyPath = base_path('Modules/Tenancy/Database/Migrations');
        $otherModulePaths = array_values(array_filter(
            $modulePaths,
            static fn (string $p): bool => $p !== $tenancyPath
        ));

        $paths = [];
        if (is_dir($tenancyPath)) {
            $paths[] = $tenancyPath;
        }
        $tenantPath = base_path('database/migrations/tenant');
        if (is_dir($tenantPath)) {
            $paths[] = $tenantPath;
        }
        foreach ($otherModulePaths as $p) {
            $paths[] = $p;
        }

        /** @var list<string> $validPaths */
        $validPaths = array_values(array_filter(array_unique($paths), 'is_dir'));

        foreach ($validPaths as $path) {
            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException('Tenant migration failed with exit code '.$exitCode.': '.trim(Artisan::output()));
            }
        }

        Log::info('ProvisionTenantDatabase: tenant migrations ran', ['tenant_id' => $this->tenantId]);
    }

    private function seedDefaultRoles(DatabaseManager $databaseManager): void
    {
        (new TenantRolePermissionSeeder)->run();
    }

    private function seedDefaultSettings(DatabaseManager $databaseManager): void
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
            $databaseManager->connection('tenant')
                ->table('settings')
                ->insertOrIgnore([
                    ...$setting,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function createOwnerUser(DatabaseManager $databaseManager): void
    {
        $existingUserId = $databaseManager->connection('tenant')
            ->table('users')
            ->where('email', $this->ownerEmail)
            ->value('id');

        if ($existingUserId === null) {
            $userId = (string) Str::ulid();

            $databaseManager->connection('tenant')
                ->table('users')
                ->insert([
                    'id' => $userId,
                    'name' => $this->ownerName,
                    'email' => $this->ownerEmail,
                    'password' => Hash::make(Str::random(32)), // Random password; reset via email in full auth flow
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            $userId = (string) $existingUserId;
        }

        // Assign owner role
        $roleId = $databaseManager->connection('tenant')
            ->table('roles')
            ->where('name', 'owner')
            ->value('id');

        if ($roleId !== null) {
            // Spatie model_has_roles
            $databaseManager->connection('tenant')
                ->table('model_has_roles')
                ->insertOrIgnore([
                    'role_id' => $roleId,
                    'model_type' => 'Modules\Auth\Models\TenantUser',
                    'model_id' => $userId,
                ]);

            // Legacy user_roles table if present
            if ($databaseManager->connection('tenant')->getSchemaBuilder()->hasTable('user_roles')) {
                $databaseManager->connection('tenant')
                    ->table('user_roles')
                    ->insertOrIgnore([
                        'user_id' => $userId,
                        'role_id' => $roleId,
                    ]);
            }
        }

        Log::info('ProvisionTenantDatabase: owner user created', [
            'tenant_id' => $this->tenantId,
            'email' => $this->ownerEmail,
        ]);
    }
}
