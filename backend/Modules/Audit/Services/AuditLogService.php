<?php

declare(strict_types=1);

namespace Modules\Audit\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Audit\Models\TenantActivity;

final class AuditLogService
{
    /**
     * @param array{
     *     search?: string|null,
     *     event?: string|null,
     *     subject_type?: string|null,
     *     causer_id?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     log_name?: string|null
     * } $filters
     * @param array{
     *     field?: string|null,
     *     direction?: string|null
     * } $sort
     * @return LengthAwarePaginator<int, TenantActivity>
     */
    public function paginate(array $filters = [], array $sort = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = TenantActivity::query()->with(['causer']);

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('description', 'ilike', $term)
                    ->orWhere('event', 'ilike', $term)
                    ->orWhere('subject_type', 'ilike', $term)
                    ->orWhere('subject_id', 'ilike', $term)
                    ->orWhere('causer_id', 'ilike', $term);
            });
        }

        if (! empty($filters['event'])) {
            $query->where('event', (string) $filters['event']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', (string) $filters['subject_type']);
        }

        if (! empty($filters['causer_id'])) {
            $query->where('causer_id', (string) $filters['causer_id']);
        }

        if (! empty($filters['log_name'])) {
            $query->where('log_name', (string) $filters['log_name']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', (string) $filters['to']);
        }

        $sortField = $sort['field'] ?? 'id';
        $allowedSortFields = ['id', 'created_at', 'event', 'subject_type', 'description'];
        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'id';
        }

        $sortDirection = strtolower((string) ($sort['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        /** @var LengthAwarePaginator<int, TenantActivity> $paginator */
        $paginator = $query->paginate($perPage);

        return $paginator;
    }

    public function findById(int $id): ?TenantActivity
    {
        /** @var TenantActivity|null */
        return TenantActivity::query()->with(['causer'])->find($id);
    }
}
