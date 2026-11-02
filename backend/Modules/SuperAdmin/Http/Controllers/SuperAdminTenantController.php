<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SuperAdmin\Http\Requests\SuspendTenantRequest;
use Modules\SuperAdmin\Http\Resources\SuperAdminTenantResource;
use Modules\Tenancy\Services\TenantManagementService;
use Shared\Responses\ApiResponse;

final class SuperAdminTenantController
{
    public function __construct(
        private readonly TenantManagementService $tenantManagementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var array{search?: ?string, status?: ?string} $filters */
        $filters = [
            'search' => $request->query('search') !== null ? (string) $request->query('search') : null,
            'status' => $request->query('status') !== null ? (string) $request->query('status') : null,
        ];

        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->tenantManagementService->listTenants($filters, $perPage);

        return ApiResponse::success(
            data: SuperAdminTenantResource::collection($paginator),
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantManagementService->findTenant($id);

        return ApiResponse::success(
            data: new SuperAdminTenantResource($tenant),
        );
    }

    public function suspend(string $id, SuspendTenantRequest $request): JsonResponse
    {
        $reason = $request->filled('reason') ? (string) $request->input('reason') : null;
        $tenant = $this->tenantManagementService->suspendTenant($id, $reason);

        return ApiResponse::success(
            data: new SuperAdminTenantResource($tenant),
            message: "Tenant '{$tenant->name}' has been suspended.",
        );
    }

    public function reactivate(string $id): JsonResponse
    {
        $tenant = $this->tenantManagementService->reactivateTenant($id);

        return ApiResponse::success(
            data: new SuperAdminTenantResource($tenant),
            message: "Tenant '{$tenant->name}' has been reactivated.",
        );
    }

    public function retryProvisioning(string $id): JsonResponse
    {
        $tenant = $this->tenantManagementService->retryProvisioning($id);

        return ApiResponse::success(
            data: new SuperAdminTenantResource($tenant),
            message: "Provisioning retry dispatched for tenant '{$tenant->name}'.",
        );
    }
}
