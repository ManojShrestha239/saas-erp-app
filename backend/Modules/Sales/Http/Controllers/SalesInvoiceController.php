<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Sales\Http\Requests\StoreSalesInvoiceRequest;
use Modules\Sales\Http\Resources\SalesInvoiceResource;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Services\SalesInvoiceService;
use Shared\Responses\ApiResponse;
use Shared\Services\PdfRendererService;

final class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceService $invoiceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SalesInvoice::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['status', 'customer_id', 'search', 'per_page', 'page']);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->invoiceService->list($filters, $perPage);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: SalesInvoiceResource::collection($paginator->items()),
            message: 'Sales invoices retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();

        $invoice = $this->invoiceService->create($request->toDto(), $userId);

        return ApiResponse::created(
            data: new SalesInvoiceResource($invoice),
            message: 'Sales invoice created successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $invoice = $this->invoiceService->find($id);
        Gate::authorize('view', $invoice);

        return ApiResponse::success(
            data: new SalesInvoiceResource($invoice),
            message: 'Sales invoice retrieved successfully.'
        );
    }

    public function update(string $id, StoreSalesInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->find($id);
        Gate::authorize('update', $invoice);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $updated = $this->invoiceService->update($id, $request->toDto(), $userId);

        return ApiResponse::success(
            data: new SalesInvoiceResource($updated),
            message: 'Sales invoice updated successfully.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $invoice = $this->invoiceService->find($id);
        Gate::authorize('delete', $invoice);

        $this->invoiceService->delete($id);

        return ApiResponse::success(
            data: null,
            message: 'Sales invoice deleted successfully.'
        );
    }

    public function post(string $id, Request $request): JsonResponse
    {
        $invoice = $this->invoiceService->find($id);
        Gate::authorize('post', $invoice);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $posted = $this->invoiceService->post($id, $userId);

        return ApiResponse::success(
            data: new SalesInvoiceResource($posted),
            message: 'Sales invoice posted successfully.'
        );
    }

    public function void(string $id, Request $request): JsonResponse
    {
        $invoice = $this->invoiceService->find($id);
        Gate::authorize('void', $invoice);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $voided = $this->invoiceService->void($id, $userId);

        return ApiResponse::success(
            data: new SalesInvoiceResource($voided),
            message: 'Sales invoice voided successfully and inventory restored.'
        );
    }

    public function customerReceivableBalance(string $customerId): JsonResponse
    {
        Gate::authorize('viewAny', SalesInvoice::class);

        $balanceCents = $this->invoiceService->getOutstandingReceivableCents($customerId);

        return ApiResponse::success(
            data: [
                'customer_id' => $customerId,
                'outstanding_receivable_cents' => $balanceCents,
            ],
            message: 'Customer outstanding receivable balance retrieved successfully.'
        );
    }

    public function pdf(string $id, Request $request, PdfRendererService $pdfService): Response
    {
        $invoice = $this->invoiceService->find($id);
        Gate::authorize('view', $invoice);

        $data = $this->invoiceService->getPdfData($id);
        $filename = "{$invoice->invoice_number}.pdf";

        if ($request->query('download') === '1') {
            return $pdfService->downloadView('sales::pdf.invoice', $data, $filename);
        }

        return $pdfService->streamView('sales::pdf.invoice', $data, $filename);
    }
}
