import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useFcmToken } from '../useFcmToken';
import { fcmApi } from '../../api/fcmApi';

vi.mock('../../api/fcmApi', () => ({
  fcmApi: {
    registerToken: vi.fn(),
    deleteToken: vi.fn(),
  },
}));

describe('useFcmToken hook', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('successfully registers an FCM device token', async () => {
    vi.mocked(fcmApi.registerToken).mockResolvedValueOnce({
      success: true,
      message: 'FCM device token registered successfully.',
      data: {
        token: 'test-token-123',
        platform: 'web',
        device_name: 'Chrome on macOS',
        last_used_at: new Date().toISOString(),
      },
    });

    const { result } = renderHook(() => useFcmToken());

    let success = false;
    await act(async () => {
      success = await result.current.registerToken({
        token: 'test-token-123',
        platform: 'web',
        device_name: 'Chrome on macOS',
      });
    });

    expect(success).toBe(true);
    expect(result.current.error).toBeNull();
    expect(result.current.isSubmitting).toBe(false);
    expect(fcmApi.registerToken).toHaveBeenCalledWith({
      token: 'test-token-123',
      platform: 'web',
      device_name: 'Chrome on macOS',
    });
  });

  it('handles error when registration fails', async () => {
    vi.mocked(fcmApi.registerToken).mockRejectedValueOnce(
      new Error('Network error registering token')
    );

    const { result } = renderHook(() => useFcmToken());

    let success = true;
    await act(async () => {
      success = await result.current.registerToken({
        token: 'invalid-token',
      });
    });

    expect(success).toBe(false);
    expect(result.current.error).toBe('Network error registering token');
    expect(result.current.isSubmitting).toBe(false);
  });

  it('successfully unregisters an FCM device token', async () => {
    vi.mocked(fcmApi.deleteToken).mockResolvedValueOnce({
      success: true,
      message: 'FCM device token removed successfully.',
      data: null,
    });

    const { result } = renderHook(() => useFcmToken());

    let success = false;
    await act(async () => {
      success = await result.current.unregisterToken('test-token-123');
    });

    expect(success).toBe(true);
    expect(result.current.error).toBeNull();
    expect(result.current.isSubmitting).toBe(false);
    expect(fcmApi.deleteToken).toHaveBeenCalledWith('test-token-123');
  });

  it('handles error when unregistering fails', async () => {
    vi.mocked(fcmApi.deleteToken).mockRejectedValueOnce(
      new Error('Failed to delete token')
    );

    const { result } = renderHook(() => useFcmToken());

    let success = true;
    await act(async () => {
      success = await result.current.unregisterToken('test-token-123');
    });

    expect(success).toBe(false);
    expect(result.current.error).toBe('Failed to delete token');
    expect(result.current.isSubmitting).toBe(false);
  });
});
