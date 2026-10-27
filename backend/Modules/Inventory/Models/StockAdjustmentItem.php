<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $stock_adjustment_id
 * @property string $product_id
 * @property int $quantity_before
 * @property int $quantity_delta
 * @property int $quantity_after
 * @property int $unit_cost_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockAdjustment $adjustment
 * @property-read Product $product
 */
final class StockAdjustmentItem extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'stock_adjustment_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'stock_adjustment_id',
        'product_id',
        'quantity_before',
        'quantity_delta',
        'quantity_after',
        'unit_cost_cents',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_delta' => 'integer',
        'quantity_after' => 'integer',
        'unit_cost_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
