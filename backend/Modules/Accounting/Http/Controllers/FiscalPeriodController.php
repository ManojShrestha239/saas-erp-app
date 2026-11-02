<?php

declare(strict_types=1);

namespace Modules\Accounting\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\DTOs\CreateFiscalPeriodData;
use Modules\Accounting\DTOs\GenerateFiscalYearData;
use Modules\Accounting\Enums\FiscalPeriodStatus;
use Modules\Accounting\Http\Requests\GenerateFiscalYearRequest;
use Modules\Accounting\Http\Requests\StoreFiscalPeriodRequest;
use Modules\Accounting\Http\Resources\FiscalPeriodResource;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Accounting\Services\FiscalPeriodService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class FiscalPeriodController
{
    public function __construct(
        private readonly FiscalPeriodService $fiscalPeriodService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', FiscalPeriod::class);

        /** @var array<string, mixed> $filters */
        $filters = $request->query();
        $periods = $this->fiscalPeriodService->list($filters);

        if ($periods instanceof LengthAwarePaginator) {
            $meta = [
                'current_page' => $periods->currentPage(),
                'per_page' => $periods->perPage(),
                'total' => $periods->total(),
                'last_page' => $periods->lastPage(),
            ];

            return ApiResponse::success(
                data: FiscalPeriodResource::collection($periods->items()),
                message: 'Fiscal periods retrieved successfully.',
                meta: $meta,
            );
        }

        return ApiResponse::success(
            data: FiscalPeriodResource::collection($periods),
            message: 'Fiscal periods retrieved successfully.'
        );
    }

    public function show(string $id): JsonResponse
    {
        $period = $this->fiscalPeriodService->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        Gate::authorize('view', $period);

        return ApiResponse::success(
            data: new FiscalPeriodResource($period),
            message: 'Fiscal period retrieved successfully.'
        );
    }

    public function store(StoreFiscalPeriodRequest $request): JsonResponse
    {
        Gate::authorize('create', FiscalPeriod::class);

        /** @var array{name: string, fiscal_year: int, period_number: int, start_date: string, end_date: string, status?: string|null} $validated */
        $validated = $request->validated();

        $dto = new CreateFiscalPeriodData(
            name: $validated['name'],
            fiscalYear: (int) $validated['fiscal_year'],
            periodNumber: (int) $validated['period_number'],
            startDate: Carbon::parse($validated['start_date']),
            endDate: Carbon::parse($validated['end_date']),
            status: ! empty($validated['status']) ? FiscalPeriodStatus::from($validated['status']) : FiscalPeriodStatus::Open,
        );

        $period = $this->fiscalPeriodService->create($dto);

        return ApiResponse::created(
            data: new FiscalPeriodResource($period),
            message: 'Fiscal period created successfully.'
        );
    }

    public function generate(GenerateFiscalYearRequest $request): JsonResponse
    {
        Gate::authorize('create', FiscalPeriod::class);

        /** @var array{fiscal_year: int, start_date?: string|null} $validated */
        $validated = $request->validated();

        $dto = new GenerateFiscalYearData(
            fiscalYear: (int) $validated['fiscal_year'],
            startDate: ! empty($validated['start_date']) ? Carbon::parse($validated['start_date']) : null,
        );

        $periods = $this->fiscalPeriodService->generateYear($dto);

        return ApiResponse::created(
            data: FiscalPeriodResource::collection($periods),
            message: 'Fiscal periods generated successfully.'
        );
    }

    public function lock(string $id, Request $request): JsonResponse
    {
        $period = $this->fiscalPeriodService->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        Gate::authorize('lock', $period);

        $userId = Auth::id() !== null ? (string) Auth::id() : null;
        $updated = $this->fiscalPeriodService->lock($id, $userId);

        return ApiResponse::success(
            data: new FiscalPeriodResource($updated),
            message: 'Fiscal period locked successfully.'
        );
    }

    public function unlock(string $id, Request $request): JsonResponse
    {
        $period = $this->fiscalPeriodService->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        Gate::authorize('unlock', $period);

        $userId = Auth::id() !== null ? (string) Auth::id() : null;
        $updated = $this->fiscalPeriodService->unlock($id, $userId);

        return ApiResponse::success(
            data: new FiscalPeriodResource($updated),
            message: 'Fiscal period unlocked successfully.'
        );
    }

    public function close(string $id, Request $request): JsonResponse
    {
        $period = $this->fiscalPeriodService->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        Gate::authorize('close', $period);

        $userId = Auth::id() !== null ? (string) Auth::id() : null;
        $updated = $this->fiscalPeriodService->close($id, $userId);

        return ApiResponse::success(
            data: new FiscalPeriodResource($updated),
            message: 'Fiscal period closed successfully.'
        );
    }
}
