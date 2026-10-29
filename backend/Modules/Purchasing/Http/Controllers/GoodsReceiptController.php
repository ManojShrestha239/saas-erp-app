<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Purchasing\Http\Requests\StoreGoodsReceiptRequest;
use Modules\Purchasing\Http\Resources\GoodsReceiptResource;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\GoodsReceiptService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $receiptService,
    ) {}

    public function store(StoreGoodsReceiptRequest $request, string $orderId): JsonResponse
    {
        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::find($orderId);
        if ($po === null) {
            throw ApiException::notFound("Purchase order '{$orderId}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
        }

        Gate::authorize('receiveGoods', $po);

        $receipt = $this->receiptService->recordReceipt($request->toDto($orderId));

        return ApiResponse::created(
            data: new GoodsReceiptResource($receipt),
            message: 'Goods receipt recorded successfully.'
        );
    }
}
