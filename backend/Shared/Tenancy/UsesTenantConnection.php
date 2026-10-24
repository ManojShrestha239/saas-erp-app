<?php

declare(strict_types=1);

namespace Shared\Tenancy;

trait UsesTenantConnection
{
    public function initializeUsesTenantConnection(): void
    {
        $this->setConnection('tenant');
    }

    public function getConnectionName(): ?string
    {
        return 'tenant';
    }
}
