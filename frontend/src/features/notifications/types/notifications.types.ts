export interface RegisterFcmTokenPayload {
  token: string;
  platform?: 'web' | 'android' | 'ios';
  device_name?: string;
}

export interface FcmTokenResponse {
  token: string;
  platform: string;
  device_name?: string | null;
  last_used_at?: string | null;
}
