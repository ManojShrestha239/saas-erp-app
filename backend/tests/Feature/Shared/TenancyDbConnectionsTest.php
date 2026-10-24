<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Shared\Tenancy\UsesTenantConnection;

afterEach(function () {
    app(TenantManager::class)->reset();
});

test('named connections central and tenant exist in database configuration', function () {
    $connections = config('database.connections');

    expect($connections)->toHaveKey('central')
        ->and($connections)->toHaveKey('tenant')
        ->and(config('database.default'))->toBe('central')
        ->and($connections['central']['driver'])->toBe('pgsql')
        ->and($connections['tenant']['driver'])->toBe('pgsql')
        ->and($connections['central']['database'])->toBe(env('DB_CENTRAL_DATABASE', env('DB_DATABASE', 'pgsql_central')))
        ->and($connections['tenant']['database'])->toBeNull();
});

test('TenantManager changes the tenant connection database name and binds context', function () {
    $manager = app(TenantManager::class);

    $context = new TenantContext(
        id: 't-123',
        slug: 'acme',
        databaseName: 'tenant_acme'
    );

    $manager->switchTo($context);

    expect(config('database.connections.tenant.database'))->toBe('tenant_acme')
        ->and($manager->connection()->getDatabaseName())->toBe('tenant_acme')
        ->and($manager->getContext())->toBe($context)
        ->and(TenantContext::get())->toBe($context)
        ->and(app(TenantContext::class))->toBe($context)
        ->and($manager->hasContext())->toBeTrue();
});

test('a model using UsesTenantConnection trait reports the tenant connection', function () {
    $model = new class extends Model
    {
        use UsesTenantConnection;
    };

    expect($model->getConnectionName())->toBe('tenant');
});

test('switching tenants twice does not leak state from the first tenant', function () {
    $manager = app(TenantManager::class);

    $tenant1 = new TenantContext(
        id: 1,
        slug: 'alpha',
        databaseName: 'tenant_alpha'
    );

    $manager->switchTo($tenant1);

    expect($manager->getContext()->id)->toBe(1)
        ->and($manager->getContext()->slug)->toBe('alpha')
        ->and($manager->getContext()->databaseName)->toBe('tenant_alpha')
        ->and($manager->connection()->getDatabaseName())->toBe('tenant_alpha')
        ->and(config('database.connections.tenant.database'))->toBe('tenant_alpha');

    $tenant2 = new TenantContext(
        id: 2,
        slug: 'beta',
        databaseName: 'tenant_beta'
    );

    $manager->switchTo($tenant2);

    expect($manager->getContext()->id)->toBe(2)
        ->and($manager->getContext()->slug)->toBe('beta')
        ->and($manager->getContext()->databaseName)->toBe('tenant_beta')
        ->and($manager->connection()->getDatabaseName())->toBe('tenant_beta')
        ->and(config('database.connections.tenant.database'))->toBe('tenant_beta')
        ->and($manager->getContext()->id)->not->toBe(1)
        ->and($manager->getContext()->slug)->not->toBe('alpha')
        ->and($manager->getContext()->databaseName)->not->toBe('tenant_alpha')
        ->and($manager->connection()->getDatabaseName())->not->toBe('tenant_alpha');

    $manager->reset();

    expect($manager->getContext())->toBeNull()
        ->and(TenantContext::get())->toBeNull()
        ->and($manager->hasContext())->toBeFalse()
        ->and(config('database.connections.tenant.database'))->toBeNull();
});

test('TenantManager accepts only TenantContext and rejects raw strings', function () {
    $manager = app(TenantManager::class);

    expect(fn () => $manager->switchTo('raw-tenant-string')) // @phpstan-ignore-line
        ->toThrow(TypeError::class);
});
