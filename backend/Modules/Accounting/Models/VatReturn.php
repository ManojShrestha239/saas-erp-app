<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\VatReturnStatus;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $return_number
 * @property string|null $fiscal_period_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property VatReturnStatus $status
 * @property int $sales_taxable_cents
 * @property int $output_vat_cents
 * @property int $purchases_taxable_cents
 * @property int $input_vat_cents
 * @property int $net_vat_cents
 * @property array<string, mixed> $rate_breakdown
 * @property bool $is_reconciled
 * @property int $gl_output_vat_cents
 * @property int $gl_input_vat_cents
 * @property int $gl_discrepancy_cents
 * @property string|null $filed_by_user_id
 * @property Carbon|null $filed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FiscalPeriod|null $fiscalPeriod
 */
final class VatReturn extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'vat_returns';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'return_number',
        'fiscal_period_id',
        'from_date',
        'to_date',
        'status',
        'sales_taxable_cents',
        'output_vat_cents',
        'purchases_taxable_cents',
        'input_vat_cents',
        'net_vat_cents',
        'rate_breakdown',
        'is_reconciled',
        'gl_output_vat_cents',
        'gl_input_vat_cents',
        'gl_discrepancy_cents',
        'filed_by_user_id',
        'filed_at',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'status' => VatReturnStatus::class,
        'sales_taxable_cents' => 'integer',
        'output_vat_cents' => 'integer',
        'purchases_taxable_cents' => 'integer',
        'input_vat_cents' => 'integer',
        'net_vat_cents' => 'integer',
        'rate_breakdown' => 'array',
        'is_reconciled' => 'boolean',
        'gl_output_vat_cents' => 'integer',
        'gl_input_vat_cents' => 'integer',
        'gl_discrepancy_cents' => 'integer',
        'filed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $model): void {
            if ($model->getOriginal('status') === VatReturnStatus::Filed) {
                throw ApiException::badRequest(
                    'Filed VAT returns are immutable and cannot be modified.',
                    'FILED_VAT_RETURN_IMMUTABLE'
                );
            }
        });

        self::deleting(function (self $model): void {
            if ($model->status === VatReturnStatus::Filed) {
                throw ApiException::badRequest(
                    'Filed VAT returns cannot be deleted.',
                    'FILED_VAT_RETURN_CANNOT_BE_DELETED'
                );
            }
        });
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function isFiled(): bool
    {
        return $this->status === VatReturnStatus::Filed;
    }
}
