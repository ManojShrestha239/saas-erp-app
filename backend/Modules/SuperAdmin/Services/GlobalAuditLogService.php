<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\SuperAdmin\Models\GlobalAuditLog;

final class GlobalAuditLogService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $event,
        ?string $tenantId = null,
        ?string $userId = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): GlobalAuditLog {
        return GlobalAuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'event' => $event,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    /**
     * @param array{
     *     search?: string|null,
     *     event?: string|null,
     *     tenant_id?: string|null,
     *     auditable_type?: string|null,
     *     from?: string|null,
     *     to?: string|null
     * } $filters
     * @param array{
     *     field?: string|null,
     *     direction?: string|null
     * } $sort
     * @return LengthAwarePaginator<int, GlobalAuditLog>
     */
    public function paginate(array $filters = [], array $sort = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = GlobalAuditLog::query();

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('event', 'ilike', $term)
                    ->orWhere('tenant_id', 'ilike', $term)
                    ->orWhere('user_id', 'ilike', $term)
                    ->orWhere('auditable_type', 'ilike', $term)
                    ->orWhere('auditable_id', 'ilike', $term);
            });
        }

        if (! empty($filters['event'])) {
            $query->where('event', (string) $filters['event']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (string) $filters['tenant_id']);
        }

        if (! empty($filters['auditable_type'])) {
            $query->where('auditable_type', (string) $filters['auditable_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', (string) $filters['to']);
        }

        $sortField = $sort['field'] ?? 'id';
        $allowedSortFields = ['id', 'created_at', 'event', 'tenant_id', 'user_id'];
        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'id';
        }

        $sortDirection = strtolower((string) ($sort['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        /** @var LengthAwarePaginator<int, GlobalAuditLog> $paginator */
        $paginator = $query->paginate($perPage);

        return $paginator;
    }
}
