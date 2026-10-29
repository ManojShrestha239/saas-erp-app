<?php

declare(strict_types=1);

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $order_number
 * @property string $supplier_id
 * @property string $warehouse_id
 * @property PurchaseOrderStatus $status
 * @property Carbon $order_date
 * @property Carbon|null $expected_delivery_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property string|null $approved_by_user_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PurchaseOrderItem> $items
 * @property-read Collection<int, GoodsReceipt> $goodsReceipts
 * @property-read Collection<int, SupplierBill> $bills
 */
final class PurchaseOrder extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'purchase_orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'order_number',
        'supplier_id',
        'warehouse_id',
        'status',
        'order_date',
        'expected_delivery_date',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'notes',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<SupplierBill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class, 'purchase_order_id');
    }
}
