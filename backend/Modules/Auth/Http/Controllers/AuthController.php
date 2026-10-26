<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

        if ($user->status === 'deactivated') {
            return ApiResponse::error(
                message: 'Your account has been deactivated.',
                code: 'ACCOUNT_DEACTIVATED',
                status: HttpStatus::FORBIDDEN,
            );
        }

        if (config('auth.require_email_verification', true) && $user->email_verified_at === null) {
            return ApiResponse::error(
                message: 'Your email address is not verified.',
                code: 'EMAIL_NOT_VERIFIED',
                status: HttpStatus::FORBIDDEN,
            );
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate(true);
        Auth::guard('web')->login($user);
        $request->session()->put('tenant_id', $tenantId);
        $request->session()->put('auth_password_hash', $user->getAuthPassword());

        $userSessionsKey = "user_sessions:{$tenantId}:{$user->id}";
        /** @var list<string> $sessionIds */
        $sessionIds = Cache::get($userSessionsKey, []);
        $sessionIds[] = $request->session()->getId();
        Cache::put($userSessionsKey, array_values(array_unique($sessionIds)), now()->addMinutes((int) config('session.lifetime', 120)));

        return ApiResponse::success(
            data: [
                'user' => (new UserResource($user))->resolve($request),
            ],
            message: 'Login successful.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $context = TenantContext::get();
        $tenantId = $context !== null ? (string) $context->id : 'unknown';
        /** @var TenantUser|null $user */
        $user = Auth::guard('web')->user();

        if ($user !== null && $request->hasSession()) {
            $userSessionsKey = "user_sessions:{$tenantId}:{$user->id}";
            /** @var list<string> $sessionIds */
            $sessionIds = Cache::get($userSessionsKey, []);
            $currentId = $request->session()->getId();
            $sessionIds = array_values(array_filter($sessionIds, fn (string $id): bool => $id !== $currentId));
            if (! empty($sessionIds)) {
                Cache::put($userSessionsKey, $sessionIds, now()->addMinutes((int) config('session.lifetime', 120)));
            } else {
                Cache::forget($userSessionsKey);
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(
            data: null,
            message: 'Logged out successfully.',
        );
    }
}
