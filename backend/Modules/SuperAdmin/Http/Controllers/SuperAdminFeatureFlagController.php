<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\SuperAdmin\Http\Requests\ToggleFeatureFlagRequest;
use Modules\SuperAdmin\Http\Resources\FeatureFlagResource;
use Modules\SuperAdmin\Services\SuperAdminFeatureFlagService;
use Shared\Responses\ApiResponse;

final class SuperAdminFeatureFlagController
{
    public function __construct(
        private readonly SuperAdminFeatureFlagService $featureFlagService,
    ) {}

    public function index(): JsonResponse
    {
        $flags = $this->featureFlagService->listFlags();

        return ApiResponse::success(
            data: FeatureFlagResource::collection($flags),
        );
    }

    public function toggle(string $key, ToggleFeatureFlagRequest $request): JsonResponse
    {
        $state = $request->has('is_enabled') ? $request->boolean('is_enabled') : null;
        $flag = $this->featureFlagService->toggleFlag($key, $state);

        $statusText = $flag->is_enabled ? 'enabled' : 'disabled';

        return ApiResponse::success(
            data: new FeatureFlagResource($flag),
            message: "Feature flag '{$flag->name}' is now {$statusText}.",
        );
    }
}
