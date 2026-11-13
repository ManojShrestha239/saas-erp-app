import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { TenantSettingsDictionary } from '../types/settings.types';

export const settingsApi = {
  getSettings: (): Promise<ApiSuccessResponse<TenantSettingsDictionary>> => {
    return apiClient.get<TenantSettingsDictionary>('/settings');
  },

  updateSettings: (
    payload: TenantSettingsDictionary
  ): Promise<ApiSuccessResponse<TenantSettingsDictionary>> => {
    return apiClient.put<TenantSettingsDictionary>('/settings', payload);
  },
};
