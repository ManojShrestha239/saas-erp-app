<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Sales\Http\Requests\ConvertSalesQuotationRequest;
use Modules\Sales\Http\Requests\StoreSalesQuotationRequest;
use Modules\Sales\Http\Resources\SalesInvoiceResource;
use Modules\Sales\Http\Resources\SalesQuotationResource;
use Modules\Sales\Models\SalesQuotation;
use Modules\Sales\Services\SalesQuotationService;
use Shared\Responses\ApiResponse;

final class SalesQuotationController extends Controller
{
    public function __construct(
        private readonly SalesQuotationService $quotationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SalesQuotation::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'customer_id', 'search', 'per_page', 'page']);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->quotationService->list($filters, $perPage);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: SalesQuotationResource::collection($paginator->items()),
            message: 'Sales quotations retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StoreSalesQuotationRequest $request): JsonResponse
    {
        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();

        $quotation = $this->quotationService->create($request->toDto(), $userId);

        return ApiResponse::created(
            data: new SalesQuotationResource($quotation),
            message: 'Sales quotation created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $quotation = $this->quotationService->find($id);
        Gate::authorize('view', $quotation);

        return ApiResponse::success(
            data: new SalesQuotationResource($quotation),
            message: 'Sales quotation retrieved successfully.'
        );
    }

    public function send(string $id, Request $request): JsonResponse
    {
        $quotation = $this->quotationService->find($id);
        Gate::authorize('send', $quotation);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $updated = $this->quotationService->markAsSent($id, $userId);

        return ApiResponse::success(
            data: new SalesQuotationResource($updated),
            message: 'Sales quotation marked as sent successfully.'
        );
    }

    public function accept(string $id, Request $request): JsonResponse
    {
        $quotation = $this->quotationService->find($id);
        Gate::authorize('accept', $quotation);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $updated = $this->quotationService->markAsAccepted($id, $userId);

        return ApiResponse::success(
            data: new SalesQuotationResource($updated),
            message: 'Sales quotation accepted successfully.'
        );
    }

    public function reject(string $id, Request $request): JsonResponse
    {
        $quotation = $this->quotationService->find($id);
        Gate::authorize('reject', $quotation);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $updated = $this->quotationService->markAsRejected($id, $userId);

        return ApiResponse::success(
            data: new SalesQuotationResource($updated),
            message: 'Sales quotation rejected successfully.'
        );
    }

    public function convert(string $id, ConvertSalesQuotationRequest $request): JsonResponse
    {
        $quotation = $this->quotationService->find($id);
        Gate::authorize('convert', $quotation);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $dueDate = $request->input('due_date') ? (string) $request->input('due_date') : null;
        $postImmediately = (bool) $request->input('post_immediately', false);

        $invoice = $this->quotationService->convertToInvoice($id, $userId, $dueDate, $postImmediately);

        return ApiResponse::created(
            data: new SalesInvoiceResource($invoice),
            message: 'Sales quotation successfully converted to sales invoice.'
        );
    }
}
