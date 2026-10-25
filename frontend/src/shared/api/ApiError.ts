export class ApiError extends Error {
  readonly code: string;
  readonly errors: Record<string, string[]>;
  readonly status?: number;

  constructor(
    message: string,
    code = 'UNKNOWN_ERROR',
    errors: Record<string, string[]> = {},
    status?: number
  ) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.errors = errors;
    this.status = status;

    // Restore prototype chain when transpiled
    Object.setPrototypeOf(this, ApiError.prototype);
  }

  getFirstError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }

  hasFieldError(field: string): boolean {
    const fieldErrors = this.errors[field];
    return fieldErrors !== undefined && fieldErrors.length > 0;
  }
}
