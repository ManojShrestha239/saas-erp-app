<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReportsWebController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if ($user === null || (! $user->can('reports.financial') && ! $user->can('reports.stock'))) {
            abort(403, 'Unauthorized to view reports.');
        }

        return Inertia::render('reports/ReportsIndex');
    }
}
