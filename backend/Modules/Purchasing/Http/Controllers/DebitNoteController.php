<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Purchasing\Http\Requests\StoreDebitNoteRequest;
use Modules\Purchasing\Http\Resources\DebitNoteResource;
use Modules\Purchasing\Models\DebitNote;
use Modules\Purchasing\Services\DebitNoteService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class DebitNoteController extends Controller
{
    public function __construct(
        private readonly DebitNoteService $debitNoteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DebitNote::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'supplier_id', 'supplier_bill_id', 'search', 'per_page', 'page']);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->debitNoteService->list($filters, $perPage);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: DebitNoteResource::collection($paginator->items()),
            message: 'Debit notes retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StoreDebitNoteRequest $request): JsonResponse
    {
        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            throw ApiException::unauthorized('User must be authenticated.');
        }

        $debitNote = $this->debitNoteService->create($request->toDto(), $userId);

        return ApiResponse::created(
            data: new DebitNoteResource($debitNote),
            message: 'Debit note created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $debitNote = $this->debitNoteService->findById($id);
        if ($debitNote === null) {
            throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('view', $debitNote);

        return ApiResponse::success(
            data: new DebitNoteResource($debitNote),
            message: 'Debit note retrieved successfully.'
        );
    }

    public function update(string $id, StoreDebitNoteRequest $request): JsonResponse
    {
        $debitNote = $this->debitNoteService->findById($id);
        if ($debitNote === null) {
            throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('update', $debitNote);

        $updated = $this->debitNoteService->update($id, $request->toDto());

        return ApiResponse::success(
            data: new DebitNoteResource($updated),
            message: 'Debit note updated successfully.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $debitNote = $this->debitNoteService->findById($id);
        if ($debitNote === null) {
            throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('delete', $debitNote);

        $this->debitNoteService->delete($id);

        return ApiResponse::success(
            data: null,
            message: 'Debit note deleted successfully.'
        );
    }

    public function post(string $id, Request $request): JsonResponse
    {
        $debitNote = $this->debitNoteService->findById($id);
        if ($debitNote === null) {
            throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('post', $debitNote);

        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            throw ApiException::unauthorized('User must be authenticated.');
        }

        $posted = $this->debitNoteService->post($id, $userId);

        return ApiResponse::success(
            data: new DebitNoteResource($posted),
            message: 'Debit note posted successfully.'
        );
    }
}
