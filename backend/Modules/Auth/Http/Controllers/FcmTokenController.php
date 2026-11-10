<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Http\Requests\DeleteFcmTokenRequest;
use Modules\Auth\Http\Requests\RegisterFcmTokenRequest;
use Modules\Auth\Models\TenantUser;
use Modules\Auth\Services\FcmDeviceTokenService;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;

final class FcmTokenController
{
    public function __construct(
        private readonly FcmDeviceTokenService $deviceTokenService,
    ) {}

    public function register(RegisterFcmTokenRequest $request): JsonResponse
    {
        /** @var TenantUser $user */
        $user = $request->user();

        /** @var string $token */
        $token = $request->validated('token');
        /** @var string $platform */
        $platform = $request->validated('platform', 'web');
        /** @var string|null $deviceName */
        $deviceName = $request->validated('device_name');

        $deviceToken = $this->deviceTokenService->registerToken(
            userId: $user->id,
            token: $token,
            platform: $platform,
            deviceName: $deviceName,
        );

        return ApiResponse::success(
            data: [
                'token' => $deviceToken->token,
                'platform' => $deviceToken->platform,
                'device_name' => $deviceToken->device_name,
                'last_used_at' => $deviceToken->last_used_at?->toIso8601String(),
            ],
            message: 'FCM device token registered successfully.',
            status: HttpStatus::CREATED,
        );
    }

    public function destroy(DeleteFcmTokenRequest $request): JsonResponse
    {
        /** @var string $token */
        $token = $request->validated('token');

        $this->deviceTokenService->removeToken($token);

        return ApiResponse::success(
            data: null,
            message: 'FCM device token removed successfully.',
            status: HttpStatus::OK,
        );
    }
}
