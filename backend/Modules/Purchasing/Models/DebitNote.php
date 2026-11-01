<?php

declare(strict_types=1);

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Purchasing\Enums\DebitNoteStatus;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $debit_note_number
 * @property string $supplier_id
 * @property string|null $supplier_bill_id
 * @property string $warehouse_id
 * @property DebitNoteStatus $status
 * @property Carbon $issue_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property bool $return_stock
 * @property string $reason
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DebitNoteItem> $items
 * @property-read SupplierBill|null $bill
 */
final class DebitNote extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'debit_notes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'debit_note_number',
        'supplier_id',
        'supplier_bill_id',
        'warehouse_id',
        'status',
        'issue_date',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'return_stock',
        'reason',
        'notes',
        'created_by_user_id',
        'posted_at',
        'voided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DebitNoteStatus::class,
            'issue_date' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'return_stock' => 'boolean',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DebitNoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DebitNoteItem::class, 'debit_note_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<SupplierBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
