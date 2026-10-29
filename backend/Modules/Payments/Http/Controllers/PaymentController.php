<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Payments\Enums\PaymentType;
use Modules\Payments\Http\Requests\AllocatePaymentRequest;
use Modules\Payments\Http\Requests\StorePaymentRequest;
use Modules\Payments\Http\Resources\PaymentResource;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentService;
use Shared\Responses\ApiResponse;

final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->only(['type', 'status', 'contact_id', 'payment_method', 'search', 'per_page', 'page']);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->paymentService->list($filters, $perPage);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: PaymentResource::collection($paginator->items()),
            message: 'Payments retrieved successfully.',
            meta: $meta,
        );
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        /** @var string|null $userId */
        $userId = $request->user()?->getAuthIdentifier();

        $payment = $this->paymentService->create($request->toDto(), (string) $userId);

        return ApiResponse::created(
            data: new PaymentResource($payment),
            message: 'Payment recorded successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $payment = $this->paymentService->find($id);
        Gate::authorize('view', $payment);

        return ApiResponse::success(
            data: new PaymentResource($payment),
            message: 'Payment retrieved successfully.'
        );
    }

    public function post(string $id, Request $request): JsonResponse
    {
        $payment = $this->paymentService->find($id);
        Gate::authorize('post', $payment);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $posted = $this->paymentService->post($id, $userId);

        return ApiResponse::success(
            data: new PaymentResource($posted),
            message: 'Payment posted successfully.'
        );
    }

    public function allocate(string $id, AllocatePaymentRequest $request): JsonResponse
    {
        $payment = $this->paymentService->find($id);
        Gate::authorize('allocate', $payment);

        /** @var string $userId */
        $userId = (string) $request->user()?->getAuthIdentifier();

        $allocated = $this->paymentService->allocate($id, $request->toDto(), $userId);

        return ApiResponse::success(
            data: new PaymentResource($allocated),
            message: 'Payment allocated successfully.'
        );
    }

    public function unallocatedCredit(string $contactId, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $rawType = (string) $request->query('type', 'inbound');
        $type = PaymentType::tryFrom($rawType) ?? PaymentType::Inbound;

        $unallocatedCents = $this->paymentService->getUnallocatedCredit($contactId, $type);

        return ApiResponse::success(
            data: [
                'contact_id' => $contactId,
                'type' => $type->value,
                'unallocated_credit_cents' => $unallocatedCents,
            ],
            message: 'Contact unallocated credit balance retrieved successfully.'
        );
    }
}
