<?php

declare(strict_types=1);

test('test suite connects to isolated mysql_testing database and never touches mysql_central', function () {
    $dbName = config('database.connections.central.database');

    expect($dbName)->toBe('mysql_testing')
        ->and($dbName)->not->toBe('mysql_central');
});
