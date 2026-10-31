import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  GoodsReceipt,
  GoodsReceiptFormData,
  PurchaseOrder,
  PurchaseOrderFilters,
  PurchaseOrderFormData,
  SupplierBill,
  SupplierBillFilters,
  SupplierBillFormData,
  SupplierPayableResponse,
} from '../types/purchasing.types';

export const purchasingApi = {
  // Purchase Orders
  getPurchaseOrders: (filters?: PurchaseOrderFilters): Promise<ApiSuccessResponse<PurchaseOrder[]>> => {
    return apiClient.get<PurchaseOrder[]>('/purchasing/orders', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getPurchaseOrder: (id: string): Promise<ApiSuccessResponse<PurchaseOrder>> => {
    return apiClient.get<PurchaseOrder>(`/purchasing/orders/${id}`);
  },

  createPurchaseOrder: (data: PurchaseOrderFormData): Promise<ApiSuccessResponse<PurchaseOrder>> => {
    return apiClient.post<PurchaseOrder>('/purchasing/orders', data);
  },

  approvePurchaseOrder: (id: string): Promise<ApiSuccessResponse<PurchaseOrder>> => {
    return apiClient.post<PurchaseOrder>(`/purchasing/orders/${id}/approve`);
  },

  cancelPurchaseOrder: (id: string): Promise<ApiSuccessResponse<PurchaseOrder>> => {
    return apiClient.post<PurchaseOrder>(`/purchasing/orders/${id}/cancel`);
  },

  getPurchaseOrderPdfUrl: (id: string, download = false): string => {
    return `/api/v1/purchasing/orders/${id}/pdf${download ? '?download=1' : ''}`;
  },

  // Goods Receipts
  recordGoodsReceipt: (orderId: string, data: GoodsReceiptFormData): Promise<ApiSuccessResponse<GoodsReceipt>> => {
    return apiClient.post<GoodsReceipt>(`/purchasing/orders/${orderId}/receipts`, data);
  },

  // Supplier Bills
  getBills: (filters?: SupplierBillFilters): Promise<ApiSuccessResponse<SupplierBill[]>> => {
    return apiClient.get<SupplierBill[]>('/purchasing/bills', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getBill: (id: string): Promise<ApiSuccessResponse<SupplierBill>> => {
    return apiClient.get<SupplierBill>(`/purchasing/bills/${id}`);
  },

  createBill: (data: SupplierBillFormData): Promise<ApiSuccessResponse<SupplierBill>> => {
    return apiClient.post<SupplierBill>('/purchasing/bills', data);
  },

  postBill: (id: string): Promise<ApiSuccessResponse<SupplierBill>> => {
    return apiClient.post<SupplierBill>(`/purchasing/bills/${id}/post`);
  },

  getSupplierPayableBalance: (supplierId: string): Promise<ApiSuccessResponse<SupplierPayableResponse>> => {
    return apiClient.get<SupplierPayableResponse>(`/purchasing/suppliers/${supplierId}/payable-balance`);
  },
};
