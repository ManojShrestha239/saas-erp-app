<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Inventory\Enums\StockMovementType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $warehouse_id
 * @property string $product_id
 * @property StockMovementType $type
 * @property int $quantity
 * @property int $unit_cost_cents
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $user_id
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read Warehouse $warehouse
 * @property-read Product $product
 */
final class StockMovement extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'stock_movements';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'warehouse_id',
        'product_id',
        'type',
        'quantity',
        'unit_cost_cents',
        'source_type',
        'source_id',
        'user_id',
        'notes',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => StockMovementType::class,
        'quantity' => 'integer',
        'unit_cost_cents' => 'integer',
        'created_at' => 'datetime',
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
}
