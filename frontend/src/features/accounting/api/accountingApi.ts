import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  AccountItem,
  FiscalPeriodItem,
  JournalEntryItem,
  CreateFiscalPeriodPayload,
  GenerateFiscalYearPayload,
  CreateJournalEntryPayload,
  UpdateJournalEntryPayload,
  RejectJournalEntryPayload,
  ReverseJournalEntryPayload,
} from '../types/accounting.types';

export const accountingApi = {
  // Accounts (Chart of Accounts)
  getAccounts: (params?: { type?: string; is_active?: boolean; search?: string; all?: boolean; per_page?: number }): Promise<ApiSuccessResponse<AccountItem[]>> => {
    return apiClient.get<AccountItem[]>('/accounting/accounts', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getAccountTree: (type?: string): Promise<ApiSuccessResponse<AccountItem[]>> => {
    return apiClient.get<AccountItem[]>('/accounting/accounts', {
      params: { tree: true, ...(type ? { type } : {}) },
    });
  },

  // Fiscal Periods
  getFiscalPeriods: (params?: { fiscal_year?: number; status?: string; all?: boolean; per_page?: number }): Promise<ApiSuccessResponse<FiscalPeriodItem[]>> => {
    return apiClient.get<FiscalPeriodItem[]>('/accounting/fiscal-periods', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getFiscalPeriod: (id: string): Promise<ApiSuccessResponse<FiscalPeriodItem>> => {
    return apiClient.get<FiscalPeriodItem>(`/accounting/fiscal-periods/${id}`);
  },

  createFiscalPeriod: (payload: CreateFiscalPeriodPayload): Promise<ApiSuccessResponse<FiscalPeriodItem>> => {
    return apiClient.post<FiscalPeriodItem>('/accounting/fiscal-periods', payload);
  },

  generateFiscalYear: (payload: GenerateFiscalYearPayload): Promise<ApiSuccessResponse<FiscalPeriodItem[]>> => {
    return apiClient.post<FiscalPeriodItem[]>('/accounting/fiscal-periods/generate', payload);
  },

  lockFiscalPeriod: (id: string): Promise<ApiSuccessResponse<FiscalPeriodItem>> => {
    return apiClient.post<FiscalPeriodItem>(`/accounting/fiscal-periods/${id}/lock`);
  },

  unlockFiscalPeriod: (id: string): Promise<ApiSuccessResponse<FiscalPeriodItem>> => {
    return apiClient.post<FiscalPeriodItem>(`/accounting/fiscal-periods/${id}/unlock`);
  },

  closeFiscalPeriod: (id: string): Promise<ApiSuccessResponse<FiscalPeriodItem>> => {
    return apiClient.post<FiscalPeriodItem>(`/accounting/fiscal-periods/${id}/close`);
  },

  // Journal Entries
  getJournalEntries: (params?: {
    status?: string;
    source_type?: string;
    from_date?: string;
    to_date?: string;
    search?: string;
    per_page?: number;
    page?: number;
  }): Promise<ApiSuccessResponse<JournalEntryItem[]>> => {
    return apiClient.get<JournalEntryItem[]>('/accounting/journal-entries', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getJournalEntry: (id: string): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.get<JournalEntryItem>(`/accounting/journal-entries/${id}`);
  },

  createJournalEntry: (payload: CreateJournalEntryPayload): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.post<JournalEntryItem>('/accounting/journal-entries', payload);
  },

  updateJournalEntry: (id: string, payload: UpdateJournalEntryPayload): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.put<JournalEntryItem>(`/accounting/journal-entries/${id}`, payload);
  },

  submitJournalEntry: (id: string): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.post<JournalEntryItem>(`/accounting/journal-entries/${id}/submit`);
  },

  approveJournalEntry: (id: string): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.post<JournalEntryItem>(`/accounting/journal-entries/${id}/approve`);
  },

  rejectJournalEntry: (id: string, payload: RejectJournalEntryPayload): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.post<JournalEntryItem>(`/accounting/journal-entries/${id}/reject`, payload);
  },

  reverseJournalEntry: (id: string, payload: ReverseJournalEntryPayload): Promise<ApiSuccessResponse<JournalEntryItem>> => {
    return apiClient.post<JournalEntryItem>(`/accounting/journal-entries/${id}/reverse`, payload);
  },

  deleteJournalEntry: (id: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/accounting/journal-entries/${id}`);
  },
};
