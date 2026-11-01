<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\DTOs\JournalEntryLineData;
use Modules\Accounting\DTOs\PostJournalEntryData;
use Modules\Accounting\Http\Requests\PostManualJournalRequest;
use Modules\Accounting\Http\Requests\ReverseJournalEntryRequest;
use Modules\Accounting\Http\Resources\JournalEntryResource;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Shared\Exceptions\ApiException;
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

        /** @var array<string, mixed> $filters */
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
     * Store a manual journal entry.
     */
    public function store(PostManualJournalRequest $request): JsonResponse
    {
        Gate::authorize('create', JournalEntry::class);

        /** @var array{entry_date: string, description?: string|null, source_type?: string|null, source_id?: string|null, currency: string, user_id?: string|null, lines: list<array{account_id: string, debit_cents?: int|null, credit_cents?: int|null, description?: string|null}>} $data */
        $data = $request->validated();
        /** @var list<JournalEntryLineData> $lines */
        $lines = array_map(
            fn (array $line): JournalEntryLineData => JournalEntryLineData::fromArray($line),
            $data['lines']
        );
        $postData = new PostJournalEntryData(
            entryDate: Carbon::parse($data['entry_date']),
            lines: $lines,
            description: $data['description'] ?? null,
            sourceType: $data['source_type'] ?? null,
            sourceId: $data['source_id'] ?? null,
            currency: $data['currency'],
            userId: $data['user_id'] ?? null,
        );
        $journalEntry = $this->journalEntryService->post($postData);

        return (new JournalEntryResource($journalEntry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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
