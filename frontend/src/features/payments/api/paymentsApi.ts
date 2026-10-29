import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  Payment,
  PaymentFilters,
  CreatePaymentPayload,
  AllocatePaymentPayload,
} from '../types/payments.types';

export interface UnallocatedCreditResponse {
  contact_id: string;
  type: string;
  unallocated_credit_cents: number;
}

export const paymentsApi = {
  getPayments: (filters?: PaymentFilters): Promise<ApiSuccessResponse<Payment[]>> => {
    return apiClient.get<Payment[]>('/payments', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getPayment: (id: string): Promise<ApiSuccessResponse<Payment>> => {
    return apiClient.get<Payment>(`/payments/${id}`);
  },

  createPayment: (data: CreatePaymentPayload): Promise<ApiSuccessResponse<Payment>> => {
    return apiClient.post<Payment>('/payments', data);
  },

  postPayment: (id: string): Promise<ApiSuccessResponse<Payment>> => {
    return apiClient.post<Payment>(`/payments/${id}/post`);
  },

  allocatePayment: (
    id: string,
    payload: AllocatePaymentPayload
  ): Promise<ApiSuccessResponse<Payment>> => {
    return apiClient.post<Payment>(`/payments/${id}/allocate`, payload);
  },

  getUnallocatedCredit: (
    contactId: string,
    type: 'inbound' | 'outbound'
  ): Promise<ApiSuccessResponse<UnallocatedCreditResponse>> => {
    return apiClient.get<UnallocatedCreditResponse>(
      `/payments/contacts/${contactId}/unallocated-credit`,
      {
        params: { type },
      }
    );
  },
};
