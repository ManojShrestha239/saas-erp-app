<?php

declare(strict_types=1);

namespace Modules\Auth\Models;

use Shared\Tenancy\UsesTenantConnection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $display_name
 * @property string|null $description
 */
final class Role extends SpatieRole
{
    use UsesTenantConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'description',
    ];
}
