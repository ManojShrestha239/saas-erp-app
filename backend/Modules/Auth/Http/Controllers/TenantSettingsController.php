<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Auth\Enums\TenantPermission;
use Modules\Auth\Http\Requests\UpdateTenantSettingsRequest;
use Modules\Auth\Services\TenantSettingsService;
use Shared\Responses\ApiResponse;

final readonly class TenantSettingsController
{
    public function __construct(
        private TenantSettingsService $settingsService,
    ) {}

    /**
     * Web/Inertia route: renders the settings management UI.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(TenantPermission::SettingsManage->value)) {
            abort(403, 'Unauthorized to manage tenant settings.');
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->show($request);
        }

        return Inertia::render('settings/SettingsIndex', [
            'settings' => $this->settingsService->getAll(),
        ]);
    }

    /**
     * API endpoint to retrieve all tenant settings.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(TenantPermission::SettingsManage->value)) {
            abort(403, 'Unauthorized to manage tenant settings.');
        }

        return ApiResponse::success(
            data: $this->settingsService->getAll(),
            message: 'Settings retrieved successfully.'
        );
    }

    /**
     * API endpoint to update tenant settings.
     */
    public function update(UpdateTenantSettingsRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $updated = $this->settingsService->updateSettings($validated);

        return ApiResponse::success(
            data: $updated,
            message: 'Settings updated successfully.'
        );
    }
}
