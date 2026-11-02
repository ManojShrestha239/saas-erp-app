<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\FiscalPeriodStatus;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $name
 * @property int $fiscal_year
 * @property int $period_number
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property FiscalPeriodStatus $status
 * @property Carbon|null $locked_at
 * @property string|null $locked_by_user_id
 * @property Carbon|null $closed_at
 * @property string|null $closed_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> open()
 * @method static Builder<static> forYear(int $year)
 * @method static Builder<static> coveringDate(CarbonInterface|string $date)
 */
final class FiscalPeriod extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'fiscal_periods';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'fiscal_year',
        'period_number',
        'start_date',
        'end_date',
        'status',
        'locked_at',
        'locked_by_user_id',
        'closed_at',
        'closed_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fiscal_year' => 'integer',
        'period_number' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => FiscalPeriodStatus::class,
        'locked_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * @param  Builder<FiscalPeriod>  $query
     * @return Builder<FiscalPeriod>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', FiscalPeriodStatus::Open->value);
    }

    /**
     * @param  Builder<FiscalPeriod>  $query
     * @return Builder<FiscalPeriod>
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('fiscal_year', $year);
    }

    /**
     * @param  Builder<FiscalPeriod>  $query
     * @return Builder<FiscalPeriod>
     */
    public function scopeCoveringDate(Builder $query, CarbonInterface|string $date): Builder
    {
        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;

        return $query->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr);
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalPeriodStatus::Open;
    }

    public function isLocked(): bool
    {
        return $this->status === FiscalPeriodStatus::Locked;
    }

    public function isClosed(): bool
    {
        return $this->status === FiscalPeriodStatus::Closed;
    }
}
