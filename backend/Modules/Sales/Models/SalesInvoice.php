<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $invoice_number
 * @property string|null $sales_quotation_id
 * @property string $customer_id
 * @property string|null $warehouse_id
 * @property SalesInvoiceStatus $status
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property int $amount_paid_cents
 * @property int $amount_due_cents
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property string|null $posted_by_user_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SalesInvoiceItem> $items
 * @property-read SalesQuotation|null $quotation
 * @property-read Collection<int, CreditNote> $creditNotes
 */
final class SalesInvoice extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'sales_invoices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'invoice_number',
        'sales_quotation_id',
        'customer_id',
        'warehouse_id',
        'status',
        'issue_date',
        'due_date',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'amount_paid_cents',
        'amount_due_cents',
        'notes',
        'created_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'voided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesInvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'amount_due_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SalesInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'sales_invoice_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<SalesQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    /**
     * @return HasMany<CreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'sales_invoice_id')->orderBy('created_at');
    }
}
