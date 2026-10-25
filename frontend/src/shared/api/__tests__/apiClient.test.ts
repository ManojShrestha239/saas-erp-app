import { describe, it, expect, vi, beforeEach } from 'vitest';
import { apiClient, request } from '../apiClient';
import { ApiError } from '../ApiError';

describe('apiClient & ApiError', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  describe('Envelope parsing and error handling', () => {
    it('parses successful response envelope into typed result', async () => {
      const mockSuccessEnvelope = {
        success: true,
        message: 'Loaded successfully',
        data: { id: 42, name: 'Acme Product' },
        meta: { page: 1, per_page: 25, total: 1 },
      };

      vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
        new Response(JSON.stringify(mockSuccessEnvelope), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      );

      const result = await apiClient.get<{ id: number; name: string }>('/products/42');

      expect(result.success).toBe(true);
      expect(result.message).toBe('Loaded successfully');
      expect(result.data).toEqual({ id: 42, name: 'Acme Product' });
      expect(result.meta).toEqual({ page: 1, per_page: 25, total: 1 });
    });

    it('parses error response envelope into ApiError with code, message, and field errors', async () => {
      const mockErrorEnvelope = {
        success: false,
        message: 'The given data was invalid.',
        errors: {
          email: ['The email field is required.', 'The email must be a valid email address.'],
          slug: ['The slug has already been taken.'],
        },
        code: 'VALIDATION_FAILED',
      };

      vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
        new Response(JSON.stringify(mockErrorEnvelope), {
          status: 422,
          headers: { 'Content-Type': 'application/json' },
        })
      );

      await expect(
        apiClient.post('/tenants', { name: 'Acme' })
      ).rejects.toThrow(ApiError);

      // Verify specific properties
      vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
        new Response(JSON.stringify(mockErrorEnvelope), {
          status: 422,
          headers: { 'Content-Type': 'application/json' },
        })
      );

      try {
        await apiClient.post('/tenants', { name: 'Acme' });
        expect.unreachable('Should have thrown ApiError');
      } catch (err: unknown) {
        expect(err).toBeInstanceOf(ApiError);
        const apiError = err as ApiError;
        expect(apiError.name).toBe('ApiError');
        expect(apiError.code).toBe('VALIDATION_FAILED');
        expect(apiError.message).toBe('The given data was invalid.');
        expect(apiError.status).toBe(422);
        expect(apiError.errors).toEqual({
          email: ['The email field is required.', 'The email must be a valid email address.'],
          slug: ['The slug has already been taken.'],
        });
        expect(apiError.getFirstError('email')).toBe('The email field is required.');
        expect(apiError.hasFieldError('slug')).toBe(true);
        expect(apiError.hasFieldError('nonexistent')).toBe(false);
      }
    });

    it('handles non-JSON HTTP errors by throwing ApiError with HTTP_ERROR code', async () => {
      vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
        new Response('Internal Server Error HTML', {
          status: 500,
          statusText: 'Internal Server Error',
          headers: { 'Content-Type': 'text/html' },
        })
      );

      try {
        await request('/broken-route');
        expect.unreachable('Should have thrown ApiError');
      } catch (err: unknown) {
        expect(err).toBeInstanceOf(ApiError);
        const apiError = err as ApiError;
        expect(apiError.code).toBe('HTTP_ERROR');
        expect(apiError.status).toBe(500);
        expect(apiError.message).toBe('Internal Server Error');
      }
    });

    it('handles network failure by throwing ApiError with NETWORK_ERROR code', async () => {
      vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(
        new Error('Failed to fetch')
      );

      try {
        await apiClient.get('/network-down');
        expect.unreachable('Should have thrown ApiError');
      } catch (err: unknown) {
        expect(err).toBeInstanceOf(ApiError);
        const apiError = err as ApiError;
        expect(apiError.code).toBe('NETWORK_ERROR');
        expect(apiError.message).toBe('Failed to fetch');
      }
    });
  });

  describe('HTTP helper methods', () => {
    it('sends correct HTTP method and serializes JSON body', async () => {
      let capturedRequest: { url: string; method?: string; body?: string } | null = null;

      vi.spyOn(globalThis, 'fetch').mockImplementationOnce(async (url, init) => {
        capturedRequest = {
          url: String(url),
          method: init?.method,
          body: init?.body as string,
        };
        return new Response(
          JSON.stringify({ success: true, message: 'Created', data: { id: 1 } }),
          {
            status: 201,
            headers: { 'Content-Type': 'application/json' },
          }
        );
      });

      const payload = { name: 'New Item' };
      await apiClient.post('/items', payload);

      expect(capturedRequest).not.toBeNull();
      expect(capturedRequest!.method).toBe('POST');
      expect(capturedRequest!.url).toBe('/api/v1/items');
      expect(JSON.parse(capturedRequest!.body!)).toEqual(payload);
    });

    it('appends query parameters correctly to url', async () => {
      let capturedUrl = '';

      vi.spyOn(globalThis, 'fetch').mockImplementationOnce(async (url) => {
        capturedUrl = String(url);
        return new Response(
          JSON.stringify({ success: true, message: 'OK', data: [] }),
          {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          }
        );
      });

      await apiClient.get('/items', {
        params: { page: 2, search: 'widget', active: true },
      });

      expect(capturedUrl).toBe('/api/v1/items?page=2&search=widget&active=true');
    });
  });
});
