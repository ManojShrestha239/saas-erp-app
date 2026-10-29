<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Purchasing\Http\Requests\StoreSupplierBillRequest;
use Modules\Purchasing\Http\Resources\SupplierBillResource;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Purchasing\Services\SupplierBillService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class SupplierBillController extends Controller
{
    public function __construct(
        private readonly SupplierBillService $billService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SupplierBill::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'supplier_id', 'purchase_order_id', 'search', 'per_page', 'page']);
        $paginator = $this->billService->list($filters);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: SupplierBillResource::collection($paginator->items()),
            message: 'Supplier bills retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StoreSupplierBillRequest $request): JsonResponse
    {
        $bill = $this->billService->create($request->toDto());

        return ApiResponse::created(
            data: new SupplierBillResource($bill),
            message: 'Supplier bill created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $bill = $this->billService->find($id);
        if ($bill === null) {
            throw ApiException::notFound("Supplier bill '{$id}' not found.", 'BILL_NOT_FOUND');
        }

        Gate::authorize('view', $bill);

        return ApiResponse::success(
            data: new SupplierBillResource($bill),
            message: 'Supplier bill retrieved successfully.'
        );
    }

    public function postBill(Request $request, string $id): JsonResponse
    {
        $bill = $this->billService->find($id);
        if ($bill === null) {
            throw ApiException::notFound("Supplier bill '{$id}' not found.", 'BILL_NOT_FOUND');
        }

        Gate::authorize('post', $bill);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();
        $postedBill = $this->billService->postBill($id, $userId);

        return ApiResponse::success(
            data: new SupplierBillResource($postedBill),
            message: 'Supplier bill posted successfully.'
        );
    }

    public function supplierBalance(string $supplierId): JsonResponse
    {
        Gate::authorize('viewAny', SupplierBill::class);

        $payableCents = $this->billService->getOutstandingPayableCents($supplierId);

        return ApiResponse::success(
            data: [
                'supplier_id' => $supplierId,
                'outstanding_payable_cents' => $payableCents,
            ],
            message: 'Supplier payable balance retrieved successfully.'
        );
    }
}
