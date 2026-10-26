<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Services\TenantPackageService;
use Shared\Exceptions\ApiException;

final class EnforceUserLimitService
{
    public function __construct(
        private readonly TenantPackageService $tenantPackageService,
    ) {}

    public function ensureCanInvite(string|int $tenantId): void
    {
        $maxUsers = $this->tenantPackageService->getMaxUsers($tenantId);

        if ($maxUsers === null) {
            return;
        }

        $activeUserCount = TenantUser::query()
            ->where('status', '!=', 'deactivated')
            ->count();

        if ($activeUserCount >= $maxUsers) {
            throw ApiException::forbidden(
                sprintf('Subscription package user limit reached (%d users maximum).', $maxUsers),
                'PACKAGE_LIMIT_EXCEEDED'
            );
        }
    }
}
