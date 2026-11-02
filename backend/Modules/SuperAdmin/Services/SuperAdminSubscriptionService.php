<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Modules\SuperAdmin\DTOs\AssignSubscriptionData;
use Modules\SuperAdmin\Models\Subscription;
use Shared\Exceptions\ApiException;
use Shared\Utils\HttpStatus;

final class SuperAdminSubscriptionService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SuperAdminPackageService $packageService,
    ) {}

    /**
     * @return Collection<int, Subscription>
     */
    public function listSubscriptions(?string $tenantId = null): Collection
    {
        $query = Subscription::query()->with('package')->latest();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get();
    }

    public function findSubscription(int $id): Subscription
    {
        /** @var Subscription|null $sub */
        $sub = Subscription::query()->with('package')->find($id);

        if ($sub === null) {
            throw new ApiException(
                message: "Subscription with ID {$id} not found.",
                status: HttpStatus::NOT_FOUND,
                errorCode: 'SUBSCRIPTION_NOT_FOUND',
            );
        }

        return $sub;
    }

    public function assignSubscription(AssignSubscriptionData $data): Subscription
    {
        // Validate package exists
        $this->packageService->findPackage($data->packageId);

        /** @var Subscription $subscription */
        $subscription = $this->databaseManager->connection('central')->transaction(function () use ($data): Subscription {
            // Cancel any current active subscription for tenant
            Subscription::query()
                ->where('tenant_id', $data->tenantId)
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                    'ends_at' => now(),
                    'updated_at' => now(),
                ]);

            /** @var Subscription $newSub */
            $newSub = Subscription::query()->create([
                'tenant_id' => $data->tenantId,
                'package_id' => $data->packageId,
                'status' => $data->status,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
            ]);

            return $newSub;
        });

        return $subscription->fresh(['package']) ?? $subscription;
    }

    public function cancelSubscription(int $id): Subscription
    {
        $sub = $this->findSubscription($id);

        if ($sub->status === 'cancelled') {
            return $sub;
        }

        $sub->update([
            'status' => 'cancelled',
            'ends_at' => now(),
        ]);

        return $sub->fresh(['package']) ?? $sub;
    }
}
