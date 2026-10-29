<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $sales_invoice_id
 * @property string $product_id
 * @property string|null $description
 * @property int $quantity
 * @property int $unit_price_cents
 * @property int $tax_rate_percent
 * @property string|null $tax_category
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SalesInvoice $invoice
 */
final class SalesInvoiceItem extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'sales_invoice_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'sales_invoice_id',
        'product_id',
        'description',
        'quantity',
        'unit_price_cents',
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
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'tax_rate_percent' => 'integer',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
