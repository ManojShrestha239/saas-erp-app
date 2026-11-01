<?php

declare(strict_types=1);

namespace Modules\Accounting\Exceptions;

use Shared\Exceptions\ApiException;
use Shared\Utils\HttpStatus;

final class ImbalancedJournalEntryException extends ApiException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message = 'Journal entry must be balanced: total debits must equal total credits.',
        array $errors = [],
    ) {
        parent::__construct(
            message: $message,
            status: HttpStatus::UNPROCESSABLE_ENTITY,
            errorCode: 'IMBALANCED_JOURNAL_ENTRY',
            errors: $errors,
        );
    }
}
