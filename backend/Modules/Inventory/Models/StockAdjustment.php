<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $adjustment_number
 * @property string $warehouse_id
 * @property string $reason
 * @property string|null $user_id
 * @property string $status
 * @property int $total_cost_impact_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, StockAdjustmentItem> $items
 */
final class StockAdjustment extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'stock_adjustments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'adjustment_number',
        'warehouse_id',
        'reason',
        'user_id',
        'status',
        'total_cost_impact_cents',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_cost_impact_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class, 'stock_adjustment_id');
    }
}
