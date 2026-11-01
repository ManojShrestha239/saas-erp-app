<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Accounting\DTOs\JournalEntryLineData;
use Modules\Accounting\DTOs\PostJournalEntryData;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Events\JournalEntryPosted;
use Modules\Accounting\Events\JournalEntryReversed;
use Modules\Accounting\Exceptions\ImbalancedJournalEntryException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class JournalEntryService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $documentNumberingService,
    ) {}

    /**
     * Post a new balanced General Ledger journal entry.
     *
     * @throws ImbalancedJournalEntryException
     * @throws ApiException
     */
    public function post(PostJournalEntryData $data): JournalEntry
    {
        $this->validateLines($data->lines);

        $accountIds = array_values(array_unique(array_map(
            static fn (JournalEntryLineData $line): string => $line->accountId,
            $data->lines,
        )));

        /** @var Collection<int, Account> $accounts */
        $accounts = Account::whereIn('id', $accountIds)->get();

        if ($accounts->count() !== count($accountIds)) {
            $existingIds = $accounts->pluck('id')->all();
            $missingIds = array_values(array_diff($accountIds, $existingIds));

            throw ApiException::unprocessable(
                'One or more accounts referenced in the journal entry do not exist.',
                'ACCOUNT_NOT_FOUND',
                ['missing_account_ids' => $missingIds]
            );
        }

        foreach ($accounts as $account) {
            if (! $account->is_active) {
                throw ApiException::badRequest(
                    "Cannot post to inactive account: {$account->code} - {$account->name}.",
                    'ACCOUNT_INACTIVE'
                );
            }
        }

        $totalDebitCents = array_sum(array_map(
            static fn (JournalEntryLineData $line): int => $line->debitCents,
            $data->lines
        ));

        $totalCreditCents = array_sum(array_map(
            static fn (JournalEntryLineData $line): int => $line->creditCents,
            $data->lines
        ));

        return $this->db->connection('tenant')->transaction(function () use (
            $data,
            $totalDebitCents,
            $totalCreditCents
        ): JournalEntry {
            $entryYear = (int) $data->entryDate->format('Y');

            $entryNumber = $this->documentNumberingService->generateNext(
                type: DocumentType::JournalEntry,
                year: $entryYear,
            );

            /** @var JournalEntry $journalEntry */
            $journalEntry = JournalEntry::create([
                'entry_number' => $entryNumber,
                'entry_date' => $data->entryDate->toDateString(),
                'posting_date' => now(),
                'status' => JournalEntryStatus::Posted,
                'description' => $data->description,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'total_debit_cents' => $totalDebitCents,
                'total_credit_cents' => $totalCreditCents,
                'currency' => $data->currency,
                'created_by_user_id' => $data->userId,
            ]);

            foreach ($data->lines as $index => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $line->accountId,
                    'line_number' => $index + 1,
                    'debit_cents' => $line->debitCents,
                    'credit_cents' => $line->creditCents,
                    'description' => $line->description,
                ]);
            }

            $tenantId = TenantContext::get() !== null ? (string) TenantContext::get()->id : null;
            JournalEntryPosted::dispatch($journalEntry, $tenantId, $data->userId);

            return $journalEntry->load(['lines.account']);
        }, 3);
    }

    /**
     * Reverse an existing posted journal entry by creating an exact inverse entry.
     *
     * @throws ApiException
     */
    public function reverse(
        JournalEntry $entry,
        string $reason,
        ?Carbon $reversalDate = null,
        ?string $userId = null,
    ): JournalEntry {
        if ($entry->isReversed()) {
            throw ApiException::unprocessable(
                "Journal entry {$entry->entry_number} is already reversed.",
                'JOURNAL_ALREADY_REVERSED'
            );
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ApiException::unprocessable(
                'A mandatory reason is required to reverse a journal entry.',
                'REASON_REQUIRED'
            );
        }

        $date = $reversalDate ?? now();

        return $this->db->connection('tenant')->transaction(function () use (
            $entry,
            $trimmedReason,
            $date,
            $userId
        ): JournalEntry {
            /** @var JournalEntry $original */
            $original = JournalEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            if ($original->isReversed()) {
                throw ApiException::unprocessable(
                    "Journal entry {$original->entry_number} is already reversed.",
                    'JOURNAL_ALREADY_REVERSED'
                );
            }

            /** @var Collection<int, JournalEntryLine> $originalLines */
            $originalLines = JournalEntryLine::where('journal_entry_id', $original->id)
                ->orderBy('line_number')
                ->get();

            $entryYear = (int) $date->format('Y');

            $reversalNumber = $this->documentNumberingService->generateNext(
                type: DocumentType::JournalEntry,
                year: $entryYear,
            );

            /** @var JournalEntry $reversalEntry */
            $reversalEntry = JournalEntry::create([
                'entry_number' => $reversalNumber,
                'entry_date' => $date->toDateString(),
                'posting_date' => now(),
                'status' => JournalEntryStatus::Posted,
                'description' => "Reversal of {$original->entry_number}: {$trimmedReason}",
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'total_debit_cents' => $original->total_debit_cents,
                'total_credit_cents' => $original->total_credit_cents,
                'currency' => $original->currency,
                'created_by_user_id' => $userId,
                'reversal_of_id' => $original->id,
                'reversal_reason' => $trimmedReason,
            ]);

            foreach ($originalLines as $index => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversalEntry->id,
                    'account_id' => $line->account_id,
                    'line_number' => $index + 1,
                    // Invert debit and credit
                    'debit_cents' => $line->credit_cents,
                    'credit_cents' => $line->debit_cents,
                    'description' => "Reversal: {$line->description}",
                ]);
            }

            // Update original journal status to reversed
            $original->update([
                'status' => JournalEntryStatus::Reversed,
                'reversed_by_id' => $reversalEntry->id,
                'reversal_reason' => $trimmedReason,
            ]);

            $tenantId = TenantContext::get() !== null ? (string) TenantContext::get()->id : null;
            JournalEntryReversed::dispatch($original, $reversalEntry, $trimmedReason, $tenantId, $userId);

            return $reversalEntry->load(['lines.account', 'reversalOf']);
        }, 3);
    }

    /**
     * Paginated list of journal entries.
     *
     * @param  array{
     *     status?: string|null,
     *     source_type?: string|null,
     *     source_id?: string|null,
     *     account_id?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     search?: string|null,
     *     per_page?: int|null
     * }  $filters
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = JournalEntry::query()
            ->with(['lines.account', 'reversalOf', 'reversedBy'])
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        if (! empty($filters['source_id'])) {
            $query->where('source_id', $filters['source_id']);
        }

        if (! empty($filters['account_id'])) {
            $query->whereHas('lines', function ($q) use ($filters): void {
                $q->where('account_id', $filters['account_id']);
            });
        }

        if (! empty($filters['from_date'])) {
            $query->where('entry_date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('entry_date', '<=', $filters['to_date']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('entry_number', 'ilike', $term)
                    ->orWhere('description', 'ilike', $term);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        /** @var LengthAwarePaginator<int, JournalEntry> */
        return $query->paginate($perPage);
    }

    public function findById(string $id): ?JournalEntry
    {
        return JournalEntry::with(['lines.account', 'reversalOf', 'reversedBy'])->find($id);
    }

    public function findByNumber(string $entryNumber): ?JournalEntry
    {
        return JournalEntry::with(['lines.account', 'reversalOf', 'reversedBy'])
            ->where('entry_number', $entryNumber)
            ->first();
    }

    public function findForSource(string $sourceType, string $sourceId): ?JournalEntry
    {
        return JournalEntry::with(['lines.account', 'reversalOf', 'reversedBy'])
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * @param  list<JournalEntryLineData>  $lines
     *
     * @throws ImbalancedJournalEntryException
     * @throws ApiException
     */
    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new ImbalancedJournalEntryException('A journal entry must contain at least two lines for double-entry bookkeeping.');
        }

        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($lines as $index => $line) {
            $lineIndex = $index + 1;

            if ($line->debitCents < 0 || $line->creditCents < 0) {
                throw ApiException::unprocessable(
                    "Line {$lineIndex} cannot have negative debit or credit amounts.",
                    'NEGATIVE_AMOUNT'
                );
            }

            if ($line->debitCents === 0 && $line->creditCents === 0) {
                throw ApiException::unprocessable(
                    "Line {$lineIndex} must have either a debit or credit amount greater than zero.",
                    'ZERO_AMOUNT'
                );
            }

            if ($line->debitCents > 0 && $line->creditCents > 0) {
                throw ApiException::unprocessable(
                    "Line {$lineIndex} cannot have both debit and credit amounts. Split into separate lines.",
                    'MUTUAL_DEBIT_CREDIT'
                );
            }

            $totalDebits += $line->debitCents;
            $totalCredits += $line->creditCents;
        }

        if ($totalDebits <= 0) {
            throw new ImbalancedJournalEntryException('Journal entry debits must be greater than zero.');
        }

        if ($totalDebits !== $totalCredits) {
            throw new ImbalancedJournalEntryException(
                sprintf(
                    'Journal entry is imbalanced. Total debits (%d cents) must equal total credits (%d cents). Difference: %d cents.',
                    $totalDebits,
                    $totalCredits,
                    abs($totalDebits - $totalCredits)
                ),
                [
                    'total_debit_cents' => $totalDebits,
                    'total_credit_cents' => $totalCredits,
                    'difference_cents' => abs($totalDebits - $totalCredits),
                ]
            );
        }
    }
}
