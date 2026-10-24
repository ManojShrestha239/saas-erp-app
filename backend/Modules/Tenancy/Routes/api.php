<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\RegisterTenantController;

/*
|--------------------------------------------------------------------------
| Tenancy Module API Routes
|--------------------------------------------------------------------------
|
| These routes run on the central domain (no tenant middleware).
| The prefix /api/v1 and 'api' middleware are applied by ModuleServiceProvider.
|
*/

Route::post('/tenants', RegisterTenantController::class)
    ->middleware('throttle:5,1')
    ->name('tenants.register');
