<?php

declare(strict_types=1);

namespace Modules\Accounting\Exceptions;

use Shared\Exceptions\ApiException;
use Shared\Utils\HttpStatus;

final class ImmutableJournalEntryException extends ApiException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message = 'Posted journal entries are immutable and cannot be modified or deleted. Use a reversal instead.',
        array $errors = [],
    ) {
        parent::__construct(
            message: $message,
            status: HttpStatus::UNPROCESSABLE_ENTITY,
            errorCode: 'JOURNAL_ENTRY_IMMUTABLE',
            errors: $errors,
        );
    }
}
