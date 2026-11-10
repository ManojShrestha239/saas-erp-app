<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class AuditLogWebController
{
    public function index(): Response
    {
        return Inertia::render('audit/AuditLogIndex');
    }
}
