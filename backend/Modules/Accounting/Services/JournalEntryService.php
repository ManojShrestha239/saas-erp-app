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
        private readonly FiscalPeriodService $fiscalPeriodService,
    ) {}

    /**
     * Post a new balanced General Ledger journal entry, or create a draft.
     *
     * @throws ImbalancedJournalEntryException
     * @throws ApiException
     */
    public function post(PostJournalEntryData $data): JournalEntry
    {
        if ($data->status === JournalEntryStatus::Draft) {
            return $this->createDraft($data);
        }

        $this->fiscalPeriodService->assertPeriodOpen($data->entryDate);
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
     * Create an unposted draft journal entry without consuming a sequential document number.
     */
    public function createDraft(PostJournalEntryData $data): JournalEntry
    {
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
            /** @var JournalEntry $journalEntry */
            $journalEntry = JournalEntry::create([
                'entry_number' => null,
                'entry_date' => $data->entryDate->toDateString(),
                'posting_date' => null,
                'status' => JournalEntryStatus::Draft,
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

            return $journalEntry->load(['lines.account']);
        }, 3);
    }

    /**
     * Update an editable draft or rejected manual journal entry.
     */
    public function updateDraft(string $id, PostJournalEntryData $data): JournalEntry
    {
        $entry = $this->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        if (! $entry->isDraft() && ! $entry->isRejected()) {
            throw ApiException::badRequest(
                'Only draft or rejected journal entries can be modified.',
                'IMMUTABLE_JOURNAL_ENTRY'
            );
        }

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

        $totalDebitCents = array_sum(array_map(
            static fn (JournalEntryLineData $line): int => $line->debitCents,
            $data->lines
        ));

        $totalCreditCents = array_sum(array_map(
            static fn (JournalEntryLineData $line): int => $line->creditCents,
            $data->lines
        ));

        return $this->db->connection('tenant')->transaction(function () use (
            $entry,
            $data,
            $totalDebitCents,
            $totalCreditCents
        ): JournalEntry {
            $entry->lines()->delete();

            $entry->update([
                'entry_date' => $data->entryDate->toDateString(),
                'description' => $data->description,
                'currency' => $data->currency,
                'total_debit_cents' => $totalDebitCents,
                'total_credit_cents' => $totalCreditCents,
                'status' => JournalEntryStatus::Draft,
                'rejection_reason' => null,
            ]);

            foreach ($data->lines as $index => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line->accountId,
                    'line_number' => $index + 1,
                    'debit_cents' => $line->debitCents,
                    'credit_cents' => $line->creditCents,
                    'description' => $line->description,
                ]);
            }

            $entry->refresh()->load(['lines.account']);

            return $entry;
        }, 3);
    }

    /**
     * Submit a draft or rejected journal entry for approval.
     */
    public function submitForApproval(string $id, ?string $userId = null): JournalEntry
    {
        $entry = $this->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        if (! $entry->isDraft() && ! $entry->isRejected()) {
            throw ApiException::badRequest(
                'Only draft or rejected journal entries can be submitted for approval.',
                'INVALID_STATUS'
            );
        }

        if ($entry->total_debit_cents !== $entry->total_credit_cents || $entry->total_debit_cents <= 0) {
            throw new ImbalancedJournalEntryException(
                'Journal entry lines must balance before submitting for approval.'
            );
        }

        $lines = $entry->lines;
        if ($lines->count() < 2) {
            throw new ImbalancedJournalEntryException(
                'A journal entry must contain at least two lines for double-entry bookkeeping.'
            );
        }

        $entry->update([
            'status' => JournalEntryStatus::PendingApproval,
            'submitted_by_user_id' => $userId,
            'submitted_at' => now(),
        ]);

        $entry->refresh()->load(['lines.account']);

        return $entry;
    }

    /**
     * Approve and post a pending or draft journal entry.
     */
    public function approve(string $id, ?string $userId = null): JournalEntry
    {
        $entry = $this->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        if (! $entry->isPendingApproval() && ! $entry->isDraft()) {
            throw ApiException::badRequest(
                'Only pending or draft journal entries can be approved and posted.',
                'INVALID_STATUS'
            );
        }

        if ($entry->total_debit_cents !== $entry->total_credit_cents || $entry->total_debit_cents <= 0) {
            throw new ImbalancedJournalEntryException(
                'Journal entry lines must balance before posting.'
            );
        }

        $this->fiscalPeriodService->assertPeriodOpen($entry->entry_date);

        // Verify all accounts active
        foreach ($entry->lines as $line) {
            if (! $line->account->is_active) {
                throw ApiException::badRequest(
                    "Cannot post to inactive account: {$line->account->code} - {$line->account->name}.",
                    'ACCOUNT_INACTIVE'
                );
            }
        }

        return $this->db->connection('tenant')->transaction(function () use ($entry, $userId): JournalEntry {
            $entryYear = (int) $entry->entry_date->format('Y');

            $entryNumber = $this->documentNumberingService->generateNext(
                type: DocumentType::JournalEntry,
                year: $entryYear,
            );

            $entry->update([
                'entry_number' => $entryNumber,
                'status' => JournalEntryStatus::Posted,
                'posting_date' => now(),
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);

            $tenantId = TenantContext::get() !== null ? (string) TenantContext::get()->id : null;
            JournalEntryPosted::dispatch($entry, $tenantId, $userId);

            $entry->refresh()->load(['lines.account']);

            return $entry;
        }, 3);
    }

    /**
     * Reject a pending journal entry with a mandatory reason.
     */
    public function reject(string $id, string $reason, ?string $userId = null): JournalEntry
    {
        $entry = $this->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        if (! $entry->isPendingApproval()) {
            throw ApiException::badRequest(
                'Only journal entries pending approval can be rejected.',
                'INVALID_STATUS'
            );
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ApiException::unprocessable(
                'A mandatory reason is required to reject a journal entry.',
                'REASON_REQUIRED'
            );
        }

        $entry->update([
            'status' => JournalEntryStatus::Rejected,
            'rejection_reason' => $trimmedReason,
            'rejected_by_user_id' => $userId,
            'rejected_at' => now(),
        ]);

        $entry->refresh()->load(['lines.account']);

        return $entry;
    }

    /**
     * Delete an unposted draft or rejected journal entry.
     */
    public function deleteDraft(string $id): void
    {
        $entry = $this->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        if (! $entry->isDraft() && ! $entry->isRejected()) {
            throw ApiException::badRequest(
                'Only draft or rejected journal entries can be deleted.',
                'IMMUTABLE_JOURNAL_ENTRY'
            );
        }

        $this->db->connection('tenant')->transaction(function () use ($entry): void {
            $entry->lines()->delete();
            $entry->delete();
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
        $this->fiscalPeriodService->assertPeriodOpen($date);

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
                'total_debit_cents' => $original->total_credit_cents,
                'total_credit_cents' => $original->total_debit_cents,
                'currency' => $original->currency,
                'created_by_user_id' => $userId,
                'reversal_of_id' => $original->id,
                'reversal_reason' => $trimmedReason,
            ]);

            $originalLines = $original->lines()->orderBy('line_number')->get();

            foreach ($originalLines as $index => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversalEntry->id,
                    'account_id' => $line->account_id,
                    'line_number' => $index + 1,
                    'debit_cents' => $line->credit_cents,
                    'credit_cents' => $line->debit_cents,
                    'description' => "Reversal: {$line->description}",
                ]);
            }

            $original->update([
                'status' => JournalEntryStatus::Reversed,
                'reversed_by_id' => $reversalEntry->id,
                'reversal_reason' => $trimmedReason,
            ]);

            $tenantId = TenantContext::get() !== null ? (string) TenantContext::get()->id : null;
            JournalEntryReversed::dispatch($original, $reversalEntry, $trimmedReason, $tenantId, $userId);

            return $reversalEntry->load(['lines.account']);
        }, 3);
    }

    /**
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
                $q->where('entry_number', 'like', $term)
                    ->orWhere('description', 'like', $term);
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

    public function findBySource(string $sourceType, string $sourceId): ?JournalEntry
    {
        return JournalEntry::with(['lines.account', 'reversalOf', 'reversedBy'])
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    public function findForSource(string $sourceType, string $sourceId): ?JournalEntry
    {
        return $this->findBySource($sourceType, $sourceId);
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
