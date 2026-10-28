<?php

declare(strict_types=1);

namespace Shared\Exceptions;

use Exception;
use Shared\Utils\HttpStatus;
use Throwable;

class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message = 'An error occurred',
        protected HttpStatus $status = HttpStatus::BAD_REQUEST,
        protected string $errorCode = 'ERROR',
        protected array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status->value, $previous);
    }

    public function getStatus(): HttpStatus
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function badRequest(string $message = 'Bad request', string $errorCode = 'BAD_REQUEST', array $errors = []): static
    {
        return new static($message, HttpStatus::BAD_REQUEST, $errorCode, $errors);
    }

    public static function notFound(string $message = 'Resource not found', string $errorCode = 'NOT_FOUND'): static
    {
        return new static($message, HttpStatus::NOT_FOUND, $errorCode);
    }

    public static function unauthorized(string $message = 'Unauthenticated', string $errorCode = 'UNAUTHENTICATED'): static
    {
        return new static($message, HttpStatus::UNAUTHORIZED, $errorCode);
    }

    public static function forbidden(string $message = 'Forbidden', string $errorCode = 'FORBIDDEN'): static
    {
        return new static($message, HttpStatus::FORBIDDEN, $errorCode);
    }

    public static function conflict(string $message = 'Conflict', string $errorCode = 'CONFLICT'): static
    {
        return new static($message, HttpStatus::CONFLICT, $errorCode);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function unprocessable(string $message = 'Validation failed', string $errorCode = 'VALIDATION_FAILED', array $errors = []): static
    {
        return new static($message, HttpStatus::UNPROCESSABLE_ENTITY, $errorCode, $errors);
    }

    public static function internal(string $message = 'Internal server error', string $errorCode = 'INTERNAL_ERROR'): static
    {
        return new static($message, HttpStatus::INTERNAL_SERVER_ERROR, $errorCode);
    }
}
