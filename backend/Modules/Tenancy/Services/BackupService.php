<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Exceptions\ApiException;
use Symfony\Component\Process\Process;
use Throwable;

final class BackupService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Backup a PostgreSQL database to a compressed .sql.gz archive.
     *
     * @return array{
     *     database: string,
     *     file_path: string,
     *     file_name: string,
     *     size_bytes: int,
     *     sha256: string,
     *     created_at: string
     * }
     */
    public function backupDatabase(string $databaseName, ?string $destinationPath = null): array
    {
        $this->assertValidDatabaseName($databaseName);

        if ($destinationPath === null) {
            $timestamp = Carbon::now()->format('Y-m-d_His');
            $dir = storage_path('app/backups/tenants');
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true, true);
            }
            $destinationPath = $dir.'/'.$databaseName.'_'.$timestamp.'.sql.gz';
        } else {
            $dir = dirname($destinationPath);
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true, true);
            }
        }

        $config = $this->resolveConnectionConfig();
        $host = (string) $config['host'];
        $port = (string) $config['port'];
        $user = (string) $config['username'];
        $password = (string) $config['password'];
        $driver = $config['driver'];

        if ($driver === 'pgsql') {
            $command = sprintf(
                'pg_dump -h %s -p %s -U %s --clean --if-exists --no-owner --no-privileges %s | gzip > %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($databaseName),
                escapeshellarg($destinationPath)
            );
            $env = ['PGPASSWORD' => $password];
        } else {
            $command = sprintf(
                'mysqldump -h %s -P %s -u %s --single-transaction --quick --skip-lock-tables --default-character-set=utf8mb4 %s | gzip > %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($databaseName),
                escapeshellarg($destinationPath)
            );
            $env = ['MYSQL_PWD' => $password];
        }

        $process = Process::fromShellCommandline($command, env: $env);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($destinationPath) || filesize($destinationPath) === 0) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            Log::error('Backup failed for database', [
                'database' => $databaseName,
                'error' => $errorOutput,
            ]);

            if (file_exists($destinationPath)) {
                @unlink($destinationPath);
            }

            throw ApiException::internal(
                "Database backup failed for '{$databaseName}': {$errorOutput}",
                'BACKUP_FAILED'
            );
        }

        $size = (int) filesize($destinationPath);
        $sha256 = (string) hash_file('sha256', $destinationPath);

        return [
            'database' => $databaseName,
            'file_path' => $destinationPath,
            'file_name' => basename($destinationPath),
            'size_bytes' => $size,
            'sha256' => $sha256,
            'created_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Backup all active tenant databases, and optionally the central database.
     *
     * @return list<array{
     *     tenant_id: ?string,
     *     tenant_slug: ?string,
     *     database: string,
     *     file_path: string,
     *     size_bytes: int,
     *     sha256: string,
     *     created_at: string
     * }>
     */
    public function backupAllTenants(?string $targetDirectory = null, bool $includeCentral = true): array
    {
        $results = [];

        if ($includeCentral) {
            $centralDb = (string) config('database.connections.central.database', 'mysql_central');
            $dest = $targetDirectory !== null ? rtrim($targetDirectory, '/').'/central_'.$centralDb.'_'.Carbon::now()->format('Y-m-d_His').'.sql.gz' : null;
            $res = $this->backupDatabase($centralDb, $dest);
            $results[] = [
                'tenant_id' => null,
                'tenant_slug' => 'central',
                'database' => $res['database'],
                'file_path' => $res['file_path'],
                'size_bytes' => $res['size_bytes'],
                'sha256' => $res['sha256'],
                'created_at' => $res['created_at'],
            ];
        }

        $tenants = Tenant::on('central')->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            $dest = $targetDirectory !== null ? rtrim($targetDirectory, '/').'/'.$tenant->slug.'_'.$tenant->database_name.'_'.Carbon::now()->format('Y-m-d_His').'.sql.gz' : null;
            $res = $this->backupDatabase($tenant->database_name, $dest);
            $results[] = [
                'tenant_id' => (string) $tenant->id,
                'tenant_slug' => (string) $tenant->slug,
                'database' => $res['database'],
                'file_path' => $res['file_path'],
                'size_bytes' => $res['size_bytes'],
                'sha256' => $res['sha256'],
                'created_at' => $res['created_at'],
            ];
        }

        return $results;
    }

    /**
     * Restore a compressed .sql.gz archive into a target PostgreSQL database.
     */
    public function restoreDatabase(string $backupFilePath, string $targetDatabaseName, bool $createDatabaseIfMissing = true): bool
    {
        $this->assertValidDatabaseName($targetDatabaseName);

        if (! file_exists($backupFilePath) || ! is_readable($backupFilePath)) {
            throw ApiException::notFound("Backup file '{$backupFilePath}' not found or unreadable.", 'BACKUP_FILE_NOT_FOUND');
        }

        if ($createDatabaseIfMissing) {
            $this->createDatabaseIfNotExists($targetDatabaseName);
        }

        $config = $this->resolveConnectionConfig();
        $host = (string) $config['host'];
        $port = (string) $config['port'];
        $user = (string) $config['username'];
        $password = (string) $config['password'];
        $driver = $config['driver'];

        if ($driver === 'pgsql') {
            $command = sprintf(
                'gunzip -c %s | psql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1',
                escapeshellarg($backupFilePath),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($targetDatabaseName)
            );
            $env = ['PGPASSWORD' => $password];
        } else {
            $command = sprintf(
                'gunzip -c %s | mysql -h %s -P %s -u %s --default-character-set=utf8mb4 %s',
                escapeshellarg($backupFilePath),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($targetDatabaseName)
            );
            $env = ['MYSQL_PWD' => $password];
        }

        $process = Process::fromShellCommandline($command, env: $env);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            Log::error('Restore failed for database', [
                'database' => $targetDatabaseName,
                'backup_file' => $backupFilePath,
                'error' => $errorOutput,
            ]);

            throw ApiException::internal(
                "Database restore failed for '{$targetDatabaseName}': {$errorOutput}",
                'RESTORE_FAILED'
            );
        }

        return true;
    }

    /**
     * Execute an end-to-end restore drill:
     * 1. Backup active tenant database
     * 2. Provision temporary drill database
     * 3. Restore dump into drill database
     * 4. Verify table and record integrity
     * 5. Clean up temporary drill database and backup
     *
     * @return array{
     *     success: bool,
     *     tenant_id: string,
     *     tenant_slug: string,
     *     original_db: string,
     *     drill_db: string,
     *     tables_verified: int,
     *     users_count: int,
     *     backup_size_bytes: int,
     *     duration_ms: int
     * }
     */
    public function runRestoreDrill(string $tenantIdOrSlug, bool $keepBackup = false): array
    {
        $startTime = microtime(true);

        /** @var Tenant|null $tenant */
        $tenant = Tenant::on('central')
            ->where('id', $tenantIdOrSlug)
            ->orWhere('slug', $tenantIdOrSlug)
            ->first();

        if ($tenant === null) {
            throw ApiException::notFound("Tenant '{$tenantIdOrSlug}' not found.", 'TENANT_NOT_FOUND');
        }

        $origDb = $tenant->database_name;
        $drillDb = 'tenant_drill_'.strtolower((string) $tenant->slug).'_'.time();
        $backupResult = $this->backupDatabase($origDb);
        $backupPath = $backupResult['file_path'];

        try {
            $this->createDatabaseIfNotExists($drillDb);
            $this->restoreDatabase($backupPath, $drillDb, createDatabaseIfMissing: false);

            // Connect to drill database to verify table counts and users
            $drillConnectionConfig = array_merge(
                (array) config('database.connections.tenant'),
                ['database' => $drillDb]
            );
            config(['database.connections.tenant_drill' => $drillConnectionConfig]);

            $driver = (string) (config('database.connections.tenant.driver') ?? 'mysql');
            $schemaFilter = $driver === 'pgsql' ? "'public'" : $this->db->connection('tenant_drill')->getPdo()->quote($drillDb);
            $tables = $this->db->connection('tenant_drill')->select(
                "SELECT count(*) as count FROM information_schema.tables WHERE table_schema = {$schemaFilter}"
            );
            $tableCount = isset($tables[0]->count) ? (int) $tables[0]->count : 0;

            $usersCount = 0;
            if ($tableCount > 0) {
                $userRows = $this->db->connection('tenant_drill')->select('SELECT count(*) as count FROM users');
                $usersCount = isset($userRows[0]->count) ? (int) $userRows[0]->count : 0;
            }

            if ($tableCount === 0) {
                throw ApiException::internal("Restore drill verification failed: 0 tables found in '{$drillDb}'.", 'DRILL_VERIFICATION_FAILED');
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'tenant_id' => (string) $tenant->id,
                'tenant_slug' => (string) $tenant->slug,
                'original_db' => $origDb,
                'drill_db' => $drillDb,
                'tables_verified' => $tableCount,
                'users_count' => $usersCount,
                'backup_size_bytes' => $backupResult['size_bytes'],
                'duration_ms' => $durationMs,
            ];
        } finally {
            $this->db->disconnect('tenant_drill');
            $this->dropDatabaseIfExists($drillDb);

            if (! $keepBackup && file_exists($backupPath)) {
                @unlink($backupPath);
            }
        }
    }

    public function createDatabaseIfNotExists(string $databaseName): void
    {
        $this->assertValidDatabaseName($databaseName);
        $config = $this->resolveConnectionConfig();
        $driver = $config['driver'];

        if ($driver === 'pgsql') {
            $pdo = $this->getAdminPdo();
            $quotedName = '"'.str_replace('"', '""', $databaseName).'"';
            $stmt = $pdo->query('SELECT COUNT(*) FROM pg_database WHERE datname = '.$pdo->quote($databaseName));
            $exists = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

            if ($exists === 0) {
                $tenantUser = (string) config('database.connections.tenant.username', config('database.connections.central.username', ''));
                $adminUser = (string) $this->resolveAdminUsername();

                if ($tenantUser !== '' && $tenantUser !== $adminUser) {
                    try {
                        $quotedUser = '"'.str_replace('"', '""', $tenantUser).'"';
                        $pdo->exec("CREATE DATABASE {$quotedName} OWNER {$quotedUser}");
                    } catch (Throwable) {
                        $pdo->exec("CREATE DATABASE {$quotedName}");
                    }
                } else {
                    $pdo->exec("CREATE DATABASE {$quotedName}");
                }
            }
        } else {
            $pdo = $this->getAdminPdo();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :dbname');
            $stmt->execute(['dbname' => $databaseName]);
            $exists = (int) $stmt->fetchColumn();

            if ($exists === 0) {
                $quotedName = '`'.str_replace('`', '``', $databaseName).'`';
                $pdo->exec("CREATE DATABASE {$quotedName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }
    }

    public function dropDatabaseIfExists(string $databaseName): void
    {
        $this->assertValidDatabaseName($databaseName);
        $config = $this->resolveConnectionConfig();
        $driver = $config['driver'];

        if ($driver === 'pgsql') {
            $pdo = $this->getAdminPdo();
            $quotedName = '"'.str_replace('"', '""', $databaseName).'"';
            $stmt = $pdo->query('SELECT COUNT(*) FROM pg_database WHERE datname = '.$pdo->quote($databaseName));
            $exists = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

            if ($exists > 0) {
                // Terminate open connections before dropping
                try {
                    $pdo->exec(sprintf(
                        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()',
                        $pdo->quote($databaseName)
                    ));
                } catch (Throwable) {
                    // Ignore termination errors if no active connections
                }

                $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
            }
        } else {
            $pdo = $this->getAdminPdo();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :dbname');
            $stmt->execute(['dbname' => $databaseName]);
            $exists = (int) $stmt->fetchColumn();

            if ($exists > 0) {
                $quotedName = '`'.str_replace('`', '``', $databaseName).'`';
                $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
            }
        }
    }

    private function assertValidDatabaseName(string $databaseName): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $databaseName)) {
            throw ApiException::unprocessable("Invalid database name '{$databaseName}'. Only alphanumeric and underscores allowed.", 'INVALID_DATABASE_NAME');
        }
    }

    /**
     * @return array{driver: string, host: string, port: int|string, username: string, password: string}
     */
    private function resolveConnectionConfig(): array
    {
        /** @var array{driver?: string, host?: string, port?: int|string, username?: string, password?: string} $tenantConfig */
        $tenantConfig = (array) config('database.connections.tenant');
        /** @var array{driver?: string, host?: string, port?: int|string, username?: string, password?: string} $centralConfig */
        $centralConfig = (array) config('database.connections.central');

        $driver = (string) ($tenantConfig['driver'] ?? $centralConfig['driver'] ?? 'mysql');
        $defaultPort = $driver === 'pgsql' ? '5432' : '3306';
        $defaultHost = $driver === 'pgsql' ? 'postgres' : 'mysql';
        $defaultUser = $driver === 'pgsql' ? 'postgres' : 'root';

        return [
            'driver' => $driver,
            'host' => (string) ($tenantConfig['host'] ?? $centralConfig['host'] ?? $defaultHost),
            'port' => (string) ($tenantConfig['port'] ?? $centralConfig['port'] ?? $defaultPort),
            'username' => (string) ($tenantConfig['username'] ?? $centralConfig['username'] ?? $defaultUser),
            'password' => (string) ($tenantConfig['password'] ?? $centralConfig['password'] ?? ''),
        ];
    }

    private function resolveAdminUsername(): string
    {
        /** @var array{username?: string} $config */
        $config = (array) (config('database.connections.provisioner') ?: config('database.connections.central'));

        return (string) ($config['username'] ?? 'postgres');
    }

    private function getAdminPdo(): PDO
    {
        /** @var array{driver?: string, host: string, port: int|string, username: string, password: string, database?: string} $config */
        $config = (array) (config('database.connections.provisioner') ?: config('database.connections.central'));
        $driver = (string) ($config['driver'] ?? 'mysql');

        if ($driver === 'pgsql') {
            $systemDb = $config['database'] ?? 'postgres';
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $systemDb);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
        }

        $pdo = new PDO(
            dsn: $dsn,
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
