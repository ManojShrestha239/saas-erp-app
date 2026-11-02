import { apiClient } from '@/shared/api/apiClient';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  SuperAdminUser,
  SuperAdminStats,
  SuperAdminTenantItem,
  SuperAdminPackageItem,
  SuperAdminSubscriptionItem,
  FeatureFlagItem,
  CreatePackagePayload,
  UpdatePackagePayload,
  AssignSubscriptionPayload,
  SuspendTenantPayload,
  TenantFilterParams,
} from '../types/superAdmin.types';

export const superAdminApi = {
  // Auth
  login: (credentials: { email: string; password: string }): Promise<ApiSuccessResponse<{ user: SuperAdminUser }>> => {
    return apiClient.post<{ user: SuperAdminUser }>('/super-admin/login', credentials);
  },

  logout: (): Promise<ApiSuccessResponse<null>> => {
    return apiClient.post<null>('/super-admin/logout');
  },

  me: (): Promise<ApiSuccessResponse<{ user: SuperAdminUser }>> => {
    return apiClient.get<{ user: SuperAdminUser }>('/super-admin/me');
  },

  // Dashboard Stats
  getStats: (): Promise<ApiSuccessResponse<SuperAdminStats>> => {
    return apiClient.get<SuperAdminStats>('/super-admin/dashboard/stats');
  },

  // Tenants Management
  getTenants: (params?: TenantFilterParams): Promise<ApiSuccessResponse<SuperAdminTenantItem[]>> => {
    return apiClient.get<SuperAdminTenantItem[]>('/super-admin/tenants', {
      params: params as Record<string, string | number | boolean | undefined | null>,
    });
  },

  getTenant: (id: string): Promise<ApiSuccessResponse<SuperAdminTenantItem>> => {
    return apiClient.get<SuperAdminTenantItem>(`/super-admin/tenants/${id}`);
  },

  suspendTenant: (id: string, payload?: SuspendTenantPayload): Promise<ApiSuccessResponse<SuperAdminTenantItem>> => {
    return apiClient.post<SuperAdminTenantItem>(`/super-admin/tenants/${id}/suspend`, payload ?? {});
  },

  reactivateTenant: (id: string): Promise<ApiSuccessResponse<SuperAdminTenantItem>> => {
    return apiClient.post<SuperAdminTenantItem>(`/super-admin/tenants/${id}/reactivate`);
  },

  retryProvisioning: (id: string): Promise<ApiSuccessResponse<SuperAdminTenantItem>> => {
    return apiClient.post<SuperAdminTenantItem>(`/super-admin/tenants/${id}/retry-provisioning`);
  },

  // Packages Management
  getPackages: (): Promise<ApiSuccessResponse<SuperAdminPackageItem[]>> => {
    return apiClient.get<SuperAdminPackageItem[]>('/super-admin/packages');
  },

  getPackage: (id: number): Promise<ApiSuccessResponse<SuperAdminPackageItem>> => {
    return apiClient.get<SuperAdminPackageItem>(`/super-admin/packages/${id}`);
  },

  createPackage: (payload: CreatePackagePayload): Promise<ApiSuccessResponse<SuperAdminPackageItem>> => {
    return apiClient.post<SuperAdminPackageItem>('/super-admin/packages', payload);
  },

  updatePackage: (id: number, payload: UpdatePackagePayload): Promise<ApiSuccessResponse<SuperAdminPackageItem>> => {
    return apiClient.put<SuperAdminPackageItem>(`/super-admin/packages/${id}`, payload);
  },

  deletePackage: (id: number): Promise<ApiSuccessResponse<null>> => {
    return apiClient.delete<null>(`/super-admin/packages/${id}`);
  },

  // Subscriptions Management
  getSubscriptions: (tenantId?: string): Promise<ApiSuccessResponse<SuperAdminSubscriptionItem[]>> => {
    return apiClient.get<SuperAdminSubscriptionItem[]>('/super-admin/subscriptions', {
      params: tenantId ? { tenant_id: tenantId } : undefined,
    });
  },

  assignSubscription: (payload: AssignSubscriptionPayload): Promise<ApiSuccessResponse<SuperAdminSubscriptionItem>> => {
    return apiClient.post<SuperAdminSubscriptionItem>('/super-admin/subscriptions', payload);
  },

  cancelSubscription: (id: number): Promise<ApiSuccessResponse<SuperAdminSubscriptionItem>> => {
    return apiClient.post<SuperAdminSubscriptionItem>(`/super-admin/subscriptions/${id}/cancel`);
  },

  // Feature Flags
  getFeatureFlags: (): Promise<ApiSuccessResponse<FeatureFlagItem[]>> => {
    return apiClient.get<FeatureFlagItem[]>('/super-admin/feature-flags');
  },

  toggleFeatureFlag: (key: string, isEnabled?: boolean): Promise<ApiSuccessResponse<FeatureFlagItem>> => {
    return apiClient.put<FeatureFlagItem>(`/super-admin/feature-flags/${key}/toggle`, {
      is_enabled: isEnabled,
    });
  },
};
