import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import SuperAdminDashboard from '../SuperAdminDashboard';
import SuperAdminLogin from '../SuperAdminLogin';
import { superAdminApi } from '../../api/superAdminApi';
import type {
  SuperAdminStats,
  SuperAdminTenantItem,
  SuperAdminPackageItem,
  FeatureFlagItem,
} from '../../types/superAdmin.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';

vi.mock('../../api/superAdminApi', () => ({
  superAdminApi: {
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
    getStats: vi.fn(),
    getTenants: vi.fn(),
    getTenant: vi.fn(),
    suspendTenant: vi.fn(),
    reactivateTenant: vi.fn(),
    retryProvisioning: vi.fn(),
    getPackages: vi.fn(),
    createPackage: vi.fn(),
    updatePackage: vi.fn(),
    deletePackage: vi.fn(),
    getSubscriptions: vi.fn(),
    assignSubscription: vi.fn(),
    cancelSubscription: vi.fn(),
    getFeatureFlags: vi.fn(),
    toggleFeatureFlag: vi.fn(),
  },
}));

const mockStats: SuperAdminStats = {
  tenants: {
    total: 12,
    active: 10,
    suspended: 1,
    provisioning: 0,
    provisioning_failed: 1,
  },
  packages_count: 3,
  active_subscriptions_count: 8,
  recent_tenants: [
    {
      id: '01TENANT00000000000000001',
      name: 'Acme Mega Corp',
      slug: 'acme',
      database_name: 'tenant_acme',
      owner_email: 'founder@acme.com',
      status: 'active',
      status_label: 'Active',
      provisioning_error: null,
      domains: ['acme.nexuserp.test'],
      primary_domain: 'acme.nexuserp.test',
      created_at: '2026-10-01T10:00:00Z',
      updated_at: '2026-10-01T10:00:00Z',
    },
    {
      id: '01TENANT00000000000000002',
      name: 'Broken Corp',
      slug: 'broken-corp',
      database_name: 'tenant_broken',
      owner_email: 'owner@broken.com',
      status: 'provisioning_failed',
      status_label: 'Provisioning Failed',
      provisioning_error: 'Timeout connecting to postgres',
      domains: ['broken.nexuserp.test'],
      primary_domain: 'broken.nexuserp.test',
      created_at: '2026-10-02T10:00:00Z',
      updated_at: '2026-10-02T10:00:00Z',
    },
  ],
  failed_tenants: [
    {
      id: '01TENANT00000000000000002',
      name: 'Broken Corp',
      slug: 'broken-corp',
      database_name: 'tenant_broken',
      owner_email: 'owner@broken.com',
      status: 'provisioning_failed',
      status_label: 'Provisioning Failed',
      provisioning_error: 'Timeout connecting to postgres',
      domains: ['broken.nexuserp.test'],
      primary_domain: 'broken.nexuserp.test',
      created_at: '2026-10-02T10:00:00Z',
      updated_at: '2026-10-02T10:00:00Z',
    },
  ],
};

const mockPackages: SuperAdminPackageItem[] = [
  {
    id: 1,
    name: 'Starter Tier',
    slug: 'starter',
    max_users: 3,
    max_warehouses: 1,
    max_invoices_per_month: 100,
    features: { multi_warehouse: false, api_access: false },
    price_cents: 2900,
    price_formatted: '29.00',
    subscriptions_count: 5,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
  {
    id: 2,
    name: 'Professional Tier',
    slug: 'professional',
    max_users: 10,
    max_warehouses: 3,
    max_invoices_per_month: 500,
    features: { multi_warehouse: true, api_access: true },
    price_cents: 7900,
    price_formatted: '79.00',
    subscriptions_count: 3,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
];

const mockFeatureFlags: FeatureFlagItem[] = [
  {
    id: 1,
    key: 'public_registration',
    name: 'Public Registration',
    description: 'Allow new customer signups',
    is_enabled: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
  {
    id: 2,
    key: 'maintenance_mode',
    name: 'Maintenance Mode',
    description: 'Central portal maintenance notice',
    is_enabled: false,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
];

describe('Super Admin Portal', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(superAdminApi.getStats).mockResolvedValue({
      success: true,
      data: mockStats,
    } as ApiSuccessResponse<SuperAdminStats>);

    vi.mocked(superAdminApi.getPackages).mockResolvedValue({
      success: true,
      data: mockPackages,
    } as ApiSuccessResponse<SuperAdminPackageItem[]>);

    vi.mocked(superAdminApi.getTenants).mockResolvedValue({
      success: true,
      data: mockStats.recent_tenants,
    } as ApiSuccessResponse<SuperAdminTenantItem[]>);

    vi.mocked(superAdminApi.getFeatureFlags).mockResolvedValue({
      success: true,
      data: mockFeatureFlags,
    } as ApiSuccessResponse<FeatureFlagItem[]>);

    vi.mocked(superAdminApi.getSubscriptions).mockResolvedValue({
      success: true,
      message: 'OK',
      data: [],
    });
  });

  it('renders login form and submits credentials', async () => {
    vi.mocked(superAdminApi.login).mockResolvedValue({
      success: true,
      message: 'Logged in',
      data: {
        user: {
          id: 1,
          name: 'Super Admin',
          email: 'admin@nexuserp.test',
          email_verified_at: '2026-01-01',
          created_at: '2026-01-01',
        },
      },
    });

    render(<SuperAdminLogin />);

    expect(screen.getByText('NexusERP Super Admin')).toBeInTheDocument();
    expect(screen.getByLabelText(/Admin Email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Password/i)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/Admin Email/i), {
      target: { value: 'admin@nexuserp.test' },
    });
    fireEvent.change(screen.getByLabelText(/Password/i), {
      target: { value: 'Secret123!' },
    });

    fireEvent.click(screen.getByRole('button', { name: /Sign In to Platform Controller/i }));

    await waitFor(() => {
      expect(superAdminApi.login).toHaveBeenCalledWith({
        email: 'admin@nexuserp.test',
        password: 'Secret123!',
      });
    });
  });

  it('renders dashboard overview with KPI cards and provisioning failure banner', async () => {
    render(<SuperAdminDashboard initialTab="overview" />);

    expect(screen.getByText('Platform Overview')).toBeInTheDocument();
    expect(screen.getByText('Nexus Platform Controller')).toBeInTheDocument();

    // Verify failure banner
    await waitFor(() => {
      expect(screen.getByText(/1 Tenant Provisioning Failure\(s\) Detected/i)).toBeInTheDocument();
      expect(screen.getByText('12')).toBeInTheDocument(); // total tenants
      expect(screen.getByText('8')).toBeInTheDocument(); // active subscriptions
    });

    expect(screen.getByText('Acme Mega Corp')).toBeInTheDocument();
  });

  it('switches to Tenants tab and lists tenants with filter', async () => {
    render(<SuperAdminDashboard initialTab="tenants" />);

    await waitFor(() => {
      expect(screen.getByText('Tenants Directory')).toBeInTheDocument();
      expect(screen.getByText('Acme Mega Corp')).toBeInTheDocument();
      expect(screen.getByText('Broken Corp')).toBeInTheDocument();
    });

    // Check Suspend button on active tenant
    expect(screen.getByRole('button', { name: /Suspend/i })).toBeInTheDocument();
    // Check Retry button on failed tenant
    expect(screen.getByRole('button', { name: /Retry/i })).toBeInTheDocument();
  });

  it('switches to Packages tab and renders package cards with pricing', async () => {
    render(<SuperAdminDashboard initialTab="packages" />);

    await waitFor(() => {
      expect(screen.getByText('Subscription Packages')).toBeInTheDocument();
      expect(screen.getByText('Starter Tier')).toBeInTheDocument();
      expect(screen.getByText('$29.00')).toBeInTheDocument();
      expect(screen.getByText('Professional Tier')).toBeInTheDocument();
      expect(screen.getByText('$79.00')).toBeInTheDocument();
    });

    expect(screen.getByRole('button', { name: /Create Package/i })).toBeInTheDocument();
  });

  it('switches to Feature Flags tab and toggles flag', async () => {
    vi.mocked(superAdminApi.toggleFeatureFlag).mockResolvedValue({
      success: true,
      message: 'Feature flag toggled',
      data: { ...mockFeatureFlags[0]!, is_enabled: false },
    });

    render(<SuperAdminDashboard initialTab="feature-flags" />);

    await waitFor(() => {
      expect(screen.getByText('Global Feature Toggles')).toBeInTheDocument();
      expect(screen.getByText('Public Registration')).toBeInTheDocument();
      expect(screen.getByText('Maintenance Mode')).toBeInTheDocument();
    });

    const toggleBtn = screen.getByRole('button', { name: 'Enabled' });
    fireEvent.click(toggleBtn);

    await waitFor(() => {
      expect(superAdminApi.toggleFeatureFlag).toHaveBeenCalledWith('public_registration', false);
    });
  });
});
