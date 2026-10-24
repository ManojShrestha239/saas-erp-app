<?php

declare(strict_types=1);

namespace Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function render(Throwable $e, ?Request $request = null): ?JsonResponse
    {
        $request ??= request();

        if (! ($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        if ($e instanceof ApiException) {
            return ApiResponse::error(
                message: $e->getMessage(),
                code: $e->getErrorCode(),
                errors: $e->getErrors(),
                status: $e->getStatus(),
            );
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::validationError(
                errors: $e->errors(),
                message: $e->getMessage(),
                code: 'VALIDATION_FAILED',
            );
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::unauthorized(
                message: $e->getMessage() ?: 'Unauthenticated',
                code: 'UNAUTHENTICATED',
            );
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::forbidden(
                message: $e->getMessage() ?: 'Forbidden',
                code: 'FORBIDDEN',
            );
        }

        if ($e instanceof ModelNotFoundException) {
            return ApiResponse::notFound(
                message: 'Resource not found',
                code: 'NOT_FOUND',
            );
        }

        if ($e instanceof NotFoundHttpException) {
            return ApiResponse::notFound(
                message: $e->getMessage() ?: 'Resource not found',
                code: 'NOT_FOUND',
            );
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return ApiResponse::error(
                message: $e->getMessage() ?: 'Method not allowed',
                code: 'METHOD_NOT_ALLOWED',
                status: HttpStatus::METHOD_NOT_ALLOWED,
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = HttpStatus::tryFrom($e->getStatusCode()) ?? HttpStatus::BAD_REQUEST;
            $code = match ($e->getStatusCode()) {
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                406 => 'NOT_ACCEPTABLE',
                409 => 'CONFLICT',
                415 => 'UNSUPPORTED_MEDIA_TYPE',
                422 => 'VALIDATION_FAILED',
                429 => 'TOO_MANY_REQUESTS',
                default => 'HTTP_ERROR',
            };

            return ApiResponse::error(
                message: $e->getMessage() ?: 'HTTP error',
                code: $code,
                status: $status,
            );
        }

        $isDebug = (bool) config('app.debug', false);

        return ApiResponse::error(
            message: $isDebug ? $e->getMessage() : 'Server error',
            code: 'INTERNAL_SERVER_ERROR',
            status: HttpStatus::INTERNAL_SERVER_ERROR,
        );
    }
}
