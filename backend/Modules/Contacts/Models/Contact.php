<?php

declare(strict_types=1);

namespace Modules\Contacts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Contacts\Enums\ContactType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property ContactType $type
 * @property string $code
 * @property string $name
 * @property string|null $tax_id
 * @property string|null $email
 * @property string|null $phone
 * @property int $payment_terms_days
 * @property int $credit_limit_cents
 * @property int $opening_balance_cents
 * @property bool $is_active
 * @property string|null $notes
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $shipping_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> customers()
 * @method static Builder<static> suppliers()
 */
final class Contact extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'contacts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'code',
        'name',
        'tax_id',
        'email',
        'phone',
        'payment_terms_days',
        'credit_limit_cents',
        'opening_balance_cents',
        'is_active',
        'notes',
        'billing_address',
        'shipping_address',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => ContactType::class,
        'payment_terms_days' => 'integer',
        'credit_limit_cents' => 'integer',
        'opening_balance_cents' => 'integer',
        'is_active' => 'boolean',
        'billing_address' => 'array',
        'shipping_address' => 'array',
    ];

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->whereIn('type', [ContactType::Customer->value, ContactType::Both->value]);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->whereIn('type', [ContactType::Supplier->value, ContactType::Both->value]);
    }
}
