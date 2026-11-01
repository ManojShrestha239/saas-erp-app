<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

use Carbon\Carbon;

final readonly class PostJournalEntryData
{
    /**
     * @param  list<JournalEntryLineData>  $lines
     */
    public function __construct(
        public Carbon $entryDate,
        public array $lines,
        public ?string $description = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public string $currency = 'USD',
        public ?string $userId = null,
    ) {}
}
