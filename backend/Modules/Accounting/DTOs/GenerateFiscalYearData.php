<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

use Carbon\CarbonInterface;

final readonly class GenerateFiscalYearData
{
    public function __construct(
        public int $fiscalYear,
        public ?CarbonInterface $startDate = null,
    ) {}
}
