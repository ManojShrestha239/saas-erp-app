<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Services\FcmDeviceTokenService;
use Modules\Inventory\Events\LowStockDetected;
use Shared\Services\FcmService;

final class SendLowStockFcmAlert
{
    public function __construct(
        private readonly FcmService $fcmService,
        private readonly FcmDeviceTokenService $deviceTokenService,
    ) {}

    public function handle(LowStockDetected $event): void
    {
        if (empty($event->tenantId)) {
            return;
        }

        try {
            $tokens = $this->deviceTokenService->getTokensForRoles([
                TenantRole::WarehouseManager->value,
                TenantRole::Owner->value,
            ]);

            if (empty($tokens)) {
                return;
            }

            $title = "Low Stock Alert: {$event->productName}";
            $body = "{$event->productName} ({$event->sku}) is down to {$event->quantityOnHand} at {$event->warehouseName} (threshold: {$event->lowStockThreshold}).";

            $payload = [
                'type' => 'low_stock_alert',
                'product_id' => $event->productId,
                'product_name' => $event->productName,
                'sku' => $event->sku,
                'warehouse_id' => $event->warehouseId,
                'warehouse_name' => $event->warehouseName,
                'quantity_on_hand' => $event->quantityOnHand,
                'low_stock_threshold' => $event->lowStockThreshold,
                'url' => '/inventory',
            ];

            $result = $this->fcmService->sendMulticast(
                tokens: $tokens,
                title: $title,
                body: $body,
                data: $payload,
            );

            if (! empty($result['invalid_tokens'])) {
                $this->deviceTokenService->removeTokens($result['invalid_tokens']);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send low stock FCM alert for product {$event->productId}: {$e->getMessage()}");
        }
    }
}
