<?php

declare(strict_types=1);

namespace Shared\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamController
{
    public function stream(Request $request): StreamedResponse
    {
        if (! app()->bound(TenantContext::class)) {
            throw ApiException::badRequest('Tenant context not resolved for event stream.', 'TENANT_NOT_RESOLVED');
        }

        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);
        $tenantId = $tenant->id;
        $channel = "tenant:{$tenantId}:stream";

        $lastEventId = $request->header('Last-Event-ID', (string) $request->query('last_event_id', ''));

        $response = new StreamedResponse(function () use ($channel, $tenantId, $lastEventId): void {
            // Disable execution time limit for long-lived connection
            if (! app()->environment('testing')) {
                set_time_limit(0);
                ignore_user_abort(false);
            }

            // Flush all output buffers in non-testing environments
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }

            $connectionId = (string) Str::ulid();

            // Initial connection acknowledgment
            echo "id: {$connectionId}\n";
            echo "event: connected\n";
            echo 'data: '.json_encode([
                'status' => 'connected',
                'tenant_id' => $tenantId,
                'connection_id' => $connectionId,
                'last_event_id' => $lastEventId,
                'timestamp' => now()->toISOString(),
            ])."\n\n";

            if (function_exists('flush')) {
                flush();
            }

            // In testing environment, exit immediately after connected event
            if (app()->runningUnitTests() || app()->environment('testing')) {
                return;
            }

            $lastHeartbeat = time();
            $heartbeatInterval = 15; // Heartbeat tick every 15 seconds

            try {
                // Subscribe to tenant-scoped Redis channel
                Redis::subscribe([$channel], function (string $rawMessage) use (&$lastHeartbeat): void {
                    if (connection_aborted()) {
                        exit;
                    }

                    /** @var array{event?: string, data?: mixed, id?: string} $payload */
                    $payload = json_decode($rawMessage, true) ?? [];
                    $eventName = $payload['event'] ?? 'message';
                    $eventData = json_encode($payload['data'] ?? []);
                    $eventId = $payload['id'] ?? (string) Str::ulid();

                    echo "id: {$eventId}\n";
                    echo "event: {$eventName}\n";
                    echo "data: {$eventData}\n\n";

                    if (function_exists('flush')) {
                        flush();
                    }

                    $lastHeartbeat = time();
                });
            } catch (\Throwable $e) {
                Log::warning("SSE stream Redis fallback to heartbeat loop: {$e->getMessage()}");

                // Fallback loop if Redis pub/sub is unavailable or in development
                while (! connection_aborted()) {
                    if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                        $heartbeatId = (string) Str::ulid();
                        echo "id: {$heartbeatId}\n";
                        echo "event: heartbeat\n";
                        echo 'data: '.json_encode(['timestamp' => now()->toISOString()])."\n\n";

                        if (function_exists('flush')) {
                            flush();
                        }

                        $lastHeartbeat = time();
                    }

                    sleep(2);
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
