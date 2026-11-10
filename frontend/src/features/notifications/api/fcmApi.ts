import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { RegisterFcmTokenPayload, FcmTokenResponse } from '../types/notifications.types';

export const fcmApi = {
  registerToken: (
    payload: RegisterFcmTokenPayload
  ): Promise<ApiSuccessResponse<FcmTokenResponse>> => {
    return apiClient.post<FcmTokenResponse>('notifications/fcm-tokens', payload);
  },

  deleteToken: (token: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>('notifications/fcm-tokens', {
      body: { token },
    });
  },
};
