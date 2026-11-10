<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Modules\Auth\Services\EnforceUserLimitService;
use Modules\Inventory\Services\WarehouseService;
use Modules\Sales\Services\SalesInvoiceService;
use Shared\Exceptions\ApiException;

final class EnforcePackageLimitService
{
    public function __construct(
        private readonly TenantPackageService $tenantPackageService,
        private readonly WarehouseService $warehouseService,
        private readonly SalesInvoiceService $salesInvoiceService,
        private readonly EnforceUserLimitService $enforceUserLimitService,
    ) {}

    public function ensureCanInviteUser(string|int $tenantId): void
    {
        $this->enforceUserLimitService->ensureCanInvite($tenantId);
    }

    public function ensureCanCreateWarehouse(string|int $tenantId): void
    {
        $maxWarehouses = $this->tenantPackageService->getMaxWarehouses($tenantId);

        if ($maxWarehouses === null || $maxWarehouses <= 0) {
            return;
        }

        $activeCount = $this->warehouseService->count();

        if ($activeCount >= $maxWarehouses) {
            throw ApiException::forbidden(
                sprintf('Subscription package warehouse limit reached (%d warehouses maximum).', $maxWarehouses),
                'PACKAGE_LIMIT_EXCEEDED'
            );
        }
    }

    public function ensureCanCreateInvoice(string|int $tenantId): void
    {
        $maxInvoices = $this->tenantPackageService->getMaxInvoicesPerMonth($tenantId);

        if ($maxInvoices === null || $maxInvoices <= 0) {
            return;
        }

        $monthCount = $this->salesInvoiceService->countCurrentMonthInvoices();

        if ($monthCount >= $maxInvoices) {
            throw ApiException::forbidden(
                sprintf('Subscription package monthly invoice limit reached (%d invoices/month maximum).', $maxInvoices),
                'PACKAGE_LIMIT_EXCEEDED'
            );
        }
    }

    public function ensureWithinLimit(string|int $tenantId, string $resource): void
    {
        match (strtolower($resource)) {
            'users', 'user' => $this->ensureCanInviteUser($tenantId),
            'warehouses', 'warehouse' => $this->ensureCanCreateWarehouse($tenantId),
            'invoices', 'invoice', 'sales_invoices', 'sales-invoices' => $this->ensureCanCreateInvoice($tenantId),
            default => null,
        };
    }
}
