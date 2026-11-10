import { useState, useCallback } from 'react';
import { fcmApi } from '../api/fcmApi';
import type { RegisterFcmTokenPayload } from '../types/notifications.types';

export function useFcmToken() {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const registerToken = useCallback(
    async (payload: RegisterFcmTokenPayload): Promise<boolean> => {
      setIsSubmitting(true);
      setError(null);
      try {
        await fcmApi.registerToken(payload);
        return true;
      } catch (err) {
        const message = err instanceof Error ? err.message : 'Failed to register device token';
        setError(message);
        return false;
      } finally {
        setIsSubmitting(false);
      }
    },
    []
  );

  const unregisterToken = useCallback(async (token: string): Promise<boolean> => {
    setIsSubmitting(true);
    setError(null);
    try {
      await fcmApi.deleteToken(token);
      return true;
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to unregister device token';
      setError(message);
      return false;
    } finally {
      setIsSubmitting(false);
    }
  }, []);

  return {
    registerToken,
    unregisterToken,
    isSubmitting,
    error,
  };
}
