<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SuperAdmin\Http\Resources\GlobalAuditLogResource;
use Modules\SuperAdmin\Services\GlobalAuditLogService;
use Shared\Responses\ApiResponse;

final class SuperAdminAuditLogController
{
    public function __construct(
        private readonly GlobalAuditLogService $globalAuditLogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->query('search');
        /** @var string|null $event */
        $event = $request->query('event');
        /** @var string|null $tenantId */
        $tenantId = $request->query('tenant_id');
        /** @var string|null $auditableType */
        $auditableType = $request->query('auditable_type');
        /** @var string|null $from */
        $from = $request->query('from');
        /** @var string|null $to */
        $to = $request->query('to');

        $sortBy = (string) $request->query('sort_by', 'id');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $paginator = $this->globalAuditLogService->paginate(
            filters: [
                'search' => $search,
                'event' => $event,
                'tenant_id' => $tenantId,
                'auditable_type' => $auditableType,
                'from' => $from,
                'to' => $to,
            ],
            sort: [
                'field' => $sortBy,
                'direction' => $sortDirection,
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
            data: GlobalAuditLogResource::collection($paginator->items()),
            message: 'Central audit logs retrieved successfully.',
            meta: $meta,
        );
    }
}
