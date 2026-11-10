<?php

declare(strict_types=1);

namespace Modules\Reports\Enums;

enum ReportFormat: string
{
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
