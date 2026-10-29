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
 * @property string $supplier_bill_id
 * @property string|null $purchase_order_item_id
 * @property string|null $product_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_cost_cents
 * @property int $tax_rate_percent
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupplierBill $supplierBill
 */
final class SupplierBillItem extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'supplier_bill_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'supplier_bill_id',
        'purchase_order_item_id',
        'product_id',
        'description',
        'quantity',
        'unit_cost_cents',
        'tax_rate_percent',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost_cents' => 'integer',
            'tax_rate_percent' => 'integer',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SupplierBill, $this>
     */
    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
