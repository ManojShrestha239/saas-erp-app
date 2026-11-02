<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\SuperAdmin\Services\SuperAdminDashboardService;
use Shared\Responses\ApiResponse;

final class SuperAdminDashboardController
{
    public function __construct(
        private readonly SuperAdminDashboardService $dashboardService,
    ) {}

    public function stats(): JsonResponse
    {
        $overview = $this->dashboardService->getOverview();

        return ApiResponse::success(
            data: $overview,
        );
    }
}
