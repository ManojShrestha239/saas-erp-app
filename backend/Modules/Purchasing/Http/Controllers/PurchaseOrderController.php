<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\PurchaseOrderService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;
use Shared\Services\PdfRendererService;

final class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $poService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'supplier_id', 'warehouse_id', 'search', 'per_page', 'page']);
        $paginator = $this->poService->list($filters);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: PurchaseOrderResource::collection($paginator->items()),
            message: 'Purchase orders retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->poService->create($request->toDto());

        return ApiResponse::created(
            data: new PurchaseOrderResource($po),
            message: 'Purchase order created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $po = $this->poService->find($id);
        if ($po === null) {
            throw ApiException::notFound("Purchase order '{$id}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
        }

        Gate::authorize('view', $po);

        return ApiResponse::success(
            data: new PurchaseOrderResource($po),
            message: 'Purchase order retrieved successfully.'
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $po = $this->poService->find($id);
        if ($po === null) {
            throw ApiException::notFound("Purchase order '{$id}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
        }

        Gate::authorize('approve', $po);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();
        $approvedPo = $this->poService->approve($id, $userId);

        return ApiResponse::success(
            data: new PurchaseOrderResource($approvedPo),
            message: 'Purchase order approved successfully.'
        );
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $po = $this->poService->find($id);
        if ($po === null) {
            throw ApiException::notFound("Purchase order '{$id}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
        }

        Gate::authorize('cancel', $po);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();
        $cancelledPo = $this->poService->cancel($id, $userId);

        return ApiResponse::success(
            data: new PurchaseOrderResource($cancelledPo),
            message: 'Purchase order cancelled successfully.'
        );
    }

    public function pdf(string $id, Request $request, PdfRendererService $pdfService): Response
    {
        $po = $this->poService->find($id);
        if ($po === null) {
            throw ApiException::notFound("Purchase order '{$id}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
        }

        Gate::authorize('view', $po);

        $data = $this->poService->getPdfData($id);
        $filename = "{$po->order_number}.pdf";

        if ($request->query('download') === '1') {
            return $pdfService->downloadView('purchasing::pdf.purchase_order', $data, $filename);
        }

        return $pdfService->streamView('purchasing::pdf.purchase_order', $data, $filename);
    }
}
