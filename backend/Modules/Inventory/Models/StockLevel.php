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
 * @property string $warehouse_id
 * @property string $product_id
 * @property int $quantity_on_hand
 * @property int $quantity_reserved
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Warehouse $warehouse
 * @property-read Product $product
 * @property-read int $quantity_available
 */
final class StockLevel extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'stock_levels';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'warehouse_id',
        'product_id',
        'quantity_on_hand',
        'quantity_reserved',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity_on_hand' => 'integer',
        'quantity_reserved' => 'integer',
    ];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function getQuantityAvailableAttribute(): int
    {
        return max(0, $this->quantity_on_hand - $this->quantity_reserved);
    }
}
