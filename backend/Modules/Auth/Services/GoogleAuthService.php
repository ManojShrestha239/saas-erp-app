<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\AbstractProvider;
use Modules\Auth\Models\OAuthAccount;
use Modules\Auth\Models\TenantUser;
use Shared\Exceptions\ApiException;

final class GoogleAuthService
{
    public function __construct(
        private readonly SocialiteFactory $socialite,
    ) {}

    /**
     * Get redirect target URL for Google OAuth consent.
     */
    public function getRedirectTargetUrl(Request $request): string
    {
        $redirectUrl = $this->determineCallbackUrl($request);

        /** @var Provider $driver */
        $driver = $this->socialite->driver('google');

        if ($driver instanceof AbstractProvider) {
            $response = $driver
                ->redirectUrl($redirectUrl)
                ->scopes(['openid', 'profile', 'email'])
                ->redirect();

            return $response->getTargetUrl();
        }

        return $driver->redirect()->getTargetUrl();
    }

    /**
     * Authenticate and automatically link tenant user from Google OAuth callback.
     */
    public function handleCallback(Request $request): TenantUser
    {
        if ($request->has('error')) {
            $errorDescription = (string) ($request->query('error_description') ?: $request->query('error'));
            throw ApiException::badRequest(
                message: "Google authentication failed: {$errorDescription}",
                errorCode: 'OAUTH_FAILED',
            );
        }

        $redirectUrl = $this->determineCallbackUrl($request);

        /** @var Provider $driver */
        $driver = $this->socialite->driver('google');

        try {
            if ($driver instanceof AbstractProvider) {
                $driver->redirectUrl($redirectUrl);
            }
            /** @var SocialiteUser $googleUser */
            $googleUser = $driver->user();
        } catch (\Throwable $e) {
            throw ApiException::badRequest(
                message: 'Invalid OAuth state or token exchange failed: '.$e->getMessage(),
                errorCode: 'INVALID_OAUTH_STATE',
            );
        }

        return $this->linkAndRetrieveUser($googleUser);
    }

    /**
     * Link and retrieve tenant user given a SocialiteUser.
     * Enforces PRD §3.2: never link on an unverified email.
     */
    public function linkAndRetrieveUser(SocialiteUser $googleUser): TenantUser
    {
        $email = $googleUser->getEmail();
        $googleId = (string) $googleUser->getId();

        if ($email === null || trim($email) === '') {
            throw ApiException::badRequest(
                message: 'Google account has no email address associated.',
                errorCode: 'MISSING_OAUTH_EMAIL',
            );
        }

        $email = strtolower(trim($email));

        // Enforce verified email requirement (PRD §3.2)
        if (! $this->isEmailVerified($googleUser)) {
            throw ApiException::forbidden(
                message: 'Google email is not verified. Account linking requires a verified email address.',
                errorCode: 'UNVERIFIED_OAUTH_EMAIL',
            );
        }

        // 1. Check if OAuth account is already linked in this tenant
        /** @var OAuthAccount|null $oauthAccount */
        $oauthAccount = OAuthAccount::query()
            ->where('provider', 'google')
            ->where('provider_id', $googleId)
            ->first();

        if ($oauthAccount !== null) {
            /** @var TenantUser|null $user */
            $user = $oauthAccount->user;

            if ($user !== null) {
                if ($user->status === 'deactivated') {
                    throw ApiException::forbidden(
                        message: 'Your account has been deactivated.',
                        errorCode: 'ACCOUNT_DEACTIVATED',
                    );
                }

                return $user;
            }
        }

        // 2. Lookup existing user by email in this tenant DB
        /** @var TenantUser|null $user */
        $user = TenantUser::query()->where('email', $email)->first();

        if ($user === null) {
            throw ApiException::notFound(
                message: 'No account found matching this email address in this organization. Please ask your administrator for an invite.',
                errorCode: 'ACCOUNT_NOT_FOUND',
            );
        }

        if ($user->status === 'deactivated') {
            throw ApiException::forbidden(
                message: 'Your account has been deactivated.',
                errorCode: 'ACCOUNT_DEACTIVATED',
            );
        }

        // 3. Mark user's email as verified if not yet marked (Google verified this email)
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // 4. Create or update OAuth account link
        OAuthAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => 'google',
            ],
            [
                'provider_id' => $googleId,
                'email' => $email,
                'avatar' => $googleUser->getAvatar(),
            ],
        );

        return $user;
    }

    /**
     * Checks if Google user payload confirms email_verified === true.
     */
    public function isEmailVerified(SocialiteUser $googleUser): bool
    {
        /** @var mixed $raw */
        $raw = method_exists($googleUser, 'getRaw') ? $googleUser->getRaw() : null;
        if (! is_array($raw) || empty($raw)) {
            /** @var mixed $userAttr */
            $userAttr = property_exists($googleUser, 'user') ? $googleUser->user : null;
            $raw = is_array($userAttr) ? $userAttr : [];
        }

        $emailVerified = $raw['email_verified'] ?? null;

        return $emailVerified === true || $emailVerified === 'true' || $emailVerified === 1 || $emailVerified === '1';
    }

    /**
     * Determine dynamic callback URL based on request scheme and host.
     */
    private function determineCallbackUrl(Request $request): string
    {
        $configured = (string) config('services.google.redirect', '/api/v1/auth/google/callback');

        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return $configured;
        }

        return $request->root().'/'.ltrim($configured, '/');
    }
}
