<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', function (): Response {
    return Inertia::render('public/LandingPage', [
        'appName' => (string) config('app.name', 'NexusERP'),
    ]);
})->name('public.landing');
