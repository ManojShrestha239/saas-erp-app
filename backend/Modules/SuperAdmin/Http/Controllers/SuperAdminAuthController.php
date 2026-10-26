<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\SuperAdmin\Http\Requests\SuperAdminLoginRequest;
use Modules\SuperAdmin\Http\Resources\SuperAdminResource;
use Modules\SuperAdmin\Models\SuperAdmin;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;

final class SuperAdminAuthController
{
    public function login(SuperAdminLoginRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->validated('email'));
        $password = (string) $request->validated('password');
        $ip = (string) $request->ip();

        $throttleKey = "super_admin_login:{$email}:{$ip}";

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                message: 'Too many login attempts. Please try again later.',
                code: 'TOO_MANY_ATTEMPTS',
                status: HttpStatus::TOO_MANY_REQUESTS,
                headers: [
                    'Retry-After' => (string) $seconds,
                ],
            );
        }

        /** @var SuperAdmin|null $admin */
        $admin = SuperAdmin::query()->where('email', $email)->first();

        if ($admin === null || ! Hash::check($password, $admin->password)) {
            RateLimiter::hit($throttleKey, 60);

            return ApiResponse::error(
                message: 'Invalid credentials.',
                code: 'INVALID_CREDENTIALS',
                status: HttpStatus::UNAUTHORIZED,
            );
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate(true);
        Auth::guard('super_admin')->login($admin);

        return ApiResponse::success(
            data: [
                'user' => (new SuperAdminResource($admin))->resolve($request),
            ],
            message: 'Super admin login successful.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('super_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(
            message: 'Super admin logged out successfully.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var SuperAdmin|null $admin */
        $admin = Auth::guard('super_admin')->user();

        if ($admin === null) {
            throw ApiException::unauthorized();
        }

        return ApiResponse::success(
            data: [
                'user' => (new SuperAdminResource($admin))->resolve($request),
            ],
        );
    }
}
