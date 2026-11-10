<?php

declare(strict_types=1);

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Shared\Audit\LogsAuditableActivity;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $bill_number
 * @property string|null $supplier_invoice_number
 * @property string $supplier_id
 * @property string|null $purchase_order_id
 * @property Carbon $bill_date
 * @property Carbon $due_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property int $amount_due_cents
 * @property int $amount_paid_cents
 * @property SupplierBillStatus $status
 * @property Carbon|null $posted_at
 * @property string|null $posted_by_user_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read Collection<int, SupplierBillItem> $items
 * @property-read Collection<int, DebitNote> $debitNotes
 */
final class SupplierBill extends Model
{
    use HasUlids;
    use LogsAuditableActivity;
    use UsesTenantConnection;

    protected $table = 'supplier_bills';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'bill_number',
        'supplier_invoice_number',
        'supplier_id',
        'purchase_order_id',
        'bill_date',
        'due_date',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'amount_due_cents',
        'amount_paid_cents',
        'status',
        'posted_at',
        'posted_by_user_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierBillStatus::class,
            'bill_date' => 'date',
            'due_date' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_due_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'posted_at' => 'datetime',
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
     * @return HasMany<SupplierBillItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierBillItem::class, 'supplier_bill_id');
    }

    /**
     * @return HasMany<DebitNote, $this>
     */
    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class, 'supplier_bill_id')->orderBy('created_at');
    }
}
