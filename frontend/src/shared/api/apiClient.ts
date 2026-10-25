import { ApiError } from './ApiError';
import type { ApiResponse, ApiSuccessResponse } from '../types/apiResponse';

export interface RequestOptions extends Omit<RequestInit, 'body'> {
  params?: Record<string, string | number | boolean | undefined | null>;
  body?: unknown;
}

const DEFAULT_BASE_URL = '/api/v1';

function buildUrl(endpoint: string, params?: RequestOptions['params']): string {
  let url = endpoint;
  if (!url.startsWith('http://') && !url.startsWith('https://')) {
    if (!url.startsWith('/')) {
      url = `${DEFAULT_BASE_URL}/${url}`;
    } else if (!url.startsWith(DEFAULT_BASE_URL)) {
      url = `${DEFAULT_BASE_URL}${url}`;
    }
  }

  if (params) {
    const searchParams = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null) {
        searchParams.append(key, String(value));
      }
    }
    const queryString = searchParams.toString();
    if (queryString) {
      url += (url.includes('?') ? '&' : '?') + queryString;
    }
  }

  return url;
}

function getCsrfToken(): string | null {
  if (typeof document === 'undefined') {
    return null;
  }
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

export async function request<T>(
  endpoint: string,
  options: RequestOptions = {}
): Promise<ApiSuccessResponse<T>> {
  const { params, body, headers: customHeaders, ...restOptions } = options;
  const url = buildUrl(endpoint, params);

  const headers = new Headers(customHeaders);
  headers.set('Accept', 'application/json');

  const csrfToken = getCsrfToken();
  if (csrfToken && !headers.has('X-XSRF-TOKEN')) {
    headers.set('X-XSRF-TOKEN', csrfToken);
  }

  let finalBody: BodyInit | undefined;
  if (body !== undefined) {
    if (body instanceof FormData || body instanceof Blob) {
      finalBody = body;
    } else {
      headers.set('Content-Type', 'application/json');
      finalBody = JSON.stringify(body);
    }
  }

  let response: Response;
  try {
    response = await fetch(url, {
      ...restOptions,
      headers,
      body: finalBody,
    });
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : 'Network request failed';
    throw new ApiError(message, 'NETWORK_ERROR');
  }

  let parsedBody: unknown;
  const contentType = response.headers.get('content-type');
  if (contentType && contentType.includes('application/json')) {
    try {
      parsedBody = await response.json();
    } catch {
      throw new ApiError(
        'Failed to parse JSON response from server',
        'PARSE_ERROR',
        {},
        response.status
      );
    }
  } else {
    // If response is not JSON
    if (!response.ok) {
      throw new ApiError(
        response.statusText || 'Request failed',
        'HTTP_ERROR',
        {},
        response.status
      );
    }
    // Non-JSON ok response
    return {
      success: true,
      message: 'OK',
      data: undefined as unknown as T,
    };
  }

  // Type narrowing on parsedBody
  if (isApiResponse<T>(parsedBody)) {
    if (parsedBody.success) {
      return parsedBody;
    } else {
      throw new ApiError(
        parsedBody.message || 'Request failed',
        parsedBody.code || 'API_ERROR',
        parsedBody.errors || {},
        response.status
      );
    }
  }

  // Non-envelope JSON response
  if (!response.ok) {
    throw new ApiError(
      response.statusText || 'Request failed',
      'HTTP_ERROR',
      {},
      response.status
    );
  }

  return {
    success: true,
    message: 'OK',
    data: parsedBody as T,
  };
}

function isApiResponse<T>(obj: unknown): obj is ApiResponse<T> {
  if (typeof obj !== 'object' || obj === null) {
    return false;
  }
  return 'success' in obj && typeof (obj as { success: unknown }).success === 'boolean';
}

export const apiClient = {
  request,
  get: <T>(url: string, options?: RequestOptions) =>
    request<T>(url, { ...options, method: 'GET' }),
  post: <T>(url: string, body?: unknown, options?: RequestOptions) =>
    request<T>(url, { ...options, method: 'POST', body }),
  put: <T>(url: string, body?: unknown, options?: RequestOptions) =>
    request<T>(url, { ...options, method: 'PUT', body }),
  patch: <T>(url: string, body?: unknown, options?: RequestOptions) =>
    request<T>(url, { ...options, method: 'PATCH', body }),
  delete: <T>(url: string, options?: RequestOptions) =>
    request<T>(url, { ...options, method: 'DELETE' }),
};
