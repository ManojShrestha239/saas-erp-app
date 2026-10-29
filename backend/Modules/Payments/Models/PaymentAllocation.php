<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Payments\Enums\PayableType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $payment_id
 * @property PayableType $payable_type
 * @property string $payable_id
 * @property int $amount_cents
 * @property Carbon|null $allocated_at
 * @property string|null $allocated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment $payment
 */
final class PaymentAllocation extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'payment_allocations';

    protected $fillable = [
        'payment_id',
        'payable_type',
        'payable_id',
        'amount_cents',
        'allocated_at',
        'allocated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payable_type' => PayableType::class,
            'amount_cents' => 'integer',
            'allocated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
