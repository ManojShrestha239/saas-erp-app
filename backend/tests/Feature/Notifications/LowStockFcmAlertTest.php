<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\FcmDeviceToken;
use Modules\Auth\Models\TenantUser;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Events\LowStockDetected;
use Modules\Inventory\Listeners\SendLowStockFcmAlert;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropLowStockFcmTestDatabase')) {
    function dropLowStockFcmTestDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_fcm_low_stock') {
            return;
        }

        TestCase::dropTestTenantDatabase($dbName);
    }
}

beforeEach(function (): void {
    app('cache')->flush();
    try {
        Cache::store('redis')->flush();
    } catch (Throwable) {
        // Redis store might not be available
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropLowStockFcmTestDatabase('tenant_fcm_low_stock');
    Domain::query()->where('host', 'lowstock-fcm.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'lowstock-fcm')->delete();

    $tenantId = (string) Str::ulid();
    $tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'LowStock FCM Corp',
        'slug' => 'lowstock-fcm',
        'database_name' => 'tenant_fcm_low_stock',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'lowstock-fcm.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@lowstock-fcm.nexuserp.test',
        ownerName: 'FCM Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    TenantUser::where('email', 'owner@lowstock-fcm.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->owner = TenantUser::where('email', 'owner@lowstock-fcm.nexuserp.test')->firstOrFail();

    // Create Warehouse Manager
    $this->whManager = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'FCM WH Mgr',
        'email' => 'whmgr@lowstock-fcm.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->whManager->assignRole(TenantRole::WarehouseManager->value);

    // Create Warehouse
    $this->warehouse = Warehouse::create([
        'id' => (string) Str::ulid(),
        'name' => 'Central Hub',
        'code' => 'HUB-01',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->tenant = $tenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropLowStockFcmTestDatabase('tenant_fcm_low_stock');
});

test('SendLowStockFcmAlert sends multicast notification to warehouse managers and owners when stock drops below threshold', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
        'services.fcm.api_url' => 'https://fcm.googleapis.com/v1/projects',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/ok_msg',
        ], 200),
    ]);

    // Register tokens for both WH Manager and Owner
    FcmDeviceToken::create([
        'user_id' => $this->whManager->id,
        'token' => 'wh_token_abc',
        'platform' => 'android',
        'last_used_at' => now(),
    ]);
    FcmDeviceToken::create([
        'user_id' => $this->owner->id,
        'token' => 'owner_token_def',
        'platform' => 'web',
        'last_used_at' => now(),
    ]);

    $product = Product::create([
        'name' => 'Thermal Printer Ribbon',
        'sku' => 'RIBBON-01',
        'cost_price_cents' => 200,
        'selling_price_cents' => 500,
        'low_stock_threshold' => 10,
        'is_active' => true,
    ]);

    /** @var StockService $stockService */
    $stockService = app(StockService::class);

    // Receive 15
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $this->warehouse->id,
        productId: $product->id,
        type: StockMovementType::PurchaseReceipt,
        quantity: 15,
        userId: $this->whManager->id,
    ));

    // Deliver 8 (15 - 8 = 7, <= threshold of 10)
    $stockService->recordMovement(new RecordStockMovementData(
        warehouseId: $this->warehouse->id,
        productId: $product->id,
        type: StockMovementType::SalesDelivery,
        quantity: 8,
        userId: $this->whManager->id,
    ));

    Http::assertSent(function ($request): bool {
        return $request['message']['notification']['title'] === 'Low Stock Alert: Thermal Printer Ribbon'
            && str_contains($request['message']['notification']['body'], 'down to 7 at Central Hub')
            && $request['message']['data']['sku'] === 'RIBBON-01'
            && in_array($request['message']['token'], ['wh_token_abc', 'owner_token_def'], true);
    });
});

test('SendLowStockFcmAlert automatically prunes unregistered tokens reported by FCM', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
        'services.fcm.api_url' => 'https://fcm.googleapis.com/v1/projects',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'Requested entity was not found.',
                'status' => 'NOT_FOUND',
                'details' => [['errorCode' => 'UNREGISTERED']],
            ],
        ], 404),
    ]);

    FcmDeviceToken::create([
        'user_id' => $this->whManager->id,
        'token' => 'stale_wh_token',
        'platform' => 'web',
        'last_used_at' => now(),
    ]);

    expect(FcmDeviceToken::where('token', 'stale_wh_token')->exists())->toBeTrue();

    $listener = app(SendLowStockFcmAlert::class);
    $event = new LowStockDetected(
        tenantId: $this->tenant->id,
        productId: (string) Str::ulid(),
        productName: 'Defective Cable',
        sku: 'CABLE-DEF',
        warehouseId: $this->warehouse->id,
        warehouseName: $this->warehouse->name,
        quantityOnHand: 2,
        lowStockThreshold: 10,
    );

    $listener->handle($event);

    expect(FcmDeviceToken::where('token', 'stale_wh_token')->exists())->toBeFalse();
});
