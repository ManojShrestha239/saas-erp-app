<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use Illuminate\Database\DatabaseManager;

final class TenantPackageService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function getMaxUsers(string|int $tenantId): ?int
    {
        /** @var int|string|null $maxUsers */
        $maxUsers = $this->databaseManager->connection('central')
            ->table('subscriptions')
            ->join('packages', 'subscriptions.package_id', '=', 'packages.id')
            ->where('subscriptions.tenant_id', (string) $tenantId)
            ->where('subscriptions.status', 'active')
            ->value('packages.max_users');

        return $maxUsers !== null ? (int) $maxUsers : null;
    }
}
