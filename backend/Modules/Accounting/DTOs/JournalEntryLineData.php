<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

final readonly class JournalEntryLineData
{
    public function __construct(
        public string $accountId,
        public int $debitCents = 0,
        public int $creditCents = 0,
        public ?string $description = null,
    ) {}

    /**
     * @param  array{
     *     account_id: string,
     *     debit_cents?: int|null,
     *     credit_cents?: int|null,
     *     description?: string|null
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'],
            debitCents: isset($data['debit_cents']) ? (int) $data['debit_cents'] : 0,
            creditCents: isset($data['credit_cents']) ? (int) $data['credit_cents'] : 0,
            description: $data['description'] ?? null,
        );
    }
}
