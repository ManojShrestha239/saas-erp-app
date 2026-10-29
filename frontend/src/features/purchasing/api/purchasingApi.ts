import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { SupplierBill, SupplierPayableResponse } from '../types/purchasing.types';

export const purchasingApi = {
  getBills: (filters?: Record<string, unknown>): Promise<ApiSuccessResponse<SupplierBill[]>> => {
    return apiClient.get<SupplierBill[]>('/purchasing/bills', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getBill: (id: string): Promise<ApiSuccessResponse<SupplierBill>> => {
    return apiClient.get<SupplierBill>(`/purchasing/bills/${id}`);
  },

  getSupplierPayableBalance: (supplierId: string): Promise<ApiSuccessResponse<SupplierPayableResponse>> => {
    return apiClient.get<SupplierPayableResponse>(`/purchasing/suppliers/${supplierId}/payable-balance`);
  },
};
