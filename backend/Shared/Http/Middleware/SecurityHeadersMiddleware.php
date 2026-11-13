<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        // Prevent MIME type sniffing
        $headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking / framing
        $headers->set('X-Frame-Options', 'DENY');

        // Modern XSS auditor disablement (OWASP recommended)
        $headers->set('X-XSS-Protection', '0');

        // Referrer policy
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser features
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Content Security Policy
        if (! $headers->has('Content-Security-Policy')) {
            $scriptSources = "'self' 'unsafe-inline' 'unsafe-eval'";
            $connectSources = "'self'";
            $styleSources = "'self' 'unsafe-inline'";
            $fontSources = "'self' data:";

            if (app()->environment('local', 'testing')) {
                $scriptSources .= ' http://localhost:5173';
                $connectSources .= ' http://localhost:5173 ws://localhost:5173';
                $styleSources .= ' https://fonts.googleapis.com';
                $fontSources .= ' https://fonts.gstatic.com';
            }

            $headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src {$scriptSources}; style-src {$styleSources}; img-src 'self' data: blob:; font-src {$fontSources}; connect-src {$connectSources}; frame-ancestors 'none';"
            );
        }

        // HSTS when connection is HTTPS
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
