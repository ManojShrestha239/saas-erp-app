<?php

declare(strict_types=1);

namespace Modules\Reports\Enums;

enum ReportExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Queued, self::Processing => false,
        };
    }
}
