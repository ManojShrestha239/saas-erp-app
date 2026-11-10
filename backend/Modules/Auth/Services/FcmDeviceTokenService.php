<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Support\Carbon;
use Modules\Auth\Models\FcmDeviceToken;
use Modules\Auth\Models\TenantUser;

final class FcmDeviceTokenService
{
    public function registerToken(
        string $userId,
        string $token,
        string $platform = 'web',
        ?string $deviceName = null
    ): FcmDeviceToken {
        /** @var FcmDeviceToken $deviceToken */
        $deviceToken = FcmDeviceToken::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $userId,
                'platform' => $platform,
                'device_name' => $deviceName,
                'last_used_at' => Carbon::now(),
            ]
        );

        return $deviceToken;
    }

    public function removeToken(string $token): bool
    {
        return (bool) FcmDeviceToken::where('token', $token)->delete();
    }

    /**
     * @param  list<string>  $tokens
     */
    public function removeTokens(array $tokens): int
    {
        if (empty($tokens)) {
            return 0;
        }

        return FcmDeviceToken::whereIn('token', $tokens)->delete();
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    public function getTokensForRoles(array $roleNames): array
    {
        if (empty($roleNames)) {
            return [];
        }

        /** @var list<string> $userIds */
        $userIds = TenantUser::role($roleNames)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if (empty($userIds)) {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = FcmDeviceToken::whereIn('user_id', $userIds)
            ->pluck('token')
            ->all();

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    public function getTokensForPermissions(array $permissionNames): array
    {
        if (empty($permissionNames)) {
            return [];
        }

        /** @var list<string> $userIds */
        $userIds = TenantUser::permission($permissionNames)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if (empty($userIds)) {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = FcmDeviceToken::whereIn('user_id', $userIds)
            ->pluck('token')
            ->all();

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    public function getTokensForUser(string $userId): array
    {
        /** @var list<string> $tokens */
        $tokens = FcmDeviceToken::where('user_id', $userId)
            ->pluck('token')
            ->all();

        return array_values(array_unique($tokens));
    }
}
