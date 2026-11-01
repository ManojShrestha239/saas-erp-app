<?php

declare(strict_types=1);

namespace Modules\Accounting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Accounting\Models\JournalEntry;

final class JournalEntryPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly JournalEntry $journalEntry,
        public readonly ?string $tenantId = null,
        public readonly ?string $userId = null,
    ) {}
}
