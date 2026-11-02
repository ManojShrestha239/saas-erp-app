export interface SuperAdminUser {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  created_at: string;
}

export type TenantStatus = 'provisioning' | 'active' | 'suspended' | 'provisioning_failed';

export interface SuperAdminTenantItem {
  id: string;
  name: string;
  slug: string;
  database_name: string;
  owner_email: string | null;
  status: TenantStatus;
  status_label: string;
  provisioning_error: string | null;
  domains: string[];
  primary_domain: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface SuperAdminPackageItem {
  id: number;
  name: string;
  slug: string;
  max_users: number;
  max_warehouses: number;
  max_invoices_per_month: number;
  features: Record<string, boolean | string | number>;
  price_cents: number;
  price_formatted: string;
  subscriptions_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface SuperAdminSubscriptionItem {
  id: number;
  tenant_id: string;
  package_id: number;
  status: 'active' | 'cancelled' | 'expired';
  status_label: string;
  starts_at: string;
  ends_at: string | null;
  package: SuperAdminPackageItem | null;
  created_at: string | null;
}

export interface FeatureFlagItem {
  id: number;
  key: string;
  name: string;
  description: string | null;
  is_enabled: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface SuperAdminStats {
  tenants: {
    total: number;
    active: number;
    suspended: number;
    provisioning: number;
    provisioning_failed: number;
  };
  packages_count: number;
  active_subscriptions_count: number;
  recent_tenants: SuperAdminTenantItem[];
  failed_tenants: SuperAdminTenantItem[];
}

export interface CreatePackagePayload {
  name: string;
  slug: string;
  max_users: number;
  max_warehouses: number;
  max_invoices_per_month: number;
  features?: Record<string, boolean | string | number>;
  price_cents: number;
}

export interface UpdatePackagePayload {
  name?: string;
  slug?: string;
  max_users?: number;
  max_warehouses?: number;
  max_invoices_per_month?: number;
  features?: Record<string, boolean | string | number>;
  price_cents?: number;
}

export interface AssignSubscriptionPayload {
  tenant_id: string;
  package_id: number;
  starts_at?: string;
  ends_at?: string;
}

export interface SuspendTenantPayload {
  reason?: string;
}

export interface TenantFilterParams {
  search?: string;
  status?: string;
  page?: number;
  per_page?: number;
}
