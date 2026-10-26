<?php

declare(strict_types=1);

namespace Modules\Auth\Models;

use Shared\Tenancy\UsesTenantConnection;
use Spatie\Permission\Models\Permission as SpatiePermission;

final class Permission extends SpatiePermission
{
    use UsesTenantConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
    ];
}
