<?php

declare(strict_types=1);

namespace Modules\Tenancy\Commands;

use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\BackupService;

final class BackupTenantDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:backup
        {--tenant= : Specific tenant slug or ID to backup}
        {--central : Backup only the central database}
        {--all : Backup central and all active tenant databases}
        {--path= : Target destination directory or file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup central or tenant databases to compressed .sql.gz archives';

    public function handle(BackupService $backupService): int
    {
        $tenantOpt = $this->option('tenant');
        $isCentral = (bool) $this->option('central');
        $isAll = (bool) $this->option('all');
        /** @var string|null $path */
        $path = $this->option('path');

        if (! $tenantOpt && ! $isCentral && ! $isAll) {
            $this->error('Please specify --tenant=SLUG, --central, or --all.');

            return self::FAILURE;
        }

        $this->info('Starting database backup...');

        if ($isAll) {
            $results = $backupService->backupAllTenants(targetDirectory: $path, includeCentral: true);
            $rows = array_map(fn (array $r): array => [
                $r['tenant_slug'] ?? 'N/A',
                $r['database'],
                $r['file_path'],
                number_format($r['size_bytes'] / 1024, 2).' KB',
                substr($r['sha256'], 0, 12).'...',
            ], $results);

            $this->table(['Tenant', 'Database', 'File', 'Size', 'SHA-256'], $rows);
            $this->info(sprintf('Successfully backed up %d databases.', count($results)));

            return self::SUCCESS;
        }

        if ($isCentral) {
            $centralDb = (string) config('database.connections.central.database', 'pgsql_central');
            $this->line("Backing up central database [{$centralDb}]...");
            $res = $backupService->backupDatabase($centralDb, destinationPath: $path);

            $this->table(['Database', 'File', 'Size', 'SHA-256'], [[
                $res['database'],
                $res['file_path'],
                number_format($res['size_bytes'] / 1024, 2).' KB',
                $res['sha256'],
            ]]);
            $this->info('Central database backup completed successfully.');

            return self::SUCCESS;
        }

        /** @var string $slugOrId */
        $slugOrId = $tenantOpt;
        /** @var Tenant|null $tenant */
        $tenant = Tenant::on('central')
            ->where('id', $slugOrId)
            ->orWhere('slug', $slugOrId)
            ->first();

        if ($tenant === null) {
            $this->error("Tenant '{$slugOrId}' not found.");

            return self::FAILURE;
        }

        $this->line("Backing up tenant [{$tenant->slug}] (DB: {$tenant->database_name})...");
        $res = $backupService->backupDatabase($tenant->database_name, destinationPath: $path);

        $this->table(['Tenant', 'Database', 'File', 'Size', 'SHA-256'], [[
            $tenant->slug,
            $res['database'],
            $res['file_path'],
            number_format($res['size_bytes'] / 1024, 2).' KB',
            $res['sha256'],
        ]]);
        $this->info("Tenant [{$tenant->slug}] backup completed successfully.");

        return self::SUCCESS;
    }
}
