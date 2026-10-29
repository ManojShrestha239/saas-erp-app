<?php

declare(strict_types=1);

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $purchase_order_id
 * @property string $product_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property int $unit_cost_cents
 * @property int $tax_rate_percent
 * @property string|null $tax_category
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 */
final class PurchaseOrderItem extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'purchase_order_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'purchase_order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'unit_cost_cents',
        'tax_rate_percent',
        'tax_category',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost_cents' => 'integer',
            'tax_rate_percent' => 'integer',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
