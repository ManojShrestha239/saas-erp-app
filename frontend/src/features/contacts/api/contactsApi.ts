import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { CsvDuplicateAction, CsvImportItem } from '@/shared/types/csvImport.types';
import type {
  AgingData,
  ContactFilters,
  ContactFormData,
  ContactItem,
  ContactStatement,
} from '../types/contacts.types';

export const contactsApi = {
  getContacts: (filters?: ContactFilters): Promise<ApiSuccessResponse<ContactItem[]>> => {
    return apiClient.get<ContactItem[]>('/contacts', {
      params: filters as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getContact: (id: string): Promise<ApiSuccessResponse<ContactItem>> => {
    return apiClient.get<ContactItem>(`/contacts/${id}`);
  },

  createContact: (data: ContactFormData): Promise<ApiSuccessResponse<ContactItem>> => {
    return apiClient.post<ContactItem>('/contacts', data);
  },

  updateContact: (id: string, data: Partial<ContactFormData>): Promise<ApiSuccessResponse<ContactItem>> => {
    return apiClient.put<ContactItem>(`/contacts/${id}`, data);
  },

  deleteContact: (id: string): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/contacts/${id}`);
  },

  getAging: (
    id: string,
    asOfDate?: string,
    type?: 'customer' | 'supplier'
  ): Promise<ApiSuccessResponse<AgingData>> => {
    return apiClient.get<AgingData>(`/contacts/${id}/aging`, {
      params: { as_of_date: asOfDate, type },
    });
  },

  getStatement: (
    id: string,
    params?: {
      start_date?: string;
      end_date?: string;
      statement_type?: 'customer' | 'supplier';
    }
  ): Promise<ApiSuccessResponse<ContactStatement>> => {
    return apiClient.get<ContactStatement>(`/contacts/${id}/statement`, {
      params: params as Record<string, string | undefined>,
    });
  },

  getStatementPdfUrl: (
    id: string,
    params?: {
      start_date?: string;
      end_date?: string;
      statement_type?: 'customer' | 'supplier';
      download?: boolean;
    }
  ): string => {
    const query = new URLSearchParams();
    if (params?.start_date) query.set('start_date', params.start_date);
    if (params?.end_date) query.set('end_date', params.end_date);
    if (params?.statement_type) query.set('statement_type', params.statement_type);
    if (params?.download) query.set('download', '1');
    const queryString = query.toString();
    return `/api/v1/contacts/${id}/statement/pdf${queryString ? `?${queryString}` : ''}`;
  },

  uploadContactCsv: (
    file: File,
    duplicateAction: CsvDuplicateAction = 'skip'
  ): Promise<ApiSuccessResponse<CsvImportItem>> => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('duplicate_action', duplicateAction);

    return apiClient.post<CsvImportItem>('/contacts/import', formData);
  },

  getContactImportStatus: (importId: string): Promise<ApiSuccessResponse<CsvImportItem>> => {
    return apiClient.get<CsvImportItem>(`/contacts/import/${importId}`);
  },

  getContactTemplateUrl: (): string => {
    return '/api/v1/contacts/import/template';
  },
};
