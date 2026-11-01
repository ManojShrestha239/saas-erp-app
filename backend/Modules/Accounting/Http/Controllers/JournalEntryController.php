<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
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
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
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
    public function store(PostManualJournalRequest $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('create', JournalEntry::class);

        $data = $request->validated();
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
    public function reverse(string $id, ReverseJournalEntryRequest $request): \Illuminate\Http\JsonResponse
    {
        $entry = $this->journalEntryService->findById($id);
        if ($entry === null) {
            throw ApiException::notFound("Journal entry {$id} not found.", 'JOURNAL_NOT_FOUND');
        }

        Gate::authorize('reverse', $entry);

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
