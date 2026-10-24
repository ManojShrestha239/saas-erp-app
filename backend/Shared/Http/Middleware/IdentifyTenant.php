<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Shared\Utils\HttpStatus;
use Symfony\Component\HttpFoundation\Response;

final class IdentifyTenant
{
    public const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $baseDomain = strtolower((string) config('app.domain', 'nexuserp.test'));
        $host = strtolower($request->getHost());

        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        // 1. Central domain: skip tenant resolution
        if ($this->isCentralDomain($host, $baseDomain)) {
            return $next($request);
        }

        // 2. Parse subdomain against APP_DOMAIN
        $subdomain = $this->extractSubdomain($request, $baseDomain);

        // 3. Look up tenant (from subdomain or custom domain host)
        $tenant = $this->resolveTenant($subdomain, $host);

        if ($tenant === null) {
            $this->abortNotFound($request);
        }

        $status = $tenant['status'] ?? '';

        if ($status === 'suspended') {
            if ($request->is('api/*') || $request->expectsJson()) {
                throw new ApiException(
                    message: 'Tenant is suspended',
                    status: HttpStatus::FORBIDDEN,
                    errorCode: 'TENANT_SUSPENDED'
                );
            }

            abort(403, 'Tenant is suspended');
        }

        if ($status === 'provisioning' || $status === 'provisioning_failed') {
            if ($request->is('api/*') || $request->expectsJson()) {
                throw new ApiException(
                    message: 'Tenant is unavailable',
                    status: HttpStatus::FORBIDDEN,
                    errorCode: 'TENANT_UNAVAILABLE'
                );
            }

            abort(403, 'Tenant is unavailable');
        }

        if ($status !== 'active') {
            $this->abortNotFound($request);
        }

        $context = new TenantContext(
            id: $tenant['id'],
            slug: $tenant['slug'],
            databaseName: $tenant['database_name'],
        );

        $this->tenantManager->switchTo($context);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenantManager->reset();
    }

    public static function cacheKey(string $identifier): string
    {
        return "tenant:{$identifier}:lookup";
    }

    public static function invalidateCache(string $identifier): void
    {
        Cache::store('redis')->forget(self::cacheKey($identifier));
    }

    public function isCentralDomain(string $host, string $baseDomain): bool
    {
        return $host === $baseDomain
            || $host === 'www.'.$baseDomain
            || $host === 'localhost'
            || $host === '127.0.0.1';
    }

    public function extractSubdomain(Request $request, string $baseDomain): ?string
    {
        $host = strtolower($request->getHost());

        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (str_ends_with($host, '.'.$baseDomain)) {
            $subdomain = substr($host, 0, -strlen('.'.$baseDomain));

            if ($subdomain !== '' && ! str_contains($subdomain, '.')) {
                return $subdomain;
            }
        }

        if (str_ends_with($host, '.localhost')) {
            $subdomain = substr($host, 0, -strlen('.localhost'));

            if ($subdomain !== '' && ! str_contains($subdomain, '.')) {
                return $subdomain;
            }
        }

        return null;
    }

    /**
     * @return array{id: string|int, slug: string, database_name: string, status: string}|null
     */
    private function resolveTenant(?string $subdomain, string $host): ?array
    {
        $lookupKey = $subdomain ?? $host;
        $cacheKey = self::cacheKey($lookupKey);

        /** @var array{id: string|int, slug: string, database_name: string, status: string}|null $cached */
        $cached = Cache::store('redis')->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $tenant = null;

        if ($subdomain !== null) {
            $tenant = $this->databaseManager->connection('central')
                ->table('tenants')
                ->where('slug', $subdomain)
                ->first(['id', 'slug', 'database_name', 'status']);
        }

        if (! $tenant) {
            $domain = $this->databaseManager->connection('central')
                ->table('domains')
                ->where('host', $host)
                ->first(['tenant_id']);

            if ($domain) {
                $tenant = $this->databaseManager->connection('central')
                    ->table('tenants')
                    ->where('id', $domain->tenant_id)
                    ->first(['id', 'slug', 'database_name', 'status']);
            }
        }

        if ($tenant === null) {
            return null;
        }

        /** @var array{id: string|int, slug: string, database_name: string, status: string} $tenantData */
        $tenantData = (array) $tenant;

        Cache::store('redis')->put($cacheKey, $tenantData, self::CACHE_TTL_SECONDS);

        return $tenantData;
    }

    /**
     * @return never
     */
    private function abortNotFound(Request $request): void
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            throw ApiException::notFound('Tenant not found', 'TENANT_NOT_FOUND');
        }

        abort(404, 'Tenant not found');
    }
}
