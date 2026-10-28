<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Inventory\Events\LowStockDetected;

final class PublishLowStockAlertToStream
{
    public function handle(LowStockDetected $event): void
    {
        if (empty($event->tenantId)) {
            return;
        }

        $channel = "tenant:{$event->tenantId}:stream";
        $payload = json_encode([
            'event' => 'low_stock_alert',
            'data' => $event->toStreamPayload(),
        ]);

        try {
            Redis::publish($channel, (string) $payload);
        } catch (\Throwable $e) {
            Log::warning("Failed to publish SSE event to Redis channel '{$channel}': {$e->getMessage()}");
        }
    }
}
