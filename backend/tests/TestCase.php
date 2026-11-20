<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safely drop an ephemeral test tenant database across both MySQL and PostgreSQL.
     */
    public static function dropTestTenantDatabase(string $databaseName): void
    {
        if (! str_starts_with($databaseName, 'tenant_')) {
            return;
        }

        try {
            $config = config('database.connections.central');
            /** @var array{driver?: string, host: string, port: int|string, username: string, password: string} $config */
            $driver = $config['driver'] ?? 'mysql';

            if ($driver === 'pgsql') {
                $pdo = new PDO(
                    dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
                    username: (string) $config['username'],
                    password: (string) $config['password'],
                );
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
                    $pdo->quote($databaseName).' AND pid <> pg_backend_pid()');

                $quoted = '"'.str_replace('"', '""', $databaseName).'"';
                $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
            } else {
                $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
                $pdo = new PDO(
                    dsn: $dsn,
                    username: (string) $config['username'],
                    password: (string) $config['password'],
                );
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $quoted = '`'.str_replace('`', '``', $databaseName).'`';
                $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
            }
        } catch (Throwable) {
            // Best effort cleanup
        }
    }
}
