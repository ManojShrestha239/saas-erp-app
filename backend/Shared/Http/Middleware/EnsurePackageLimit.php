<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Services\EnforcePackageLimitService;
use Shared\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePackageLimit
{
    public function __construct(
        private readonly EnforcePackageLimitService $limitService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $tenantContext = TenantContext::get();

        if ($tenantContext === null) {
            return $next($request);
        }

        $this->limitService->ensureWithinLimit($tenantContext->id, $resource);

        return $next($request);
    }
}
