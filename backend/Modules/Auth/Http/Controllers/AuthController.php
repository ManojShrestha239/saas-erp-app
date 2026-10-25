<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Resources\UserResource;
use Modules\Auth\Models\TenantUser;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Utils\HttpStatus;

final class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $context = TenantContext::get();
        $tenantId = $context !== null ? (string) $context->id : 'unknown';
        $email = strtolower((string) $request->validated('email'));
        $password = (string) $request->validated('password');
        $ip = (string) $request->ip();

        $throttleKey = "login:{$tenantId}:{$email}:{$ip}";

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

        /** @var TenantUser|null $user */
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return ApiResponse::error(
                message: 'Invalid credentials.',
                code: 'INVALID_CREDENTIALS',
                status: HttpStatus::UNAUTHORIZED,
            );
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate(true);
        Auth::guard('web')->login($user);
        $request->session()->put('tenant_id', $tenantId);

        return ApiResponse::success(
            data: [
                'user' => (new UserResource($user))->resolve($request),
            ],
            message: 'Login successful.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(
            data: null,
            message: 'Logged out successfully.',
        );
    }
}
