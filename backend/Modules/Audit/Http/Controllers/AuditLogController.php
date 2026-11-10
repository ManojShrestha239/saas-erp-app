<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Http\Requests\ListAuditLogsRequest;
use Modules\Audit\Http\Resources\AuditLogResource;
use Modules\Audit\Models\TenantActivity;
use Modules\Audit\Services\AuditLogService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class AuditLogController
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', TenantActivity::class);

        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);

        $paginator = $this->auditLogService->paginate(
            filters: [
                'search' => $validated['search'] ?? null,
                'event' => $validated['event'] ?? null,
                'subject_type' => $validated['subject_type'] ?? null,
                'causer_id' => $validated['causer_id'] ?? null,
                'log_name' => $validated['log_name'] ?? null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            sort: [
                'field' => $validated['sort_by'] ?? 'id',
                'direction' => $validated['sort_direction'] ?? 'desc',
            ],
            perPage: $perPage,
        );

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: AuditLogResource::collection($paginator->items()),
            message: 'Audit logs retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(int $id): JsonResponse
    {
        $activity = $this->auditLogService->findById($id);

        if ($activity === null) {
            throw ApiException::notFound("Audit log {$id} not found.", 'AUDIT_LOG_NOT_FOUND');
        }

        Gate::authorize('view', $activity);

        return ApiResponse::success(
            data: new AuditLogResource($activity),
            message: 'Audit log retrieved successfully.',
        );
    }
}
