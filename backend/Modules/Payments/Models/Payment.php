<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Payments\Enums\PaymentMethod;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\PaymentType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $payment_number
 * @property PaymentType $type
 * @property string $contact_id
 * @property int $amount_cents
 * @property int $allocated_amount_cents
 * @property int $unallocated_amount_cents
 * @property string $currency
 * @property PaymentMethod $payment_method
 * @property string $payment_date
 * @property string|null $reference_number
 * @property string|null $notes
 * @property PaymentStatus $status
 * @property string|null $created_by_user_id
 * @property string|null $posted_by_user_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PaymentAllocation> $allocations
 */
final class Payment extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'payments';

    protected $fillable = [
        'payment_number',
        'type',
        'contact_id',
        'amount_cents',
        'allocated_amount_cents',
        'unallocated_amount_cents',
        'currency',
        'payment_method',
        'payment_date',
        'reference_number',
        'notes',
        'status',
        'created_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'payment_method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'allocated_amount_cents' => 'integer',
            'unallocated_amount_cents' => 'integer',
            'payment_date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }
}
