<?php

declare(strict_types=1);

namespace Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;
use Symfony\Component\HttpFoundation\Response;

final class EnsureJsonRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader('Accept') && ! $request->accepts('application/json') && ! $request->accepts('text/event-stream')) {
            return ApiResponse::error(
                message: 'Not Acceptable. Request must accept application/json or text/event-stream.',
                code: 'NOT_ACCEPTABLE',
                status: HttpStatus::NOT_ACCEPTABLE,
            );
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            if ($request->allFiles() !== []) {
                return $next($request);
            }

            $contentType = (string) $request->header('Content-Type', '');
            $hasBody = strlen((string) $request->getContent()) > 0;

            if ($hasBody || $contentType !== '') {
                $isJson = str_contains(strtolower($contentType), 'application/json');
                $isMultipart = str_contains(strtolower($contentType), 'multipart/form-data');

                if (! $isJson && ! $isMultipart) {
                    return ApiResponse::error(
                        message: 'Unsupported Media Type. Content-Type must be application/json or multipart/form-data.',
                        code: 'UNSUPPORTED_MEDIA_TYPE',
                        status: HttpStatus::UNSUPPORTED_MEDIA_TYPE,
                    );
                }
            }
        }

        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
