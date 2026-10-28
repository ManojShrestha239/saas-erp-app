<?php

declare(strict_types=1);

namespace Shared\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Shared\DTOs\GeneratedDocumentNumber;
use Shared\Enums\DocumentType;
use Shared\Models\DocumentSequence;

final class DocumentNumberingService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Atomically generates and increments the next document number for the given type and year.
     * Operates within a tenant database transaction with pessimistic row locking (lockForUpdate).
     */
    public function generate(
        DocumentType|string $type,
        ?int $year = null,
        ?string $customPrefix = null,
        ?int $customPadding = null,
    ): GeneratedDocumentNumber {
        $resolvedYear = $year ?? (int) now()->format('Y');
        $typeKey = $type instanceof DocumentType ? $type->value : $type;
        $defaultPrefix = $type instanceof DocumentType ? $type->defaultPrefix() : strtoupper($typeKey);

        return $this->db->connection('tenant')->transaction(function () use ($typeKey, $defaultPrefix, $customPrefix, $resolvedYear, $customPadding): GeneratedDocumentNumber {
            /** @var DocumentSequence|null $sequence */
            $sequence = DocumentSequence::where('type', $typeKey)
                ->where('year', $resolvedYear)
                ->lockForUpdate()
                ->first();

            $prefix = $customPrefix ?? ($sequence !== null ? $sequence->prefix : $defaultPrefix);
            $padding = $customPadding ?? ($sequence !== null ? $sequence->padding : 6);

            if ($sequence === null) {
                try {
                    DocumentSequence::insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'type' => $typeKey,
                        'prefix' => $prefix,
                        'year' => $resolvedYear,
                        'current_number' => 0,
                        'padding' => $padding,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable) {
                    // Handled if concurrent insert occurred in another transaction
                }

                /** @var DocumentSequence $sequence */
                $sequence = DocumentSequence::where('type', $typeKey)
                    ->where('year', $resolvedYear)
                    ->lockForUpdate()
                    ->firstOrFail();

                $prefix = $customPrefix ?? $sequence->prefix;
                $padding = $customPadding ?? $sequence->padding;
            }

            $nextNumber = $sequence->current_number + 1;
            $sequence->update([
                'current_number' => $nextNumber,
                'prefix' => $prefix,
                'padding' => $padding,
            ]);

            $formatted = $this->formatNumber($prefix, $sequence->year, $nextNumber, $padding);

            return new GeneratedDocumentNumber(
                formattedNumber: $formatted,
                prefix: $prefix,
                year: $sequence->year,
                sequenceNumber: $nextNumber,
            );
        }, 3);
    }

    /**
     * Helper to atomically generate and return the formatted document number string.
     */
    public function generateNext(
        DocumentType|string $type,
        ?int $year = null,
        ?string $customPrefix = null,
        ?int $customPadding = null,
    ): string {
        return $this->generate(
            type: $type,
            year: $year,
            customPrefix: $customPrefix,
            customPadding: $customPadding,
        )->formattedNumber;
    }

    /**
     * Non-locking preview of what the next document number will be (e.g. for draft display).
     */
    public function peek(
        DocumentType|string $type,
        ?int $year = null,
        ?string $customPrefix = null,
        ?int $customPadding = null,
    ): GeneratedDocumentNumber {
        $resolvedYear = $year ?? (int) now()->format('Y');
        $typeKey = $type instanceof DocumentType ? $type->value : $type;
        $defaultPrefix = $type instanceof DocumentType ? $type->defaultPrefix() : strtoupper($typeKey);

        /** @var DocumentSequence|null $sequence */
        $sequence = DocumentSequence::where('type', $typeKey)
            ->where('year', $resolvedYear)
            ->first();

        $prefix = $customPrefix ?? ($sequence !== null ? $sequence->prefix : $defaultPrefix);
        $padding = $customPadding ?? ($sequence !== null ? $sequence->padding : 6);
        $nextNumber = ($sequence !== null ? $sequence->current_number : 0) + 1;

        $formatted = $this->formatNumber($prefix, $resolvedYear, $nextNumber, $padding);

        return new GeneratedDocumentNumber(
            formattedNumber: $formatted,
            prefix: $prefix,
            year: $resolvedYear,
            sequenceNumber: $nextNumber,
        );
    }

    /**
     * Helper to preview the next formatted document number string.
     */
    public function peekNext(
        DocumentType|string $type,
        ?int $year = null,
        ?string $customPrefix = null,
        ?int $customPadding = null,
    ): string {
        return $this->peek(
            type: $type,
            year: $year,
            customPrefix: $customPrefix,
            customPadding: $customPadding,
        )->formattedNumber;
    }

    /**
     * Get the current highest consumed sequence integer for the given type and year.
     */
    public function getCurrentNumber(DocumentType|string $type, ?int $year = null): int
    {
        $resolvedYear = $year ?? (int) now()->format('Y');
        $typeKey = $type instanceof DocumentType ? $type->value : $type;

        /** @var DocumentSequence|null $sequence */
        $sequence = DocumentSequence::where('type', $typeKey)
            ->where('year', $resolvedYear)
            ->first();

        return $sequence !== null ? $sequence->current_number : 0;
    }

    /**
     * Set the next starting document number for the sequence.
     * Stores ($nextNumber - 1) as current_number so the subsequent generate call returns $nextNumber.
     */
    public function setNextNumber(
        DocumentType|string $type,
        int $nextNumber,
        ?int $year = null,
        ?string $customPrefix = null,
        ?int $customPadding = null,
    ): void {
        if ($nextNumber < 1) {
            throw new InvalidArgumentException('Next number must be an integer greater than or equal to 1.');
        }

        $resolvedYear = $year ?? (int) now()->format('Y');
        $typeKey = $type instanceof DocumentType ? $type->value : $type;
        $defaultPrefix = $type instanceof DocumentType ? $type->defaultPrefix() : strtoupper($typeKey);

        $this->db->connection('tenant')->transaction(function () use ($typeKey, $defaultPrefix, $customPrefix, $resolvedYear, $nextNumber, $customPadding): void {
            /** @var DocumentSequence|null $sequence */
            $sequence = DocumentSequence::where('type', $typeKey)
                ->where('year', $resolvedYear)
                ->lockForUpdate()
                ->first();

            $prefix = $customPrefix ?? ($sequence !== null ? $sequence->prefix : $defaultPrefix);
            $padding = $customPadding ?? ($sequence !== null ? $sequence->padding : 6);
            $currentNumberToStore = $nextNumber - 1;

            if ($sequence === null) {
                DocumentSequence::create([
                    'id' => (string) Str::ulid(),
                    'type' => $typeKey,
                    'prefix' => $prefix,
                    'year' => $resolvedYear,
                    'current_number' => $currentNumberToStore,
                    'padding' => $padding,
                ]);
            } else {
                $sequence->update([
                    'current_number' => $currentNumberToStore,
                    'prefix' => $prefix,
                    'padding' => $padding,
                ]);
            }
        }, 3);
    }

    /**
     * Format the document number string.
     * If year is 0, format is {PREFIX}-{000000}.
     * If year > 0, format is {PREFIX}-{YYYY}-{000000}.
     */
    public function formatNumber(string $prefix, int $year, int $number, int $padding): string
    {
        if ($year <= 0) {
            return sprintf('%s-%0*d', $prefix, $padding, $number);
        }

        return sprintf('%s-%04d-%0*d', $prefix, $year, $padding, $number);
    }
}
