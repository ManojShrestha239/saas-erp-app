<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SuperAdmin\Http\Requests\AssignSubscriptionRequest;
use Modules\SuperAdmin\Http\Resources\SuperAdminSubscriptionResource;
use Modules\SuperAdmin\Services\SuperAdminSubscriptionService;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;

final class SuperAdminSubscriptionController
{
    public function __construct(
        private readonly SuperAdminSubscriptionService $subscriptionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id') !== null ? (string) $request->query('tenant_id') : null;
        $subscriptions = $this->subscriptionService->listSubscriptions($tenantId);

        return ApiResponse::success(
            data: SuperAdminSubscriptionResource::collection($subscriptions),
        );
    }

    public function store(AssignSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptionService->assignSubscription($request->toDto());

        return ApiResponse::success(
            data: new SuperAdminSubscriptionResource($subscription),
            message: 'Subscription assigned successfully.',
            status: HttpStatus::CREATED,
        );
    }

    public function cancel(int $id): JsonResponse
    {
        $subscription = $this->subscriptionService->cancelSubscription($id);

        return ApiResponse::success(
            data: new SuperAdminSubscriptionResource($subscription),
            message: 'Subscription cancelled successfully.',
        );
    }
}
