<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Http\Middleware\IdentifyTenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

function dropSpecificTenantDatabase(string $dbName): void
{
    // Safety guard: only drop test-specific tenant databases
    if (! in_array($dbName, ['tenant_alpha', 'tenant_beta'], true) && ! str_starts_with($dbName, 'tenant_test_')) {
        return;
    }

    try {
        $config = config('database.connections.central');
        /** @var array{host: string, port: int|string, username: string, password: string} $config */
        $pdo = new PDO(
            dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
            $pdo->quote($dbName).' AND pid <> pg_backend_pid()');

        $quotedName = '"'.str_replace('"', '""', $dbName).'"';
        $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
    } catch (Throwable) {
        // Best-effort cleanup
    }
}

beforeEach(function (): void {
    app('cache')->flush();
    Cache::store('redis')->flush();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropSpecificTenantDatabase('tenant_alpha');
    dropSpecificTenantDatabase('tenant_beta');

    Domain::query()->whereIn('host', ['alpha.nexuserp.test', 'beta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha', 'beta'])->delete();

    // Register test-only route behind identify.tenant alias
    Route::middleware(['identify.tenant'])->get('/test-isolation/status', function (Request $request) {
        $context = TenantContext::get();
        $markerKey = (string) $request->query('key', 'isolation.marker');

        /** @var object{key: string, value: string|null}|null $settingRow */
        $settingRow = DB::connection('tenant')->table('settings')->where('key', $markerKey)->first();

        return response()->json([
            'slug' => $context?->slug,
            'settings_count' => DB::connection('tenant')->table('settings')->count(),
            'users_count' => DB::connection('tenant')->table('users')->count(),
            'marker' => $settingRow?->value,
            'has_marker' => $settingRow !== null,
            'users' => DB::connection('tenant')->table('users')->pluck('email')->all(),
        ]);
    });
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropSpecificTenantDatabase('tenant_alpha');
    dropSpecificTenantDatabase('tenant_beta');

    Domain::query()->whereIn('host', ['alpha.nexuserp.test', 'beta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha', 'beta'])->delete();

    TenantContext::set(null);
    app('cache')->flush();
    Cache::store('redis')->flush();
});

test('Phase 1 exit criterion: two tenants on two subdomains see isolated data via real HTTP requests', function (): void {
    // 1. Provision two tenants: alpha and beta using existing provisioning job via dispatchSync
    $alphaId = (string) Str::ulid();
    $alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha Tenant',
        'slug' => 'alpha',
        'database_name' => 'tenant_alpha',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    $betaId = (string) Str::ulid();
    $betaTenant = Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Tenant',
        'slug' => 'beta',
        'database_name' => 'tenant_beta',
        'status' => 'provisioning',
    ]);

    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta.nexuserp.test',
        'is_primary' => true,
    ]);

    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // Verify both are active in central
    expect($alphaTenant->fresh()->status)->toBe('active')
        ->and($betaTenant->fresh()->status)->toBe('active');

    // 2. Insert setting with key isolation.marker and value alpha into alpha's database only
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $alphaContext = new TenantContext(
        id: $alphaId,
        slug: 'alpha',
        databaseName: 'tenant_alpha',
    );
    $tenantManager->switchTo($alphaContext);

    DB::connection('tenant')->table('settings')->insert([
        'key' => 'isolation.marker',
        'value' => 'alpha',
        'group' => 'general',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantManager->reset();
    expect(TenantContext::get())->toBeNull();

    // 3. Request via alpha host: confirm marker is visible, count is 7, owner user is visible
    $alphaResponse1 = $this->getJson('http://alpha.nexuserp.test/test-isolation/status');

    $alphaResponse1->assertOk()
        ->assertJsonPath('slug', 'alpha')
        ->assertJsonPath('settings_count', 7) // 6 seeded defaults + 1 isolation.marker
        ->assertJsonPath('users_count', 1)
        ->assertJsonPath('marker', 'alpha')
        ->assertJsonPath('has_marker', true)
        ->assertJsonPath('users', ['owner@alpha.nexuserp.test']);

    expect(TenantContext::get())->toBeNull();

    // 4. Request via beta host: confirm marker is NOT visible, count is 6, alpha owner is not visible
    $betaResponse1 = $this->getJson('http://beta.nexuserp.test/test-isolation/status');

    $betaResponse1->assertOk()
        ->assertJsonPath('slug', 'beta')
        ->assertJsonPath('settings_count', 6) // only the 6 seeded defaults
        ->assertJsonPath('users_count', 1)
        ->assertJsonPath('marker', null)
        ->assertJsonPath('has_marker', false)
        ->assertJsonPath('users', ['owner@beta.nexuserp.test']);

    expect($betaResponse1->json('users'))->not->toContain('owner@alpha.nexuserp.test');
    expect(TenantContext::get())->toBeNull();

    // 5. Check the reverse: insert isolation.marker with value beta into beta's database only
    $betaContext = new TenantContext(
        id: $betaId,
        slug: 'beta',
        databaseName: 'tenant_beta',
    );
    $tenantManager->switchTo($betaContext);

    DB::connection('tenant')->table('settings')->insert([
        'key' => 'isolation.marker',
        'value' => 'beta',
        'group' => 'general',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantManager->reset();
    expect(TenantContext::get())->toBeNull();

    // Confirm via beta host that beta's marker is visible
    $betaResponse2 = $this->getJson('http://beta.nexuserp.test/test-isolation/status');

    $betaResponse2->assertOk()
        ->assertJsonPath('slug', 'beta')
        ->assertJsonPath('settings_count', 7)
        ->assertJsonPath('users_count', 1)
        ->assertJsonPath('marker', 'beta')
        ->assertJsonPath('has_marker', true)
        ->assertJsonPath('users', ['owner@beta.nexuserp.test']);

    expect($betaResponse2->json('users'))->not->toContain('owner@alpha.nexuserp.test');
    expect(TenantContext::get())->toBeNull();

    // Confirm via alpha host that alpha still sees its own marker and users, not beta's
    $alphaResponse2 = $this->getJson('http://alpha.nexuserp.test/test-isolation/status');

    $alphaResponse2->assertOk()
        ->assertJsonPath('slug', 'alpha')
        ->assertJsonPath('settings_count', 7)
        ->assertJsonPath('users_count', 1)
        ->assertJsonPath('marker', 'alpha')
        ->assertJsonPath('has_marker', true)
        ->assertJsonPath('users', ['owner@alpha.nexuserp.test']);

    expect($alphaResponse2->json('users'))->not->toContain('owner@beta.nexuserp.test');
    expect(TenantContext::get())->toBeNull();

    // 6. Suspend beta: confirm beta host returns 403 TENANT_SUSPENDED, alpha host still returns its own data
    Tenant::on('central')->where('slug', 'beta')->update([
        'status' => 'suspended',
    ]);

    IdentifyTenant::invalidateCache('beta');
    IdentifyTenant::invalidateCache('beta.nexuserp.test');
    Cache::store('redis')->flush();

    $suspendedBetaResponse = $this->getJson('http://beta.nexuserp.test/test-isolation/status');

    $suspendedBetaResponse->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'TENANT_SUSPENDED')
        ->assertJsonPath('message', 'Tenant is suspended');

    expect(TenantContext::get())->toBeNull();

    // Confirm alpha host still returns its own data
    $alphaAfterSuspendResponse = $this->getJson('http://alpha.nexuserp.test/test-isolation/status');

    $alphaAfterSuspendResponse->assertOk()
        ->assertJsonPath('slug', 'alpha')
        ->assertJsonPath('settings_count', 7)
        ->assertJsonPath('users_count', 1)
        ->assertJsonPath('marker', 'alpha')
        ->assertJsonPath('has_marker', true)
        ->assertJsonPath('users', ['owner@alpha.nexuserp.test']);

    // 7. Confirm TenantContext::get() is null after each request
    expect(TenantContext::get())->toBeNull();
});
