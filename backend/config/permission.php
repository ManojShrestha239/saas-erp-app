<?php

declare(strict_types=1);

use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\TenantUser;
use Spatie\Permission\DefaultTeamResolver;

return [

    'models' => [

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your permissions.
         */

        'permission' => Permission::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your roles.
         */

        'role' => Role::class,

        /*
         * When using the "Teams" feature from this package, we need to know which
         * Eloquent model should be used to retrieve your teams.
         */
        'team' => null,

        /*
         * When using the "HasModels" trait and passing raw IDs to syncModels,
         * attachModels, or detachModels, this model class will be used to
         * resolve those IDs. If null, defaults to the guard's model.
         */
        'default_model' => TenantUser::class,
    ],

    'table_names' => [

        'roles' => 'roles',

        'permissions' => 'permissions',

        'model_has_permissions' => 'model_has_permissions',

        'model_has_roles' => 'model_has_roles',

        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null, // default 'role_id',
        'permission_pivot_key' => null, // default 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    /*
     * When set to true, the method for checking permissions will be registered on the gate.
     */
    'register_permission_check_method' => true,

    'register_octane_reset_listener' => false,

    'events_enabled' => false,

    'teams' => false,

    'team_resolver' => DefaultTeamResolver::class,

    'use_passport_client_credentials' => false,

    'display_permission_in_exception' => false,

    'display_role_in_exception' => false,

    'enable_wildcard_permission' => false,

    /* Cache-specific settings */
    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
