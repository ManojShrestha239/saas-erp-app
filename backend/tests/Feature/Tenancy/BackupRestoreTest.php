<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\BackupService;
use Tests\TestCase;

beforeEach(function (): void {
    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    // Ensure demo tenant exists for backup and restore testing
    Artisan::call('tenants:seed-demo');
});

test('tenants:backup command successfully backs up a specific tenant to a valid gzip archive', function (): void {
    /** @var TestCase $this */
    $tempBackupPath = storage_path('app/backups/tenants/test_demo_backup.sql.gz');
    if (file_exists($tempBackupPath)) {
        @unlink($tempBackupPath);
    }

    try {
        $exitCode = Artisan::call('tenants:backup', [
            '--tenant' => 'demo',
            '--path' => $tempBackupPath,
        ]);

        expect($exitCode)->toBe(0);
        expect(File::exists($tempBackupPath))->toBeTrue();
        expect(filesize($tempBackupPath))->toBeGreaterThan(1000);

        // Verify it is a valid gzip file (magic bytes 0x1f 0x8b)
        $handle = fopen($tempBackupPath, 'rb');
        $bytes = fread($handle, 2);
        fclose($handle);

        expect(bin2hex($bytes))->toBe('1f8b');
    } finally {
        if (file_exists($tempBackupPath)) {
            @unlink($tempBackupPath);
        }
    }
});

test('tenants:backup --central successfully backs up central database', function (): void {
    $tempCentralPath = storage_path('app/backups/tenants/test_central_backup.sql.gz');
    if (file_exists($tempCentralPath)) {
        @unlink($tempCentralPath);
    }

    try {
        $exitCode = Artisan::call('tenants:backup', [
            '--central' => true,
            '--path' => $tempCentralPath,
        ]);

        expect($exitCode)->toBe(0);
        expect(File::exists($tempCentralPath))->toBeTrue();
        expect(filesize($tempCentralPath))->toBeGreaterThan(500);
    } finally {
        if (file_exists($tempCentralPath)) {
            @unlink($tempCentralPath);
        }
    }
});

test('tenants:backup --all backs up central and all active tenant databases', function (): void {
    $tempDir = storage_path('app/backups/test_all_dir');
    if (File::isDirectory($tempDir)) {
        File::deleteDirectory($tempDir);
    }

    try {
        $exitCode = Artisan::call('tenants:backup', [
            '--all' => true,
            '--path' => $tempDir,
        ]);

        expect($exitCode)->toBe(0);
        expect(File::isDirectory($tempDir))->toBeTrue();

        $files = File::files($tempDir);
        expect(count($files))->toBeGreaterThanOrEqual(2); // central + demo
    } finally {
        if (File::isDirectory($tempDir)) {
            File::deleteDirectory($tempDir);
        }
    }
});

test('tenants:restore command successfully restores database tables from a backup archive', function (): void {
    $backupService = app(BackupService::class);
    $tempBackupPath = storage_path('app/backups/tenants/test_restore_source.sql.gz');
    $drillDatabaseName = 'tenant_restore_verify_test';

    try {
        // 1. Create a fresh backup of tenant_demo
        $backupService->backupDatabase('tenant_demo', $tempBackupPath);
        expect(File::exists($tempBackupPath))->toBeTrue();

        // 2. Restore into new target database using artisan command
        $exitCode = Artisan::call('tenants:restore', [
            '--file' => $tempBackupPath,
            '--target-db' => $drillDatabaseName,
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);

        // 3. Connect to the restored database and verify tables exist
        config(['database.connections.test_restore' => array_merge(
            (array) config('database.connections.tenant'),
            ['database' => $drillDatabaseName]
        )]);

        $tables = DB::connection('test_restore')->select(
            "SELECT count(*) as count FROM information_schema.tables WHERE table_schema = 'public'"
        );
        $tableCount = isset($tables[0]->count) ? (int) $tables[0]->count : 0;
        expect($tableCount)->toBeGreaterThanOrEqual(40);

        $users = DB::connection('test_restore')->select('SELECT count(*) as count FROM users');
        $userCount = isset($users[0]->count) ? (int) $users[0]->count : 0;
        expect($userCount)->toBe(5);
    } finally {
        DB::disconnect('test_restore');
        $backupService->dropDatabaseIfExists($drillDatabaseName);
        if (file_exists($tempBackupPath)) {
            @unlink($tempBackupPath);
        }
    }
});

test('tenants:restore-drill performs end-to-end backup, ephemeral restore verification, and cleanup', function (): void {
    $exitCode = Artisan::call('tenants:restore-drill', [
        '--tenant' => 'demo',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('SUCCESS')
        ->and($output)->toContain('Disaster recovery restore drill passed!');
});

test('tenants:backup fails gracefully when targeting non-existent tenant', function (): void {
    $exitCode = Artisan::call('tenants:backup', [
        '--tenant' => 'non-existent-tenant-slug',
    ]);

    expect($exitCode)->toBe(1);
});

test('tenants:restore fails gracefully when file is missing', function (): void {
    $exitCode = Artisan::call('tenants:restore', [
        '--file' => '/path/to/missing/backup.sql.gz',
        '--target-db' => 'target_db',
        '--force' => true,
    ]);

    expect($exitCode)->toBe(1);
});
