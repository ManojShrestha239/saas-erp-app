<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

test('test suite connects to isolated pgsql_testing database and never touches pgsql_central', function () {
    $dbName = config('database.connections.central.database');
    $currentDb = DB::connection('central')->scalar('SELECT current_database()');

    expect($dbName)->toBe('pgsql_testing')
        ->and($currentDb)->toBe('pgsql_testing')
        ->and($currentDb)->not->toBe('pgsql_central');
});
