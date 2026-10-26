<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Auth\Http\Requests\ResendVerificationRequest;
use Modules\Auth\Http\Requests\VerifyEmailRequest;
use Modules\Auth\Jobs\SendEmailVerificationEmail;
use Modules\Auth\Models\EmailVerificationToken;
use Modules\Auth\Models\TenantUser;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Shared\Utils\HttpStatus;

final class EmailVerificationController
{
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->validated('email'));
        $plainToken = (string) $request->validated('token');
        $hashedToken = hash('sha256', $plainToken);

        /** @var EmailVerificationToken|null $record */
        $record = EmailVerificationToken::query()
            ->where('email', $email)
            ->where('token', $hashedToken)
            ->first();

        if ($record === null || $record->expires_at === null || $record->expires_at->isPast()) {
            if ($record !== null) {
                EmailVerificationToken::query()->where('email', $email)->delete();
            }

            return ApiResponse::error(
                message: 'Invalid or expired email verification token.',
                code: 'INVALID_VERIFICATION_TOKEN',
                status: HttpStatus::BAD_REQUEST,
            );
        }

        /** @var TenantUser|null $user */
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user === null) {
            EmailVerificationToken::query()->where('email', $email)->delete();

            return ApiResponse::error(
                message: 'Invalid or expired email verification token.',
                code: 'INVALID_VERIFICATION_TOKEN',
                status: HttpStatus::BAD_REQUEST,
            );
        }

        $user->email_verified_at = now();
        $user->save();

        EmailVerificationToken::query()->where('email', $email)->delete();

        return ApiResponse::success(
            data: null,
            message: 'Email verified successfully.',
        );
    }

    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $context = TenantContext::get();
        $tenantId = $context !== null ? (string) $context->id : 'unknown';
        $ip = (string) $request->ip();

        $throttleKey = "email_verify_resend:{$tenantId}:{$ip}";

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                message: 'Too many verification requests. Please try again later.',
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

        if ($user !== null && $user->email_verified_at === null && $context !== null) {
            $plainToken = Str::random(64);
            $hashedToken = hash('sha256', $plainToken);

            EmailVerificationToken::query()->where('email', $email)->delete();

            EmailVerificationToken::query()->create([
                'email' => $email,
                'token' => $hashedToken,
                'created_at' => now(),
                'expires_at' => now()->addMinutes(60),
            ]);

            SendEmailVerificationEmail::dispatch(
                tenantId: (string) $context->id,
                tenantSlug: $context->slug,
                tenantDatabaseName: $context->databaseName,
                email: $email,
                token: $plainToken,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'If an unverified account with that email exists, a verification link has been sent.',
        );
    }
}
