<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Sales\Enums\SalesQuotationStatus;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $quotation_number
 * @property string $customer_id
 * @property string|null $warehouse_id
 * @property SalesQuotationStatus $status
 * @property Carbon $issue_date
 * @property Carbon|null $expiry_date
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string|null $notes
 * @property string|null $created_by_user_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property string|null $converted_to_invoice_id
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SalesQuotationItem> $items
 */
final class SalesQuotation extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'sales_quotations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'quotation_number',
        'customer_id',
        'warehouse_id',
        'status',
        'issue_date',
        'expiry_date',
        'currency',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'notes',
        'created_by_user_id',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'converted_to_invoice_id',
        'converted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesQuotationStatus::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SalesQuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationItem::class, 'sales_quotation_id')->orderBy('created_at');
    }
}
