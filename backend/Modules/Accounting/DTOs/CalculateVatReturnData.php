<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

use Illuminate\Support\Carbon;

final readonly class CalculateVatReturnData
{
    public function __construct(
        public Carbon $fromDate,
        public Carbon $toDate,
        public ?string $periodId = null,
    ) {}
}
