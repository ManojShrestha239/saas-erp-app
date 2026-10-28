import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { CsvDuplicateAction, CsvImportItem } from '@/shared/types/csvImport.types';
import type { GetLowStockParams, LowStockItem } from '../types/inventory.types';

export const inventoryApi = {
  getLowStock: (params?: GetLowStockParams): Promise<ApiSuccessResponse<LowStockItem[]>> => {
    return apiClient.get<LowStockItem[]>('/inventory/low-stock', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  uploadProductCsv: (
    file: File,
    duplicateAction: CsvDuplicateAction = 'skip'
  ): Promise<ApiSuccessResponse<CsvImportItem>> => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('duplicate_action', duplicateAction);

    return apiClient.post<CsvImportItem>('/inventory/products/import', formData);
  },

  getProductImportStatus: (importId: string): Promise<ApiSuccessResponse<CsvImportItem>> => {
    return apiClient.get<CsvImportItem>(`/inventory/products/import/${importId}`);
  },

  getProductTemplateUrl: (): string => {
    return '/api/v1/inventory/products/import/template';
  },
};
