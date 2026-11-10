<?php

declare(strict_types=1);

namespace Modules\Tenancy\Commands;

use Illuminate\Console\Command;
use Modules\Tenancy\Services\BackupService;

final class RestoreTenantDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:restore
        {--file= : Path to .sql.gz backup file}
        {--target-db= : Target database name to restore into}
        {--force : Force restore without interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a compressed .sql.gz backup archive into a target PostgreSQL database';

    public function handle(BackupService $backupService): int
    {
        /** @var string|null $file */
        $file = $this->option('file');
        /** @var string|null $targetDb */
        $targetDb = $this->option('target-db');
        $force = (bool) $this->option('force');

        if (! $file || ! $targetDb) {
            $this->error('Both --file=PATH and --target-db=NAME options are required.');

            return self::FAILURE;
        }

        if (! file_exists($file)) {
            $this->error("Backup file '{$file}' not found.");

            return self::FAILURE;
        }

        if (! $force && ! $this->confirm("WARNING: This will overwrite tables in '{$targetDb}' with '{$file}'. Proceed?")) {
            $this->warn('Restore cancelled by user.');

            return self::SUCCESS;
        }

        $this->info("Restoring '{$file}' into database '{$targetDb}'...");
        $backupService->restoreDatabase($file, $targetDb);

        $this->info("Database '{$targetDb}' restored successfully from '{$file}'.");

        return self::SUCCESS;
    }
}
