<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Illuminate\Database\DatabaseManager;

final class TenantPackageService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @return object{package_id: int, package_name: string, package_slug: string, max_users: int, max_warehouses: int, max_invoices_per_month: int, features: mixed, subscription_id: int, subscription_status: string, starts_at: string, ends_at: string|null}|null
     */
    public function getActiveSubscription(string|int $tenantId): ?object
    {
        /** @var object{package_id: int, package_name: string, package_slug: string, max_users: int, max_warehouses: int, max_invoices_per_month: int, features: mixed, subscription_id: int, subscription_status: string, starts_at: string, ends_at: string|null}|null $sub */
        $sub = $this->databaseManager->connection('central')
            ->table('subscriptions')
            ->join('packages', 'subscriptions.package_id', '=', 'packages.id')
            ->where('subscriptions.tenant_id', (string) $tenantId)
            ->where('subscriptions.status', 'active')
            ->where(function ($query): void {
                $query->whereNull('subscriptions.ends_at')
                    ->orWhere('subscriptions.ends_at', '>', now());
            })
            ->select([
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.slug as package_slug',
                'packages.max_users',
                'packages.max_warehouses',
                'packages.max_invoices_per_month',
                'packages.features',
                'subscriptions.id as subscription_id',
                'subscriptions.status as subscription_status',
                'subscriptions.starts_at',
                'subscriptions.ends_at',
            ])
            ->first();

        return $sub;
    }

    public function getMaxUsers(string|int $tenantId): ?int
    {
        $sub = $this->getActiveSubscription($tenantId);

        return $sub !== null ? (int) $sub->max_users : null;
    }

    public function getMaxWarehouses(string|int $tenantId): ?int
    {
        $sub = $this->getActiveSubscription($tenantId);

        return $sub !== null ? (int) $sub->max_warehouses : null;
    }

    public function getMaxInvoicesPerMonth(string|int $tenantId): ?int
    {
        $sub = $this->getActiveSubscription($tenantId);

        return $sub !== null ? (int) $sub->max_invoices_per_month : null;
    }

    public function isGlobalFeatureEnabled(string $featureKey): ?bool
    {
        /** @var object{id: int, key: string, name: string, description: ?string, is_enabled: bool}|null $flag */
        $flag = $this->databaseManager->connection('central')
            ->table('feature_flags')
            ->where('key', $featureKey)
            ->first();

        return $flag !== null ? (bool) $flag->is_enabled : null;
    }

    public function hasPackageFeature(string|int $tenantId, string $featureKey): ?bool
    {
        $sub = $this->getActiveSubscription($tenantId);

        if ($sub === null) {
            return null;
        }

        $features = $this->parseFeatures($sub->features);

        if (array_key_exists($featureKey, $features)) {
            return (bool) $features[$featureKey];
        }

        return null;
    }

    public function hasFeature(string|int $tenantId, string $featureKey): bool
    {
        $globalState = $this->isGlobalFeatureEnabled($featureKey);

        // Global kill-switch: if explicitly defined globally and disabled, deny
        if ($globalState === false) {
            return false;
        }

        $packageState = $this->hasPackageFeature($tenantId, $featureKey);

        // Package restriction: if explicitly defined in package tier and disabled, deny
        if ($packageState === false) {
            return false;
        }

        // Grant if enabled either by package tier or active global flag
        if ($packageState === true || $globalState === true) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *     package_id: int|null,
     *     package_name: string|null,
     *     package_slug: string|null,
     *     max_users: int|null,
     *     max_warehouses: int|null,
     *     max_invoices_per_month: int|null,
     *     features: array<string, mixed>,
     *     subscription_status: string|null,
     *     starts_at: string|null,
     *     ends_at: string|null
     * }
     */
    public function getPackageLimits(string|int $tenantId): array
    {
        $sub = $this->getActiveSubscription($tenantId);

        if ($sub === null) {
            return [
                'package_id' => null,
                'package_name' => null,
                'package_slug' => null,
                'max_users' => null,
                'max_warehouses' => null,
                'max_invoices_per_month' => null,
                'features' => [],
                'subscription_status' => null,
                'starts_at' => null,
                'ends_at' => null,
            ];
        }

        return [
            'package_id' => (int) $sub->package_id,
            'package_name' => (string) $sub->package_name,
            'package_slug' => (string) $sub->package_slug,
            'max_users' => (int) $sub->max_users,
            'max_warehouses' => (int) $sub->max_warehouses,
            'max_invoices_per_month' => (int) $sub->max_invoices_per_month,
            'features' => $this->parseFeatures($sub->features),
            'subscription_status' => (string) $sub->subscription_status,
            'starts_at' => (string) $sub->starts_at,
            'ends_at' => $sub->ends_at !== null ? (string) $sub->ends_at : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFeatures(mixed $features): array
    {
        if (is_array($features)) {
            /** @var array<string, mixed> $features */
            return $features;
        }

        if (is_string($features)) {
            /** @var mixed $decoded */
            $decoded = json_decode($features, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }
}
