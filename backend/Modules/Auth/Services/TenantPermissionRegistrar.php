<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Shared\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

final class TenantPermissionRegistrar extends PermissionRegistrar
{
    public function initializeCache(): void
    {
        parent::initializeCache();

        $tenant = TenantContext::get();
        $tenantId = $tenant !== null ? $tenant->id : 'central';
        $this->cacheKey = (string) config('permission.cache.key').':'.$tenantId;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, Model>
     */
    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        $tenant = TenantContext::get();
        $expectedTenantId = $tenant !== null ? $tenant->id : 'central';
        $expectedKey = (string) config('permission.cache.key').':'.$expectedTenantId;

        if ($this->cacheKey !== $expectedKey) {
            $this->initializeCache();
        }

        return parent::getPermissions($params, $onlyOne);
    }

    public function forgetCachedPermissions(): bool
    {
        $tenant = TenantContext::get();
        $expectedTenantId = $tenant !== null ? $tenant->id : 'central';
        $this->cacheKey = (string) config('permission.cache.key').':'.$expectedTenantId;

        return parent::forgetCachedPermissions();
    }
}
