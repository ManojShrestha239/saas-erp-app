<?php

declare(strict_types=1);

namespace Shared\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;
use Throwable;

final class HealthController
{
    public function check(): JsonResponse
    {
        $checks = [];
        $isHealthy = true;

        // 1. Central database check
        try {
            DB::connection('central')->select('SELECT 1 as ping');
            $checks['central_database'] = 'ok';
        } catch (Throwable $e) {
            $checks['central_database'] = 'unreachable: '.$e->getMessage();
            $isHealthy = false;
        }

        // 2. Redis check
        try {
            Redis::connection()->ping();
            $checks['redis'] = 'ok';
        } catch (Throwable $e) {
            $checks['redis'] = 'unreachable: '.$e->getMessage();
            $isHealthy = false;
        }

        // 3. Storage writeability check
        $storageOk = is_writable(storage_path('framework/cache')) && is_writable(storage_path('logs'));
        $checks['storage'] = $storageOk ? 'ok' : 'unwritable';
        if (! $storageOk) {
            $isHealthy = false;
        }

        // 4. OPcache status
        $checks['opcache'] = function_exists('opcache_get_status') && is_array(@opcache_get_status(false)) ? 'active' : 'disabled';

        // 5. Production safeguards
        $isProd = app()->isProduction();
        $debugEnabled = (bool) config('app.debug');
        $telescopeEnabled = (bool) config('telescope.enabled', false);

        if ($isProd && $debugEnabled) {
            $checks['debug_mode'] = 'warning: APP_DEBUG is true in production';
            $isHealthy = false;
        } else {
            $checks['debug_mode'] = $debugEnabled ? 'enabled (non-prod)' : 'disabled';
        }

        if ($isProd && $telescopeEnabled) {
            $checks['telescope'] = 'warning: telescope is enabled in production';
            $isHealthy = false;
        } else {
            $checks['telescope'] = $telescopeEnabled ? 'enabled (non-prod)' : 'disabled';
        }

        $data = [
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'environment' => app()->environment(),
            'debug' => $debugEnabled,
            'checks' => $checks,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        if (! $isHealthy) {
            return ApiResponse::error(
                message: 'System health check failed',
                code: 'HEALTH_CHECK_FAILED',
                errors: $checks,
                status: HttpStatus::SERVICE_UNAVAILABLE,
            );
        }

        return ApiResponse::success(
            data: $data,
            message: 'System is healthy'
        );
    }
}
