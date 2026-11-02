<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

use Carbon\CarbonInterface;
use Modules\Accounting\Enums\FiscalPeriodStatus;

final readonly class CreateFiscalPeriodData
{
    public function __construct(
        public string $name,
        public int $fiscalYear,
        public int $periodNumber,
        public CarbonInterface $startDate,
        public CarbonInterface $endDate,
        public FiscalPeriodStatus $status = FiscalPeriodStatus::Open,
    ) {}
}
