import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  ApAgingData,
  ArAgingData,
  BalanceSheetData,
  CreateReportExportRequest,
  ProfitAndLossData,
  ReportExportResponse,
  StockMovementData,
  StockValuationData,
  TrialBalanceData,
} from '../types/reports.types';

export const reportsApi = {
  getTrialBalance: (params?: {
    as_of_date?: string;
    include_zero_balances?: boolean;
  }): Promise<ApiSuccessResponse<TrialBalanceData>> => {
    return apiClient.get<TrialBalanceData>('/reports/trial-balance', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getProfitAndLoss: (params?: {
    start_date?: string;
    end_date?: string;
  }): Promise<ApiSuccessResponse<ProfitAndLossData>> => {
    return apiClient.get<ProfitAndLossData>('/reports/profit-and-loss', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getBalanceSheet: (params?: {
    as_of_date?: string;
  }): Promise<ApiSuccessResponse<BalanceSheetData>> => {
    return apiClient.get<BalanceSheetData>('/reports/balance-sheet', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getArAging: (params?: {
    as_of_date?: string;
    customer_id?: string;
  }): Promise<ApiSuccessResponse<ArAgingData>> => {
    return apiClient.get<ArAgingData>('/reports/ar-aging', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getApAging: (params?: {
    as_of_date?: string;
    supplier_id?: string;
  }): Promise<ApiSuccessResponse<ApAgingData>> => {
    return apiClient.get<ApAgingData>('/reports/ap-aging', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getStockValuation: (params?: {
    as_of_date?: string;
    warehouse_id?: string;
    category_id?: string;
    search?: string;
    page?: number;
    per_page?: number;
    sort_field?: string;
    sort_direction?: 'asc' | 'desc';
  }): Promise<ApiSuccessResponse<StockValuationData>> => {
    return apiClient.get<StockValuationData>('/reports/stock-valuation', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getStockMovements: (params?: {
    warehouse_id?: string;
    product_id?: string;
    type?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
    sort_field?: string;
    sort_direction?: 'asc' | 'desc';
  }): Promise<ApiSuccessResponse<StockMovementData>> => {
    return apiClient.get<StockMovementData>('/reports/stock-movements', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  requestExport: (payload: CreateReportExportRequest): Promise<ApiSuccessResponse<ReportExportResponse>> => {
    return apiClient.post<ReportExportResponse>('/reports/exports', payload);
  },

  getExportStatus: (id: string): Promise<ApiSuccessResponse<ReportExportResponse>> => {
    return apiClient.get<ReportExportResponse>(`/reports/exports/${id}/status`);
  },
};
