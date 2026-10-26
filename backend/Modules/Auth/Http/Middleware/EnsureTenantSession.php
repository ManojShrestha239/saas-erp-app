<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Shared\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->has('tenant_id')) {
            $sessionTenantId = (string) $request->session()->get('tenant_id');
            $currentTenant = TenantContext::get();
            $currentTenantId = $currentTenant !== null ? (string) $currentTenant->id : null;

            if ($currentTenantId === null || $sessionTenantId !== $currentTenantId) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw new AuthenticationException('Unauthenticated.');
            }

            /** @var Authenticatable|null $user */
            $user = Auth::guard('web')->user();
            if ($user !== null) {
                $storedHash = $request->session()->get('auth_password_hash');
                if (! is_string($storedHash) || $storedHash !== $user->getAuthPassword()) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    throw new AuthenticationException('Unauthenticated.');
                }
            }
        }

        return $next($request);
    }
}
