<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Http\Requests\CalculateVatReturnRequest;
use Modules\Accounting\Http\Requests\FileVatReturnRequest;
use Modules\Accounting\Http\Resources\VatReturnResource;
use Modules\Accounting\Models\VatReturn;
use Modules\Accounting\Services\VatReturnService;
use Shared\Responses\ApiResponse;

final class VatReturnController
{
    public function __construct(
        private readonly VatReturnService $vatReturnService,
    ) {}

    public function calculate(CalculateVatReturnRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', VatReturn::class);

        $result = $this->vatReturnService->calculate($request->toDto());

        return ApiResponse::success(
            data: $result,
            message: 'VAT return calculated successfully.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', VatReturn::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->query();
        $returns = $this->vatReturnService->list($filters);

        if ($returns instanceof LengthAwarePaginator) {
            $meta = [
                'current_page' => $returns->currentPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
                'last_page' => $returns->lastPage(),
            ];

            return ApiResponse::success(
                data: VatReturnResource::collection($returns->items()),
                message: 'VAT returns retrieved successfully.',
                meta: $meta,
            );
        }

        return ApiResponse::success(
            data: VatReturnResource::collection($returns),
            message: 'VAT returns retrieved successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $vatReturn = $this->vatReturnService->find($id);
        Gate::authorize('view', $vatReturn);

        return ApiResponse::success(
            data: new VatReturnResource($vatReturn),
            message: 'VAT return retrieved successfully.'
        );
    }

    public function store(FileVatReturnRequest $request): JsonResponse
    {
        Gate::authorize('create', VatReturn::class);

        $userId = (string) $request->user()?->getAuthIdentifier();
        $vatReturn = $this->vatReturnService->file($request->toDto(), $userId);

        return ApiResponse::created(
            data: new VatReturnResource($vatReturn),
            message: 'VAT return filed successfully.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $vatReturn = $this->vatReturnService->find($id);
        Gate::authorize('delete', $vatReturn);

        $vatReturn->delete();

        return ApiResponse::success(
            data: null,
            message: 'VAT return deleted successfully.'
        );
    }

    public function exportPdf(CalculateVatReturnRequest $request): Response
    {
        Gate::authorize('viewAny', VatReturn::class);

        $data = $this->vatReturnService->calculate($request->toDto());
        $pdf = $this->vatReturnService->renderPdf($data);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vat-return.pdf"',
        ]);
    }
}
