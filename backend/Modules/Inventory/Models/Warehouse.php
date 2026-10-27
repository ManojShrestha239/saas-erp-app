<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Warehouse extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'warehouses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'code',
        'address',
        'is_default',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'warehouse_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'warehouse_id');
    }
}
