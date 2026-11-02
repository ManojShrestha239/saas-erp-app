<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\DTOs;

use Illuminate\Support\Carbon;

final readonly class AssignSubscriptionData
{
    public function __construct(
        public string $tenantId,
        public int $packageId,
        public Carbon $startsAt,
        public ?Carbon $endsAt = null,
        public string $status = 'active',
    ) {}
}
