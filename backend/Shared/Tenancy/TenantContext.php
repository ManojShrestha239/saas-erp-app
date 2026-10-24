<?php

declare(strict_types=1);

namespace Shared\Tenancy;

final readonly class TenantContext
{
    public function __construct(
        public string|int $id,
        public string $slug,
        public string $databaseName,
    ) {}

    public static function get(): ?self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }

        return null;
    }

    public static function set(?self $context): void
    {
        if ($context === null) {
            app()->forgetInstance(self::class);
        } else {
            app()->instance(self::class, $context);
        }
    }
}
