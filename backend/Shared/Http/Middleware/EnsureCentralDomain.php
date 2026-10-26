<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Shared\Exceptions\ApiException;
use Shared\Utils\HttpStatus;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralDomain
{
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

        if (! $this->isCentralDomain($host, $baseDomain)) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                throw new ApiException(
                    message: 'Super Admin routes are only accessible from the central platform domain.',
                    status: HttpStatus::NOT_FOUND,
                    errorCode: 'ROUTE_NOT_FOUND',
                );
            }

            abort(404);
        }

        return $next($request);
    }

    private function isCentralDomain(string $host, string $baseDomain): bool
    {
        return $host === $baseDomain
            || $host === 'www.'.$baseDomain
            || $host === 'localhost'
            || $host === '127.0.0.1';
    }
}
