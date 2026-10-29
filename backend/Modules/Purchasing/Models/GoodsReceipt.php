<?php

declare(strict_types=1);

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $receipt_number
 * @property string $purchase_order_id
 * @property string $supplier_id
 * @property string $warehouse_id
 * @property Carbon $received_date
 * @property string|null $notes
 * @property string|null $received_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Collection<int, GoodsReceiptItem> $items
 */
final class GoodsReceipt extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'goods_receipts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'receipt_number',
        'purchase_order_id',
        'supplier_id',
        'warehouse_id',
        'received_date',
        'notes',
        'received_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<GoodsReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }
}
