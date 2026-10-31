<?php

declare(strict_types=1);

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Accounting\Enums\AccountType;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string|null $parent_id
 * @property bool $is_system
 * @property string|null $system_name
 * @property bool $is_active
 * @property string $currency
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account|null $parent
 * @property-read Collection<int, Account> $children
 */
final class Account extends Model
{
    use HasUlids;
    use UsesTenantConnection;

    protected $table = 'accounts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'code',
        'name',
        'type',
        'parent_id',
        'is_system',
        'system_name',
        'is_active',
        'currency',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => AccountType::class,
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }
}
