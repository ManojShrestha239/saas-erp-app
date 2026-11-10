import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  AuditFilterParams,
  AuditLog,
  GlobalAuditFilterParams,
  GlobalAuditLog,
} from '../types/audit.types';

export const auditApi = {
  getAuditLogs: (params?: AuditFilterParams): Promise<ApiSuccessResponse<AuditLog[]>> => {
    return apiClient.get<AuditLog[]>('/audit-logs', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getAuditLog: (id: number): Promise<ApiSuccessResponse<AuditLog>> => {
    return apiClient.get<AuditLog>(`/audit-logs/${id}`);
  },

  getGlobalAuditLogs: (params?: GlobalAuditFilterParams): Promise<ApiSuccessResponse<GlobalAuditLog[]>> => {
    return apiClient.get<GlobalAuditLog[]>('/super-admin/audit-logs', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },
};
