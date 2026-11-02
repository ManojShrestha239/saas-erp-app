<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\DTOs\JournalEntryLineData;
use Modules\Accounting\DTOs\PostJournalEntryData;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Http\Requests\PostManualJournalRequest;
use Modules\Accounting\Http\Requests\RejectJournalEntryRequest;
use Modules\Accounting\Http\Requests\ReverseJournalEntryRequest;
use Modules\Accounting\Http\Resources\JournalEntryResource;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

final class JournalEntryController
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * List journal entries with optional filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', JournalEntry::class);

        /** @var array{status?: string|null, source_type?: string|null, source_id?: string|null, account_id?: string|null, from_date?: string|null, to_date?: string|null, search?: string|null, per_page?: int|null} $filters */
        $filters = $request->query();
        $paginator = $this->journalEntryService->list($filters);

        return JournalEntryResource::collection($paginator);
    }

    /**
     * Show a single journal entry.
     */
    public function show(string $id): JournalEntryResource
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('view', $entry);

        return new JournalEntryResource($entry);
    }

    /**
     * Store a manual journal entry (posted or draft).
     */
    public function store(PostManualJournalRequest $request): JsonResponse
    {
        Gate::authorize('create', JournalEntry::class);

        /** @var array{entry_date: string, status?: string|null, description?: string|null, source_type?: string|null, source_id?: string|null, currency?: string|null, user_id?: string|null, lines: list<array{account_id: string, debit_cents?: int|null, credit_cents?: int|null, description?: string|null}>} $data */
        $data = $request->validated();

        /** @var list<JournalEntryLineData> $lines */
        $lines = array_map(
            fn (array $line): JournalEntryLineData => JournalEntryLineData::fromArray($line),
            $data['lines']
        );

        $status = ! empty($data['status']) ? JournalEntryStatus::from($data['status']) : null;
        $userId = $data['user_id'] ?? (Auth::id() !== null ? (string) Auth::id() : null);

        $postData = new PostJournalEntryData(
            entryDate: Carbon::parse($data['entry_date']),
            lines: $lines,
            description: $data['description'] ?? null,
            sourceType: $data['source_type'] ?? null,
            sourceId: $data['source_id'] ?? null,
            currency: $data['currency'] ?? 'USD',
            userId: $userId,
            status: $status,
        );

        $journalEntry = $this->journalEntryService->post($postData);

        return (new JournalEntryResource($journalEntry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update an editable draft or rejected journal entry.
     */
    public function update(string $id, PostManualJournalRequest $request): JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('update', $entry);

        /** @var array{entry_date: string, status?: string|null, description?: string|null, source_type?: string|null, source_id?: string|null, currency?: string|null, user_id?: string|null, lines: list<array{account_id: string, debit_cents?: int|null, credit_cents?: int|null, description?: string|null}>} $data */
        $data = $request->validated();

        /** @var list<JournalEntryLineData> $lines */
        $lines = array_map(
            fn (array $line): JournalEntryLineData => JournalEntryLineData::fromArray($line),
            $data['lines']
        );

        $userId = $data['user_id'] ?? (Auth::id() !== null ? (string) Auth::id() : null);

        $postData = new PostJournalEntryData(
            entryDate: Carbon::parse($data['entry_date']),
            lines: $lines,
            description: $data['description'] ?? null,
            sourceType: $data['source_type'] ?? null,
            sourceId: $data['source_id'] ?? null,
            currency: $data['currency'] ?? 'USD',
            userId: $userId,
            status: JournalEntryStatus::Draft,
        );

        $updated = $this->journalEntryService->updateDraft($id, $postData);

        return (new JournalEntryResource($updated))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Submit a draft journal entry for approval.
     */
    public function submit(string $id, Request $request): JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('submit', $entry);

        $userId = Auth::id() !== null ? (string) Auth::id() : null;
        $submitted = $this->journalEntryService->submitForApproval($id, $userId);

        return (new JournalEntryResource($submitted))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Approve and post a pending journal entry.
     */
    public function approve(string $id, Request $request): JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('approve', $entry);

        $userId = Auth::id() !== null ? (string) Auth::id() : null;
        $approved = $this->journalEntryService->approve($id, $userId);

        return (new JournalEntryResource($approved))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Reject a pending journal entry.
     */
    public function reject(string $id, RejectJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('reject', $entry);

        /** @var array{rejection_reason: string} $validated */
        $validated = $request->validated();
        $userId = Auth::id() !== null ? (string) Auth::id() : null;

        $rejected = $this->journalEntryService->reject($id, $validated['rejection_reason'], $userId);

        return (new JournalEntryResource($rejected))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Delete an unposted draft or rejected journal entry.
     */
    public function destroy(string $id): JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('delete', $entry);

        $this->journalEntryService->deleteDraft($id);

        return ApiResponse::success(null, 'Journal entry deleted successfully.');
    }

    /**
     * Reverse an existing journal entry.
     */
    public function reverse(string $id, ReverseJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('reverse', $entry);

        /** @var array{reason: string, user_id?: string|null} $validated */
        $validated = $request->validated();
        $reversalEntry = $this->journalEntryService->reverse(
            $entry,
            $validated['reason'],
            null,
            $validated['user_id'] ?? null,
        );

        return (new JournalEntryResource($reversalEntry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
