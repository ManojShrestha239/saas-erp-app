<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Shared\Exceptions\ApiExceptionRenderer;
use Shared\Http\Middleware\EnsureCentralDomain;
use Shared\Http\Middleware\EnsureJsonRequest;
use Shared\Http\Middleware\HandleInertiaRequests;
use Shared\Http\Middleware\IdentifyTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->api(prepend: [
            EnsureJsonRequest::class,
        ]);

        $middleware->alias([
            'ensure.json' => EnsureJsonRequest::class,
            'tenant' => IdentifyTenant::class,
            'identify.tenant' => IdentifyTenant::class,
            'central.domain' => EnsureCentralDomain::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            return app(ApiExceptionRenderer::class)->render($e, $request);
        });
    })->create();
