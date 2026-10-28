<?php

declare(strict_types=1);

namespace Shared\DTOs;

final readonly class GeneratedDocumentNumber
{
    public function __construct(
        public string $formattedNumber,
        public string $prefix,
        public int $year,
        public int $sequenceNumber,
    ) {}
}
