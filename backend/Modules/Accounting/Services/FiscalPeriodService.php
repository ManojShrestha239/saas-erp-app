<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Modules\Accounting\DTOs\CreateFiscalPeriodData;
use Modules\Accounting\DTOs\GenerateFiscalYearData;
use Modules\Accounting\Enums\FiscalPeriodStatus;
use Modules\Accounting\Models\FiscalPeriod;
use Shared\Exceptions\ApiException;

final class FiscalPeriodService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, FiscalPeriod>|Collection<int, FiscalPeriod>
     */
    public function list(array $filters): LengthAwarePaginator|Collection
    {
        $query = FiscalPeriod::query();

        if (! empty($filters['fiscal_year'])) {
            $query->where('fiscal_year', (int) $filters['fiscal_year']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $query->orderBy('fiscal_year', 'desc')->orderBy('period_number', 'asc');

        if (isset($filters['all']) && filter_var($filters['all'], FILTER_VALIDATE_BOOLEAN)) {
            /** @var Collection<int, FiscalPeriod> $periods */
            $periods = $query->get();

            return $periods;
        }

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 25;

        /** @var LengthAwarePaginator<int, FiscalPeriod> $paginator */
        $paginator = $query->paginate(max(1, min($perPage, 100)));

        return $paginator;
    }

    public function findById(string $id): ?FiscalPeriod
    {
        return FiscalPeriod::find($id);
    }

    public function create(CreateFiscalPeriodData $data): FiscalPeriod
    {
        if ($data->startDate->greaterThan($data->endDate)) {
            throw ApiException::unprocessable(
                'Period start date cannot be after end date.',
                'INVALID_DATE_RANGE'
            );
        }

        $exists = FiscalPeriod::where('fiscal_year', $data->fiscalYear)
            ->where('period_number', $data->periodNumber)
            ->exists();

        if ($exists) {
            throw ApiException::unprocessable(
                "Period number {$data->periodNumber} for fiscal year {$data->fiscalYear} already exists.",
                'PERIOD_ALREADY_EXISTS'
            );
        }

        /** @var FiscalPeriod $period */
        $period = $this->db->connection('tenant')->transaction(function () use ($data): FiscalPeriod {
            return FiscalPeriod::create([
                'name' => $data->name,
                'fiscal_year' => $data->fiscalYear,
                'period_number' => $data->periodNumber,
                'start_date' => $data->startDate->toDateString(),
                'end_date' => $data->endDate->toDateString(),
                'status' => $data->status,
            ]);
        });

        return $period;
    }

    /**
     * @return Collection<int, FiscalPeriod>
     */
    public function generateYear(GenerateFiscalYearData $data): Collection
    {
        $year = $data->fiscalYear;

        return $this->db->connection('tenant')->transaction(function () use ($year): Collection {
            $periods = new Collection;

            for ($month = 1; $month <= 12; $month++) {
                $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();
                $monthName = $startDate->translatedFormat('F');

                /** @var FiscalPeriod $period */
                $period = FiscalPeriod::firstOrCreate(
                    [
                        'fiscal_year' => $year,
                        'period_number' => $month,
                    ],
                    [
                        'name' => "{$monthName} {$year}",
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'status' => FiscalPeriodStatus::Open,
                    ]
                );

                $periods->push($period);
            }

            return $periods;
        });
    }

    public function lock(string $id, ?string $userId = null): FiscalPeriod
    {
        $period = $this->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        if ($period->isClosed()) {
            throw ApiException::badRequest(
                'Closed fiscal periods cannot be locked or modified.',
                'PERIOD_ALREADY_CLOSED'
            );
        }

        $period->update([
            'status' => FiscalPeriodStatus::Locked,
            'locked_at' => now(),
            'locked_by_user_id' => $userId,
        ]);

        $period->refresh();

        return $period;
    }

    public function unlock(string $id, ?string $userId = null): FiscalPeriod
    {
        $period = $this->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        if ($period->isClosed()) {
            throw ApiException::badRequest(
                'Closed fiscal periods cannot be unlocked.',
                'PERIOD_ALREADY_CLOSED'
            );
        }

        $period->update([
            'status' => FiscalPeriodStatus::Open,
            'locked_at' => null,
            'locked_by_user_id' => null,
        ]);

        $period->refresh();

        return $period;
    }

    public function close(string $id, ?string $userId = null): FiscalPeriod
    {
        $period = $this->findById($id);
        if ($period === null) {
            throw ApiException::notFound("Fiscal period {$id} not found.", 'PERIOD_NOT_FOUND');
        }

        $period->update([
            'status' => FiscalPeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by_user_id' => $userId,
        ]);

        $period->refresh();

        return $period;
    }

    public function getPeriodForDate(CarbonInterface|string $date): ?FiscalPeriod
    {
        /** @var FiscalPeriod|null $period */
        $period = FiscalPeriod::coveringDate($date)->first();

        return $period;
    }

    public function assertPeriodOpen(CarbonInterface|string $date): void
    {
        $period = $this->getPeriodForDate($date);

        if ($period !== null) {
            if ($period->isLocked()) {
                throw ApiException::badRequest(
                    "The fiscal period '{$period->name}' is locked. Posting is prohibited.",
                    'FISCAL_PERIOD_LOCKED'
                );
            }

            if ($period->isClosed()) {
                throw ApiException::badRequest(
                    "The fiscal period '{$period->name}' is closed. Posting is prohibited.",
                    'FISCAL_PERIOD_CLOSED'
                );
            }
        }
    }
}
