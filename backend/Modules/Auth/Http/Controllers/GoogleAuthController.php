<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Http\Resources\UserResource;
use Modules\Auth\Services\GoogleAuthService;
use Shared\Responses\ApiResponse;
use Shared\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class GoogleAuthController
{
    public function __construct(
        private readonly GoogleAuthService $googleAuthService,
    ) {}

    /**
     * Redirect user to Google OAuth consent screen.
     */
    public function redirect(Request $request): Response
    {
        $targetUrl = $this->googleAuthService->getRedirectTargetUrl($request);

        if ($request->boolean('redirect') || $request->query('mode') === 'redirect') {
            return redirect()->away($targetUrl);
        }

        return ApiResponse::success(
            data: ['url' => $targetUrl],
            message: 'OAuth redirect URL generated.',
        );
    }

    /**
     * Handle callback from Google OAuth.
     */
    public function callback(Request $request): Response
    {
        $user = $this->googleAuthService->handleCallback($request);

        $context = TenantContext::get();
        $tenantId = $context !== null ? (string) $context->id : 'unknown';

        $request->session()->regenerate(true);
        Auth::guard('web')->login($user);
        $request->session()->put('tenant_id', $tenantId);
        $request->session()->put('auth_password_hash', $user->getAuthPassword());

        $userSessionsKey = "user_sessions:{$tenantId}:{$user->id}";
        /** @var list<string> $sessionIds */
        $sessionIds = Cache::get($userSessionsKey, []);
        $sessionIds[] = $request->session()->getId();
        Cache::put($userSessionsKey, array_values(array_unique($sessionIds)), now()->addMinutes((int) config('session.lifetime', 120)));

        if ($request->boolean('redirect') || $request->query('mode') === 'redirect') {
            return redirect()->intended('/dashboard');
        }

        return ApiResponse::success(
            data: [
                'user' => (new UserResource($user))->resolve($request),
            ],
            message: 'Authenticated successfully with Google.',
        );
    }
}
