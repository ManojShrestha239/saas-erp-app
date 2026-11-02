<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Tenant;
use Shared\Exceptions\ApiException;
use Shared\Http\Middleware\IdentifyTenant;
use Shared\Utils\HttpStatus;

final class TenantManagementService
{
    /**
     * @param  array{search?: ?string, status?: ?string}  $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function listTenants(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = Tenant::query()->with('domains')->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%")
                    ->orWhereHas('domains', function ($dq) use ($search): void {
                        $dq->where('host', 'ilike', "%{$search}%");
                    });
            });
        }

        /** @var LengthAwarePaginator<int, Tenant> $paginator */
        $paginator = $query->paginate($perPage);

        return $paginator;
    }

    public function findTenant(string $id): Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->with('domains')->find($id);

        if ($tenant === null) {
            throw new ApiException(
                message: "Tenant with ID '{$id}' not found.",
                status: HttpStatus::NOT_FOUND,
                errorCode: 'TENANT_NOT_FOUND',
            );
        }

        return $tenant;
    }

    public function suspendTenant(string $id, ?string $reason = null): Tenant
    {
        $tenant = $this->findTenant($id);

        if ($tenant->status === 'suspended') {
            return $tenant;
        }

        $tenant->update([
            'status' => 'suspended',
        ]);

        $this->invalidateTenantCaches($tenant);

        return $tenant;
    }

    public function reactivateTenant(string $id): Tenant
    {
        $tenant = $this->findTenant($id);

        if ($tenant->status === 'active') {
            return $tenant;
        }

        $tenant->update([
            'status' => 'active',
        ]);

        $this->invalidateTenantCaches($tenant);

        return $tenant;
    }

    public function retryProvisioning(string $id): Tenant
    {
        $tenant = $this->findTenant($id);

        if ($tenant->status !== 'provisioning_failed') {
            throw new ApiException(
                message: "Only tenants in 'provisioning_failed' status can be retried. Current status: {$tenant->status}.",
                status: HttpStatus::BAD_REQUEST,
                errorCode: 'INVALID_TENANT_STATUS',
            );
        }

        $tenant->update([
            'status' => 'provisioning',
            'provisioning_error' => null,
        ]);

        $ownerEmail = $tenant->owner_email ?: "owner@{$tenant->slug}.com";
        $ownerName = $tenant->name;

        ProvisionTenantDatabase::dispatch(
            tenantId: $tenant->id,
            ownerEmail: $ownerEmail,
            ownerName: $ownerName,
        );

        return $tenant;
    }

    /**
     * @return array{total: int, active: int, suspended: int, provisioning: int, provisioning_failed: int}
     */
    public function getStats(): array
    {
        /** @var object{total: int|string|null, active: int|string|null, suspended: int|string|null, provisioning: int|string|null, provisioning_failed: int|string|null}|null $counts */
        $counts = Tenant::query()
            ->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended,
                COUNT(CASE WHEN status = 'provisioning' THEN 1 END) as provisioning,
                COUNT(CASE WHEN status = 'provisioning_failed' THEN 1 END) as provisioning_failed
            ")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'active' => (int) ($counts->active ?? 0),
            'suspended' => (int) ($counts->suspended ?? 0),
            'provisioning' => (int) ($counts->provisioning ?? 0),
            'provisioning_failed' => (int) ($counts->provisioning_failed ?? 0),
        ];
    }

    private function invalidateTenantCaches(Tenant $tenant): void
    {
        IdentifyTenant::invalidateCache($tenant->slug);
        foreach ($tenant->domains as $domain) {
            IdentifyTenant::invalidateCache($domain->host);
        }
    }
}
