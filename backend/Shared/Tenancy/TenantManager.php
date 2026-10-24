<?php

declare(strict_types=1);

namespace Shared\Tenancy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

final class TenantManager
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly ConfigRepository $config,
    ) {}

    public function switchTo(TenantContext $context): void
    {
        $this->config->set('database.connections.tenant.database', $context->databaseName);

        $this->databaseManager->purge('tenant');

        $this->databaseManager->reconnect('tenant');

        TenantContext::set($context);
    }

    public function switch(TenantContext $context): void
    {
        $this->switchTo($context);
    }

    public function getContext(): ?TenantContext
    {
        return TenantContext::get();
    }

    public function hasContext(): bool
    {
        return TenantContext::get() !== null;
    }

    public function connection(): ConnectionInterface
    {
        return $this->databaseManager->connection('tenant');
    }

    public function reset(): void
    {
        $this->config->set('database.connections.tenant.database', null);

        $this->databaseManager->purge('tenant');

        TenantContext::set(null);
    }
}
