<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Auth\Http\Requests\ForgotPasswordRequest;
use Modules\Auth\Http\Requests\ResetPasswordRequest;
use Modules\Auth\Jobs\SendPasswordResetEmail;
use Modules\Auth\Models\PasswordResetToken;
use Modules\Auth\Models\TenantUser;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Utils\HttpStatus;

final class PasswordResetController
{
    public function sendResetLinkEmail(ForgotPasswordRequest $request): JsonResponse
    {
        $context = TenantContext::get();
        $tenantId = $context !== null ? (string) $context->id : 'unknown';
        $ip = (string) $request->ip();

        $throttleKey = "password_reset:{$tenantId}:{$ip}";

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                message: 'Too many password reset attempts. Please try again later.',
                code: 'TOO_MANY_ATTEMPTS',
                status: HttpStatus::TOO_MANY_REQUESTS,
                headers: [
                    'Retry-After' => (string) $seconds,
                ],
            );
        }

        RateLimiter::hit($throttleKey, 60);

        $email = strtolower((string) $request->validated('email'));
        /** @var TenantUser|null $user */
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user !== null && $context !== null) {
            $plainToken = Str::random(64);
            $hashedToken = hash('sha256', $plainToken);

            PasswordResetToken::query()->where('email', $email)->delete();

            PasswordResetToken::query()->create([
                'email' => $email,
                'token' => $hashedToken,
                'created_at' => now(),
                'expires_at' => now()->addMinutes(60),
            ]);

            SendPasswordResetEmail::dispatch(
                tenantId: (string) $context->id,
                tenantSlug: $context->slug,
                tenantDatabaseName: $context->databaseName,
                email: $email,
                token: $plainToken,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'If an account with that email exists, a password reset link has been sent.',
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->validated('email'));
        $plainToken = (string) $request->validated('token');
        $hashedToken = hash('sha256', $plainToken);

        /** @var PasswordResetToken|null $record */
        $record = PasswordResetToken::query()
            ->where('email', $email)
            ->where('token', $hashedToken)
            ->first();

        if ($record === null || $record->expires_at === null || $record->expires_at->isPast()) {
            if ($record !== null) {
                PasswordResetToken::query()->where('email', $email)->delete();
            }

            return ApiResponse::error(
                message: 'Invalid or expired password reset token.',
                code: 'INVALID_RESET_TOKEN',
                status: HttpStatus::BAD_REQUEST,
            );
        }

        /** @var TenantUser|null $user */
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user === null) {
            PasswordResetToken::query()->where('email', $email)->delete();

            return ApiResponse::error(
                message: 'Invalid or expired password reset token.',
                code: 'INVALID_RESET_TOKEN',
                status: HttpStatus::BAD_REQUEST,
            );
        }

        $user->password = Hash::make((string) $request->validated('password'));
        $user->remember_token = Str::random(60);
        $user->save();

        // Invalidate all of that user's sessions across the session driver store
        $context = TenantContext::get();
        $tenantId = $context !== null ? (string) $context->id : 'unknown';
        $userSessionsKey = "user_sessions:{$tenantId}:{$user->id}";
        /** @var list<string> $sessionIds */
        $sessionIds = Cache::get($userSessionsKey, []);
        $sessionHandler = app('session')->driver()->getHandler();
        foreach ($sessionIds as $sessId) {
            $sessionHandler->destroy($sessId);
        }
        Cache::forget($userSessionsKey);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Single use: delete token
        PasswordResetToken::query()->where('email', $email)->delete();

        return ApiResponse::success(
            data: null,
            message: 'Password has been reset successfully.',
        );
    }
}
