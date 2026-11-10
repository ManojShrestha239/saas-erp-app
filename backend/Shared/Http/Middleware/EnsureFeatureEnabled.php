<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Services\TenantPackageService;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFeatureEnabled
{
    public function __construct(
        private readonly TenantPackageService $tenantPackageService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenantContext = TenantContext::get();

        if ($tenantContext !== null) {
            $hasFeature = $this->tenantPackageService->hasFeature($tenantContext->id, $feature);

            if (! $hasFeature) {
                throw ApiException::forbidden(
                    sprintf("Feature '%s' is not enabled or not included in your subscription.", $feature),
                    'FEATURE_DISABLED'
                );
            }

            return $next($request);
        }

        // Outside tenant context (e.g. central domain / platform features)
        $isGloballyEnabled = $this->tenantPackageService->isGlobalFeatureEnabled($feature);

        if ($isGloballyEnabled !== true) {
            throw ApiException::forbidden(
                sprintf("Feature '%s' is currently disabled.", $feature),
                'FEATURE_DISABLED'
            );
        }

        return $next($request);
    }
}
