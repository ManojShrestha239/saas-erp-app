<?php

declare(strict_types=1);

namespace Modules\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

final class TenantsMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:migrate
        {--tenant= : The slug of a specific tenant to migrate}
        {--fresh : Drop all tables and re-run all migrations (disabled)}
        {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run database migrations for active tenants';

    public function handle(TenantManager $tenantManager): int
    {
        if ($this->option('fresh')) {
            $this->error('The --fresh option is not supported for tenant migrations.');

            return self::FAILURE;
        }

        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Application In Production! Use --force to run tenant migrations.');

            return self::FAILURE;
        }

        $migrationPaths = $this->getMigrationPaths();

        if (empty($migrationPaths)) {
            $this->warn('No tenant migration paths found.');

            return self::SUCCESS;
        }

        $targetSlug = $this->option('tenant');
        $targetSlug = is_string($targetSlug) && $targetSlug !== '' ? $targetSlug : null;

        if ($targetSlug !== null) {
            $tenant = Tenant::where('slug', $targetSlug)->first();

            if (! $tenant) {
                $this->error("Tenant with slug '{$targetSlug}' not found.");

                return self::FAILURE;
            }

            $tenants = collect([$tenant]);
        } else {
            $tenants = Tenant::orderBy('created_at')->get();
        }

        if ($tenants->isEmpty()) {
            $this->info('No tenants found to migrate.');

            return self::SUCCESS;
        }

        /** @var array<string, string> $failedTenants */
        $failedTenants = [];
        $migratedCount = 0;

        foreach ($tenants as $tenant) {
            if ($tenant->status !== 'active') {
                $this->line("Skipping tenant [{$tenant->slug}]: status is '{$tenant->status}'.");

                continue;
            }

            try {
                if (! $this->tenantDatabaseExists((string) $tenant->database_name)) {
                    throw new \RuntimeException("Database '{$tenant->database_name}' does not exist.");
                }

                $tenantManager->switchTo(new TenantContext(
                    id: (string) $tenant->id,
                    slug: (string) $tenant->slug,
                    databaseName: (string) $tenant->database_name,
                ));

                $this->info("Migrating tenant [{$tenant->slug}] (DB: {$tenant->database_name})...");

                $params = [
                    '--database' => 'tenant',
                    '--path' => $migrationPaths,
                    '--realpath' => true,
                    '--force' => true,
                ];

                $exitCode = Artisan::call('migrate', $params);

                if ($exitCode !== 0) {
                    $output = trim(Artisan::output());
                    $failedTenants[$tenant->slug] = $output !== '' ? $output : "Exit code {$exitCode}";
                    $this->error("Failed migrating tenant [{$tenant->slug}]: {$failedTenants[$tenant->slug]}");
                } else {
                    $output = trim(Artisan::output());
                    if ($output !== '') {
                        $this->line($output);
                    }
                    $this->info("Successfully migrated tenant [{$tenant->slug}].");
                    $migratedCount++;
                }
            } catch (\Throwable $e) {
                $failedTenants[$tenant->slug] = $e->getMessage();
                $this->error("Exception while migrating tenant [{$tenant->slug}]: {$e->getMessage()}");
            } finally {
                $tenantManager->reset();
            }
        }

        if (! empty($failedTenants)) {
            $this->newLine();
            $this->error(sprintf('Failed migrating %d tenant(s):', count($failedTenants)));
            foreach ($failedTenants as $slug => $reason) {
                $this->error("  - [{$slug}]: {$reason}");
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('Migration completed. %d active tenant(s) migrated successfully.', $migratedCount));

        return self::SUCCESS;
    }

    private function tenantDatabaseExists(string $databaseName): bool
    {
        $config = config('database.connections.central');
        /** @var array{host: string, port: int|string, username: string, password: string} $config */
        $pdo = new \PDO(
            dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query(
            'SELECT COUNT(*) FROM pg_database WHERE datname = '.
            $pdo->quote($databaseName)
        );

        return $stmt !== false && ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * @return list<string>
     */
    private function getMigrationPaths(): array
    {
        $pattern = base_path('Modules/*/Database/Migrations');
        $paths = glob($pattern) ?: [];

        $tenantPath = base_path('database/migrations/tenant');
        if (is_dir($tenantPath)) {
            $paths[] = $tenantPath;
        }

        /** @var list<string> $validPaths */
        $validPaths = array_values(array_filter(array_unique($paths), 'is_dir'));

        return $validPaths;
    }
}
