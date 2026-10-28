<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Shared\Http\Middleware\IdentifyTenant;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

final class IdentifyTenantTestModel extends Model
{
    protected $connection = 'central';

    protected $table = 'tenants';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}

final class IdentifyDomainTestModel extends Model
{
    protected $connection = 'central';

    protected $table = 'domains';

    protected $guarded = [];
}

beforeEach(function (): void {
    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    Route::middleware(['api', 'tenant'])->group(function (): void {
        Route::get('/api/tenant-ping', function (): JsonResponse {
            $context = TenantContext::get();

            return ApiResponse::success([
                'tenant_id' => $context?->id,
                'slug' => $context?->slug,
                'database' => $context?->databaseName,
            ]);
        });

        Route::get('/api/tenant-error', function (): never {
            throw new RuntimeException('Unhandled route exception during request');
        });
    });

    Route::middleware(['web', 'tenant'])->group(function (): void {
        Route::get('/web/tenant-page', function (): string {
            return 'Hello '.TenantContext::get()?->slug;
        });
    });
});

afterEach(function (): void {
    IdentifyDomainTestModel::query()->whereIn('host', [
        'suspended.nexuserp.test',
        'provisioning.nexuserp.test',
        'failed.nexuserp.test',
        'acme.nexuserp.test',
        'cached.nexuserp.test',
        'lifecycle.nexuserp.test',
        'exception.nexuserp.test',
    ])->delete();
    IdentifyTenantTestModel::query()->whereIn('slug', [
        'suspended',
        'provisioning',
        'failed-tenant',
        'acme',
        'cached-corp',
        'lifecycle',
        'exception-corp',
    ])->delete();
    app(TenantManager::class)->reset();
    Cache::store('redis')->flush();
});

test('unknown tenant subdomain on API request returns 404 ApiException envelope', function (): void {
    $response = $this->getJson('http://unknown-tenant.nexuserp.test/api/tenant-ping');

    $response->assertStatus(404)
        ->assertExactJson([
            'success' => false,
            'message' => 'Tenant not found',
            'errors' => [],
            'code' => 'TENANT_NOT_FOUND',
        ]);

    expect($response->getContent())->toContain('"errors":{}');
});

test('unknown tenant subdomain on web request returns Laravel default 404 response', function (): void {
    $response = $this->get('http://unknown-tenant.nexuserp.test/web/tenant-page', [
        'Accept' => 'text/html',
    ]);

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    expect($response->getContent())->toContain('<html');
});

test('suspended tenant returns 403 with TENANT_SUSPENDED code', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Suspended Corp',
        'slug' => 'suspended',
        'database_name' => 'tenant_suspended',
        'status' => 'suspended',
    ]);

    $response = $this->getJson('http://suspended.nexuserp.test/api/tenant-ping');

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Tenant is suspended',
            'errors' => [],
            'code' => 'TENANT_SUSPENDED',
        ]);
});

test('provisioning tenant returns 403 with TENANT_UNAVAILABLE code', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Provisioning Corp',
        'slug' => 'provisioning',
        'database_name' => 'tenant_provisioning',
        'status' => 'provisioning',
    ]);

    $response = $this->getJson('http://provisioning.nexuserp.test/api/tenant-ping');

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Tenant is unavailable',
            'errors' => [],
            'code' => 'TENANT_UNAVAILABLE',
        ]);
});

test('provisioning_failed tenant returns 403 with TENANT_UNAVAILABLE code', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Failed Corp',
        'slug' => 'failed-tenant',
        'database_name' => 'tenant_failed',
        'status' => 'provisioning_failed',
        'provisioning_error' => 'Database connection timeout during migration',
    ]);

    $response = $this->getJson('http://failed-tenant.nexuserp.test/api/tenant-ping');

    $response->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'Tenant is unavailable',
            'errors' => [],
            'code' => 'TENANT_UNAVAILABLE',
        ]);
});

test('valid active tenant resolves context, switches tenant connection, and returns success', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Acme Corporation',
        'slug' => 'acme',
        'database_name' => 'tenant_acme',
        'status' => 'active',
    ]);

    $response = $this->getJson('http://acme.nexuserp.test/api/tenant-ping');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'tenant_id' => $tenantId,
                'slug' => 'acme',
                'database' => 'tenant_acme',
            ],
        ]);
});

test('central tenant lookup is cached in Redis with short TTL and can be invalidated', function (): void {
    $tenantId = (string) Str::ulid();

    $tenant = IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Cached Corp',
        'slug' => 'cached-corp',
        'database_name' => 'tenant_cached',
        'status' => 'active',
    ]);

    $cacheKey = IdentifyTenant::cacheKey('cached-corp');

    expect(Cache::store('redis')->has($cacheKey))->toBeFalse();

    $this->getJson('http://cached-corp.nexuserp.test/api/tenant-ping')
        ->assertStatus(200);

    expect(Cache::store('redis')->has($cacheKey))->toBeTrue();

    $cached = Cache::store('redis')->get($cacheKey);
    expect($cached['slug'])->toBe('cached-corp')
        ->and($cached['status'])->toBe('active');

    // Change status in central database
    $tenant->update(['status' => 'suspended']);

    // While cache exists, old active status is used
    $this->getJson('http://cached-corp.nexuserp.test/api/tenant-ping')
        ->assertStatus(200);

    // Invalidate cache
    IdentifyTenant::invalidateCache('cached-corp');
    expect(Cache::store('redis')->has($cacheKey))->toBeFalse();

    // Next request immediately reflects the updated status
    $this->getJson('http://cached-corp.nexuserp.test/api/tenant-ping')
        ->assertStatus(403)
        ->assertJson(['code' => 'TENANT_SUSPENDED']);
});

test('tenant context is reset after request lifecycle terminates', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Lifecycle Corp',
        'slug' => 'lifecycle',
        'database_name' => 'tenant_lifecycle',
        'status' => 'active',
    ]);

    expect(TenantContext::get())->toBeNull();

    $response = $this->getJson('http://lifecycle.nexuserp.test/api/tenant-ping');
    $response->assertStatus(200);

    // After response is sent and kernel terminates, context is reset
    expect(TenantContext::get())->toBeNull()
        ->and(config('database.connections.tenant.database'))->toBeNull();
});

test('central domain request skips tenant resolution', function (): void {
    expect(TenantContext::get())->toBeNull();

    $response = $this->getJson('http://nexuserp.test/api/tenant-ping');

    $response->assertStatus(200)
        ->assertExactJson([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'tenant_id' => null,
                'slug' => null,
                'database' => null,
            ],
        ]);

    expect(TenantContext::get())->toBeNull();
});

test('web request to suspended tenant receives 403 HTML page', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Suspended Corp',
        'slug' => 'suspended',
        'database_name' => 'tenant_suspended',
        'status' => 'suspended',
    ]);

    $response = $this->get('http://suspended.nexuserp.test/web/tenant-page', [
        'Accept' => 'text/html',
    ]);

    $response->assertStatus(403);
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    expect($response->getContent())->toContain('<html')
        ->and($response->getContent())->toContain('Tenant is suspended');
});

test('tenant context is cleared when an exception is thrown during request', function (): void {
    $tenantId = (string) Str::ulid();

    IdentifyTenantTestModel::query()->create([
        'id' => $tenantId,
        'name' => 'Exception Corp',
        'slug' => 'exception-corp',
        'database_name' => 'tenant_exception',
        'status' => 'active',
    ]);

    expect(TenantContext::get())->toBeNull();

    $response = $this->getJson('http://exception-corp.nexuserp.test/api/tenant-error');
    $response->assertStatus(500);

    // Ensure TenantContext and tenant database connection config are reset even after exception
    expect(TenantContext::get())->toBeNull()
        ->and(config('database.connections.tenant.database'))->toBeNull();
});
