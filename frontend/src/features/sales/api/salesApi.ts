import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  SalesQuotation,
  SalesInvoice,
  CreateQuotationFormData,
  CreateInvoiceFormData,
  SalesFilters,
} from '../types/sales.types';

export interface ConvertQuotationPayload {
  warehouse_id?: string;
  due_date?: string;
  post_immediately?: boolean;
}

export interface CustomerReceivableResponse {
  customer_id: string;
  outstanding_receivable_cents: number;
}

export const salesApi = {
  // Quotations
  getQuotations: (filters?: SalesFilters): Promise<ApiSuccessResponse<SalesQuotation[]>> => {
    return apiClient.get<SalesQuotation[]>('/sales/quotations', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getQuotation: (id: string): Promise<ApiSuccessResponse<SalesQuotation>> => {
    return apiClient.get<SalesQuotation>(`/sales/quotations/${id}`);
  },

  getQuotationPdfUrl: (id: string, download = false): string => {
    return `/api/v1/sales/quotations/${id}/pdf${download ? '?download=1' : ''}`;
  },

  createQuotation: (data: CreateQuotationFormData): Promise<ApiSuccessResponse<SalesQuotation>> => {
    return apiClient.post<SalesQuotation>('/sales/quotations', data);
  },

  sendQuotation: (id: string): Promise<ApiSuccessResponse<SalesQuotation>> => {
    return apiClient.post<SalesQuotation>(`/sales/quotations/${id}/send`);
  },

  acceptQuotation: (id: string): Promise<ApiSuccessResponse<SalesQuotation>> => {
    return apiClient.post<SalesQuotation>(`/sales/quotations/${id}/accept`);
  },

  rejectQuotation: (id: string): Promise<ApiSuccessResponse<SalesQuotation>> => {
    return apiClient.post<SalesQuotation>(`/sales/quotations/${id}/reject`);
  },

  convertQuotation: (
    id: string,
    payload?: ConvertQuotationPayload
  ): Promise<ApiSuccessResponse<SalesInvoice>> => {
    return apiClient.post<SalesInvoice>(`/sales/quotations/${id}/convert`, payload);
  },

  // Invoices
  getInvoices: (filters?: SalesFilters): Promise<ApiSuccessResponse<SalesInvoice[]>> => {
    return apiClient.get<SalesInvoice[]>('/sales/invoices', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getInvoice: (id: string): Promise<ApiSuccessResponse<SalesInvoice>> => {
    return apiClient.get<SalesInvoice>(`/sales/invoices/${id}`);
  },

  getInvoicePdfUrl: (id: string, download = false): string => {
    return `/api/v1/sales/invoices/${id}/pdf${download ? '?download=1' : ''}`;
  },

  createInvoice: (data: CreateInvoiceFormData): Promise<ApiSuccessResponse<SalesInvoice>> => {
    return apiClient.post<SalesInvoice>('/sales/invoices', data);
  },

  updateInvoice: (
    id: string,
    data: Partial<CreateInvoiceFormData>
  ): Promise<ApiSuccessResponse<SalesInvoice>> => {
    return apiClient.put<SalesInvoice>(`/sales/invoices/${id}`, data);
  },

  deleteInvoice: (id: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/sales/invoices/${id}`);
  },

  postInvoice: (id: string): Promise<ApiSuccessResponse<SalesInvoice>> => {
    return apiClient.post<SalesInvoice>(`/sales/invoices/${id}/post`);
  },

  voidInvoice: (id: string): Promise<ApiSuccessResponse<SalesInvoice>> => {
    return apiClient.post<SalesInvoice>(`/sales/invoices/${id}/void`);
  },

  getCustomerReceivableBalance: (
    customerId: string
  ): Promise<ApiSuccessResponse<CustomerReceivableResponse>> => {
    return apiClient.get<CustomerReceivableResponse>(
      `/sales/customers/${customerId}/receivable-balance`
    );
  },
};
