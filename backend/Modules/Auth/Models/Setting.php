<?php

declare(strict_types=1);

namespace Modules\Auth\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Shared\Tenancy\UsesTenantConnection;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $group
 * @property CarbonImmutable|Carbon|null $created_at
 * @property CarbonImmutable|Carbon|null $updated_at
 */
final class Setting extends Model
{
    use UsesTenantConnection;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'group',
    ];
}
