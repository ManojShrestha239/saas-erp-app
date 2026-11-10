<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\SuperAdmin\DTOs\CreatePackageData;
use Modules\SuperAdmin\DTOs\UpdatePackageData;
use Modules\SuperAdmin\Models\Package;
use Shared\Exceptions\ApiException;
use Shared\Utils\HttpStatus;

final class SuperAdminPackageService
{
    public function __construct(
        private readonly GlobalAuditLogService $auditLogService,
    ) {}

    /**
     * @return Collection<int, Package>
     */
    public function listPackages(): Collection
    {
        return Package::query()
            ->withCount(['subscriptions' => function ($query): void {
                $query->where('status', 'active');
            }])
            ->orderBy('price_cents')
            ->get();
    }

    public function findPackage(int $id): Package
    {
        /** @var Package|null $package */
        $package = Package::query()
            ->withCount(['subscriptions' => function ($query): void {
                $query->where('status', 'active');
            }])
            ->find($id);

        if ($package === null) {
            throw new ApiException(
                message: "Package with ID {$id} not found.",
                status: HttpStatus::NOT_FOUND,
                errorCode: 'PACKAGE_NOT_FOUND',
            );
        }

        return $package;
    }

    public function createPackage(CreatePackageData $data): Package
    {
        /** @var Package $package */
        $package = Package::query()->create([
            'name' => $data->name,
            'slug' => $data->slug,
            'max_users' => $data->maxUsers,
            'max_warehouses' => $data->maxWarehouses,
            'max_invoices_per_month' => $data->maxInvoicesPerMonth,
            'features' => $data->features,
            'price_cents' => $data->priceCents,
        ]);

        $this->auditLogService->log(
            event: 'package.created',
            auditableType: Package::class,
            auditableId: (string) $package->id,
            newValues: $package->toArray(),
        );

        return $package;
    }

    public function updatePackage(int $id, UpdatePackageData $data): Package
    {
        $package = $this->findPackage($id);
        $oldValues = $package->toArray();

        $payload = array_filter([
            'name' => $data->name,
            'slug' => $data->slug,
            'max_users' => $data->maxUsers,
            'max_warehouses' => $data->maxWarehouses,
            'max_invoices_per_month' => $data->maxInvoicesPerMonth,
            'features' => $data->features,
            'price_cents' => $data->priceCents,
        ], fn ($val): bool => $val !== null);

        $package->update($payload);

        $fresh = $package->fresh();
        $this->auditLogService->log(
            event: 'package.updated',
            auditableType: Package::class,
            auditableId: (string) $package->id,
            oldValues: $oldValues,
            newValues: $fresh ? $fresh->toArray() : $package->toArray(),
        );

        return $package;
    }

    public function deletePackage(int $id): void
    {
        $package = $this->findPackage($id);

        $hasActive = $package->subscriptions()->where('status', 'active')->exists();
        if ($hasActive) {
            throw new ApiException(
                message: "Cannot delete package '{$package->name}' because it has active subscriptions.",
                status: HttpStatus::BAD_REQUEST,
                errorCode: 'PACKAGE_IN_USE',
            );
        }

        $oldValues = $package->toArray();
        $package->delete();

        $this->auditLogService->log(
            event: 'package.deleted',
            auditableType: Package::class,
            auditableId: (string) $id,
            oldValues: $oldValues,
        );
    }
}
