<?php

declare(strict_types=1);

namespace Shared\Responses;

use Illuminate\Http\JsonResponse;
use Shared\Utils\HttpStatus;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        ?array $meta = null,
        HttpStatus|int $status = HttpStatus::OK,
        array $headers = [],
    ): JsonResponse {
        $statusCode = $status instanceof HttpStatus ? $status->value : $status;

        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $statusCode, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    public static function created(
        mixed $data = null,
        string $message = 'Created',
        ?array $meta = null,
        array $headers = [],
    ): JsonResponse {
        return self::success(
            data: $data,
            message: $message,
            meta: $meta,
            status: HttpStatus::CREATED,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        string $code = 'ERROR',
        ?array $errors = null,
        HttpStatus|int $status = HttpStatus::BAD_REQUEST,
        array $headers = [],
    ): JsonResponse {
        $statusCode = $status instanceof HttpStatus ? $status->value : $status;

        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => empty($errors) ? (object) [] : $errors,
            'code' => $code,
        ];

        return response()->json($payload, $statusCode, $headers);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function validationError(
        array $errors,
        string $message = 'Validation failed',
        string $code = 'VALIDATION_FAILED',
        array $headers = [],
    ): JsonResponse {
        return self::error(
            message: $message,
            code: $code,
            errors: $errors,
            status: HttpStatus::UNPROCESSABLE_ENTITY,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function notFound(
        string $message = 'Resource not found',
        string $code = 'NOT_FOUND',
        array $headers = [],
    ): JsonResponse {
        return self::error(
            message: $message,
            code: $code,
            status: HttpStatus::NOT_FOUND,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function unauthorized(
        string $message = 'Unauthenticated',
        string $code = 'UNAUTHENTICATED',
        array $headers = [],
    ): JsonResponse {
        return self::error(
            message: $message,
            code: $code,
            status: HttpStatus::UNAUTHORIZED,
            headers: $headers,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function forbidden(
        string $message = 'Forbidden',
        string $code = 'FORBIDDEN',
        array $headers = [],
    ): JsonResponse {
        return self::error(
            message: $message,
            code: $code,
            status: HttpStatus::FORBIDDEN,
            headers: $headers,
        );
    }
}
