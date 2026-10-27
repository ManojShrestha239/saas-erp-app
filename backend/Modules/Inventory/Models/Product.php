<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Inventory\Enums\TaxCategory;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string|null $category_id
 * @property string|null $unit_of_measure_id
 * @property string $sku
 * @property string|null $barcode
 * @property string $name
 * @property string|null $description
 * @property int $cost_price_cents
 * @property int $selling_price_cents
 * @property TaxCategory $tax_category
 * @property int $low_stock_threshold
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 * @property-read UnitOfMeasure|null $unitOfMeasure
 */
final class Product extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'category_id',
        'unit_of_measure_id',
        'sku',
        'barcode',
        'name',
        'description',
        'cost_price_cents',
        'selling_price_cents',
        'tax_category',
        'low_stock_threshold',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cost_price_cents' => 'integer',
        'selling_price_cents' => 'integer',
        'low_stock_threshold' => 'integer',
        'tax_category' => TaxCategory::class,
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'product_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }
}
