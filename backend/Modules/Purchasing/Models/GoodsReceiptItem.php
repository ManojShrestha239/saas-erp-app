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
 * @property string $goods_receipt_id
 * @property string $purchase_order_item_id
 * @property string $product_id
 * @property int $quantity_received
 * @property int $unit_cost_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GoodsReceipt $goodsReceipt
 * @property-read PurchaseOrderItem $purchaseOrderItem
 */
final class GoodsReceiptItem extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'goods_receipt_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'quantity_received',
        'unit_cost_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'unit_cost_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderItem, $this>
     */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
