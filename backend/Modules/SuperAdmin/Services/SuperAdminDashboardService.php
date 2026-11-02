<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Services;

use Modules\SuperAdmin\Models\Package;
use Modules\SuperAdmin\Models\Subscription;
use Modules\Tenancy\Services\TenantManagementService;

final class SuperAdminDashboardService
{
    public function __construct(
        private readonly TenantManagementService $tenantManagementService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $tenantStats = $this->tenantManagementService->getStats();
        $packagesCount = Package::query()->count();
        $activeSubscriptionsCount = Subscription::query()->where('status', 'active')->count();

        $recentTenants = $this->tenantManagementService->listTenants([], 5);
        $failedTenants = $this->tenantManagementService->listTenants(['status' => 'provisioning_failed'], 10);

        return [
            'tenants' => $tenantStats,
            'packages_count' => $packagesCount,
            'active_subscriptions_count' => $activeSubscriptionsCount,
            'recent_tenants' => $recentTenants->items(),
            'failed_tenants' => $failedTenants->items(),
        ];
    }
}
