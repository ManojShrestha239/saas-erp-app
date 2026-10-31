import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { CsvDuplicateAction, CsvImportItem } from '@/shared/types/csvImport.types';
import type {
  CategoryFormData,
  CategoryItem,
  GetLowStockParams,
  LowStockItem,
  ProductFilters,
  ProductFormData,
  ProductItem,
  StockAdjustmentFilters,
  StockAdjustmentFormData,
  StockAdjustmentItem,
  StockLevelFilters,
  StockLevelItem,
  UnitOfMeasureFormData,
  UnitOfMeasureItem,
  WarehouseFormData,
  WarehouseItem,
} from '../types/inventory.types';

export const inventoryApi = {
  // Products
  getProducts: (params?: ProductFilters): Promise<ApiSuccessResponse<ProductItem[]>> => {
    return apiClient.get<ProductItem[]>('/inventory/products', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getProduct: (id: string): Promise<ApiSuccessResponse<ProductItem>> => {
    return apiClient.get<ProductItem>(`/inventory/products/${id}`);
  },

  createProduct: (data: ProductFormData): Promise<ApiSuccessResponse<ProductItem>> => {
    return apiClient.post<ProductItem>('/inventory/products', data);
  },

  updateProduct: (id: string, data: Partial<ProductFormData>): Promise<ApiSuccessResponse<ProductItem>> => {
    return apiClient.put<ProductItem>(`/inventory/products/${id}`, data);
  },

  deleteProduct: (id: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/inventory/products/${id}`);
  },

  // Categories
  getCategories: (params?: { search?: string; is_active?: boolean; per_page?: number }): Promise<ApiSuccessResponse<CategoryItem[]>> => {
    return apiClient.get<CategoryItem[]>('/inventory/categories', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  createCategory: (data: CategoryFormData): Promise<ApiSuccessResponse<CategoryItem>> => {
    return apiClient.post<CategoryItem>('/inventory/categories', data);
  },

  updateCategory: (id: string, data: Partial<CategoryFormData>): Promise<ApiSuccessResponse<CategoryItem>> => {
    return apiClient.put<CategoryItem>(`/inventory/categories/${id}`, data);
  },

  deleteCategory: (id: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/inventory/categories/${id}`);
  },

  // Units of Measure
  getUnitsOfMeasure: (params?: { search?: string; is_active?: boolean; per_page?: number }): Promise<ApiSuccessResponse<UnitOfMeasureItem[]>> => {
    return apiClient.get<UnitOfMeasureItem[]>('/inventory/units-of-measure', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  createUnitOfMeasure: (data: UnitOfMeasureFormData): Promise<ApiSuccessResponse<UnitOfMeasureItem>> => {
    return apiClient.post<UnitOfMeasureItem>('/inventory/units-of-measure', data);
  },

  // Warehouses
  getWarehouses: (params?: { search?: string; is_active?: boolean; per_page?: number }): Promise<ApiSuccessResponse<WarehouseItem[]>> => {
    return apiClient.get<WarehouseItem[]>('/inventory/warehouses', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  createWarehouse: (data: WarehouseFormData): Promise<ApiSuccessResponse<WarehouseItem>> => {
    return apiClient.post<WarehouseItem>('/inventory/warehouses', data);
  },

  updateWarehouse: (id: string, data: Partial<WarehouseFormData>): Promise<ApiSuccessResponse<WarehouseItem>> => {
    return apiClient.put<WarehouseItem>(`/inventory/warehouses/${id}`, data);
  },

  deleteWarehouse: (id: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/inventory/warehouses/${id}`);
  },

  // Stock Levels
  getStockLevels: (params?: StockLevelFilters): Promise<ApiSuccessResponse<StockLevelItem[]>> => {
    return apiClient.get<StockLevelItem[]>('/inventory/stock-levels', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getLowStock: (params?: GetLowStockParams): Promise<ApiSuccessResponse<LowStockItem[]>> => {
    return apiClient.get<LowStockItem[]>('/inventory/low-stock', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  // Stock Adjustments
  getStockAdjustments: (params?: StockAdjustmentFilters): Promise<ApiSuccessResponse<StockAdjustmentItem[]>> => {
    return apiClient.get<StockAdjustmentItem[]>('/inventory/adjustments', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getStockAdjustment: (id: string): Promise<ApiSuccessResponse<StockAdjustmentItem>> => {
    return apiClient.get<StockAdjustmentItem>(`/inventory/adjustments/${id}`);
  },

  createStockAdjustment: (data: StockAdjustmentFormData): Promise<ApiSuccessResponse<StockAdjustmentItem>> => {
    return apiClient.post<StockAdjustmentItem>('/inventory/adjustments', data);
  },

  // CSV Import
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
