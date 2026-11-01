<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Sales\Enums\CreditNoteStatus;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $credit_note_number
 * @property string $customer_id
 * @property string|null $sales_invoice_id
 * @property string $warehouse_id
 * @property CreditNoteStatus $status
 * @property Carbon $issue_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property bool $restock
 * @property string $reason
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CreditNoteItem> $items
 * @property-read SalesInvoice|null $invoice
 */
final class CreditNote extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'credit_notes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'credit_note_number',
        'customer_id',
        'sales_invoice_id',
        'warehouse_id',
        'status',
        'issue_date',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'restock',
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
            'status' => CreditNoteStatus::class,
            'issue_date' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'restock' => 'boolean',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CreditNoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class, 'credit_note_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
