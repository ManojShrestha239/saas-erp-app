<?php

declare(strict_types=1);

namespace Modules\Tenancy\Commands;

use Illuminate\Console\Command;
use Modules\Tenancy\Services\BackupService;

final class RestoreDrillCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:restore-drill
        {--tenant=demo : Tenant slug or ID to perform drill on}
        {--keep-backup : Keep the generated backup archive after drill completes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform an automated backup and restore drill against an isolated temporary database';

    public function handle(BackupService $backupService): int
    {
        /** @var string $tenant */
        $tenant = (string) $this->option('tenant');
        $keepBackup = (bool) $this->option('keep-backup');

        $this->info("Initiating disaster recovery restore drill for tenant [{$tenant}]...");

        $result = $backupService->runRestoreDrill($tenant, keepBackup: $keepBackup);

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Status', $result['success'] ? 'SUCCESS' : 'FAILED'],
            ['Tenant ID', $result['tenant_id']],
            ['Tenant Slug', $result['tenant_slug']],
            ['Source Database', $result['original_db']],
            ['Ephemeral Drill Database', $result['drill_db']],
            ['Tables Verified', (string) $result['tables_verified']],
            ['Users Verified', (string) $result['users_count']],
            ['Backup Size', number_format($result['backup_size_bytes'] / 1024, 2).' KB'],
            ['Drill Duration', $result['duration_ms'].' ms'],
        ]);

        $this->info("Disaster recovery restore drill passed! Ephemeral database '{$result['drill_db']}' was cleanly destroyed.");

        return self::SUCCESS;
    }
}
