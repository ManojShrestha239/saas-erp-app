import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { CsvDuplicateAction, CsvImportItem } from '@/shared/types/csvImport.types';
import type { ContactFilters, ContactFormData, ContactItem } from '../types/contacts.types';

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
