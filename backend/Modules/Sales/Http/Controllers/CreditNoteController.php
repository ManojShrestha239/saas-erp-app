<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Sales\Http\Requests\StoreCreditNoteRequest;
use Modules\Sales\Http\Resources\CreditNoteResource;
use Modules\Sales\Models\CreditNote;
use Modules\Sales\Services\CreditNoteService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class CreditNoteController extends Controller
{
    public function __construct(
        private readonly CreditNoteService $creditNoteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CreditNote::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'customer_id', 'sales_invoice_id', 'search', 'per_page', 'page']);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->creditNoteService->list($filters, $perPage);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: CreditNoteResource::collection($paginator->items()),
            message: 'Credit notes retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StoreCreditNoteRequest $request): JsonResponse
    {
        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            throw ApiException::unauthorized('User must be authenticated.');
        }

        $creditNote = $this->creditNoteService->create($request->toDto(), $userId);

        return ApiResponse::created(
            data: new CreditNoteResource($creditNote),
            message: 'Credit note created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $creditNote = $this->creditNoteService->findById($id);
        if ($creditNote === null) {
            throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('view', $creditNote);

        return ApiResponse::success(
            data: new CreditNoteResource($creditNote),
            message: 'Credit note retrieved successfully.'
        );
    }

    public function update(string $id, StoreCreditNoteRequest $request): JsonResponse
    {
        $creditNote = $this->creditNoteService->findById($id);
        if ($creditNote === null) {
            throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('update', $creditNote);

        $updated = $this->creditNoteService->update($id, $request->toDto());

        return ApiResponse::success(
            data: new CreditNoteResource($updated),
            message: 'Credit note updated successfully.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $creditNote = $this->creditNoteService->findById($id);
        if ($creditNote === null) {
            throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('delete', $creditNote);

        $this->creditNoteService->delete($id);

        return ApiResponse::success(
            data: null,
            message: 'Credit note deleted successfully.'
        );
    }

    public function post(string $id, Request $request): JsonResponse
    {
        $creditNote = $this->creditNoteService->findById($id);
        if ($creditNote === null) {
            throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
        }

        Gate::authorize('post', $creditNote);

        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null) {
            throw ApiException::unauthorized('User must be authenticated.');
        }

        $posted = $this->creditNoteService->post($id, $userId);

        return ApiResponse::success(
            data: new CreditNoteResource($posted),
            message: 'Credit note posted successfully.'
        );
    }
}
