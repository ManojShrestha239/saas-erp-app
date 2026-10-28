import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { GetLowStockParams, LowStockItem } from '../types/inventory.types';

export const inventoryApi = {
  getLowStock: (params?: GetLowStockParams): Promise<ApiSuccessResponse<LowStockItem[]>> => {
    return apiClient.get<LowStockItem[]>('/inventory/low-stock', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },
};
