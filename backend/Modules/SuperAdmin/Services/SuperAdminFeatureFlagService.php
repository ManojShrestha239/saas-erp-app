<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\SuperAdmin\Models\FeatureFlag;
use Shared\Exceptions\ApiException;
use Shared\Utils\HttpStatus;

final class SuperAdminFeatureFlagService
{
    public function __construct(
        private readonly GlobalAuditLogService $auditLogService,
    ) {}

    /**
     * @return Collection<int, FeatureFlag>
     */
    public function listFlags(): Collection
    {
        return FeatureFlag::query()->orderBy('name')->get();
    }

    public function findFlag(string $key): FeatureFlag
    {
        /** @var FeatureFlag|null $flag */
        $flag = FeatureFlag::query()->where('key', $key)->first();

        if ($flag === null) {
            throw new ApiException(
                message: "Feature flag '{$key}' not found.",
                status: HttpStatus::NOT_FOUND,
                errorCode: 'FEATURE_FLAG_NOT_FOUND',
            );
        }

        return $flag;
    }

    public function toggleFlag(string $key, ?bool $state = null): FeatureFlag
    {
        $flag = $this->findFlag($key);
        $oldValues = ['is_enabled' => $flag->is_enabled];

        $newState = $state !== null ? $state : ! $flag->is_enabled;
        $flag->update(['is_enabled' => $newState]);

        $this->auditLogService->log(
            event: 'feature_flag.toggled',
            auditableType: FeatureFlag::class,
            auditableId: $flag->key,
            oldValues: $oldValues,
            newValues: ['is_enabled' => $newState],
        );

        return $flag;
    }

    public function isEnabled(string $key): bool
    {
        /** @var FeatureFlag|null $flag */
        $flag = FeatureFlag::query()->where('key', $key)->first();

        return $flag !== null && $flag->is_enabled;
    }
}
