<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $max_users
 * @property int $max_warehouses
 * @property int $max_invoices_per_month
 * @property array<string, mixed> $features
 * @property int $price_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Package extends Model
{
    /**
     * @var string
     */
    protected $connection = 'central';

    /**
     * @var string
     */
    protected $table = 'packages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'max_users',
        'max_warehouses',
        'max_invoices_per_month',
        'features',
        'price_cents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_users' => 'integer',
            'max_warehouses' => 'integer',
            'max_invoices_per_month' => 'integer',
            'features' => 'array',
            'price_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'package_id');
    }
}
