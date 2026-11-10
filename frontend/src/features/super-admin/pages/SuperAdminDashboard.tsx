import { useState, useEffect, useCallback } from 'react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
  Building2,
  Package as PackageIcon,
  CreditCard,
  AlertTriangle,
  RotateCw,
  Search,
  Plus,
  Check,
  X,
  Loader2,
  CheckCircle2,
  Users,
  Warehouse,
  Receipt,
  Eye,
} from 'lucide-react';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { auditApi } from '@/features/audit/api/auditApi';
import type { GlobalAuditLog } from '@/features/audit/types/audit.types';
import { superAdminApi } from '../api/superAdminApi';
import type {
  SuperAdminStats,
  SuperAdminTenantItem,
  SuperAdminPackageItem,
  SuperAdminSubscriptionItem,
  FeatureFlagItem,
} from '../types/superAdmin.types';
import { SuspendTenantModal } from '../components/SuspendTenantModal';
import { PackageFormModal } from '../components/PackageFormModal';
import { AssignSubscriptionModal } from '../components/AssignSubscriptionModal';
import { TenantDetailsModal } from '../components/TenantDetailsModal';

interface SuperAdminDashboardProps {
  initialTab?: string;
}

export default function SuperAdminDashboard({
  initialTab = 'overview',
}: SuperAdminDashboardProps) {
  const [activeTab, setActiveTab] = useState<string>(initialTab);

  // Data States
  const [stats, setStats] = useState<SuperAdminStats | null>(null);
  const [tenants, setTenants] = useState<SuperAdminTenantItem[]>([]);
  const [packages, setPackages] = useState<SuperAdminPackageItem[]>([]);
  const [subscriptions, setSubscriptions] = useState<SuperAdminSubscriptionItem[]>([]);
  const [featureFlags, setFeatureFlags] = useState<FeatureFlagItem[]>([]);

  // Loading & Feedback
  const [loading, setLoading] = useState(false);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Filters
  const [tenantSearch, setTenantSearch] = useState('');
  const [tenantStatus, setTenantStatus] = useState<string>('');

  // Modals
  const [suspendModalOpen, setSuspendModalOpen] = useState(false);
  const [tenantToSuspend, setTenantToSuspend] = useState<SuperAdminTenantItem | null>(null);

  const [packageModalOpen, setPackageModalOpen] = useState(false);
  const [packageToEdit, setPackageToEdit] = useState<SuperAdminPackageItem | null>(null);

  const [subscriptionModalOpen, setSubscriptionModalOpen] = useState(false);
  const [tenantForSub, setTenantForSub] = useState<SuperAdminTenantItem | null>(null);

  const [detailsModalOpen, setDetailsModalOpen] = useState(false);
  const [tenantDetails, setTenantDetails] = useState<SuperAdminTenantItem | null>(null);

  // Central Audit State
  const [auditLogs, setAuditLogs] = useState<GlobalAuditLog[]>([]);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditSearch, setAuditSearch] = useState('');
  const [auditEventFilter, setAuditEventFilter] = useState('all');
  const [auditDetailsModalOpen, setAuditDetailsModalOpen] = useState(false);
  const [selectedGlobalLog, setSelectedGlobalLog] = useState<GlobalAuditLog | null>(null);

  // Fetch Dashboard Stats
  const loadStats = useCallback(async () => {
    try {
      const res = await superAdminApi.getStats();
      setStats(res.data);
    } catch {
      // Best effort load
    }
  }, []);

  // Fetch Tenants
  const loadTenants = useCallback(async () => {
    setLoading(true);
    try {
      const res = await superAdminApi.getTenants({
        search: tenantSearch || undefined,
        status: tenantStatus || undefined,
        per_page: 50,
      });
      setTenants(res.data);
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to load tenants.',
      });
    } finally {
      setLoading(false);
    }
  }, [tenantSearch, tenantStatus]);

  // Fetch Packages
  const loadPackages = useCallback(async () => {
    try {
      const res = await superAdminApi.getPackages();
      setPackages(res.data);
    } catch {
      // Best effort load
    }
  }, []);

  // Fetch Subscriptions
  const loadSubscriptions = useCallback(async () => {
    try {
      const res = await superAdminApi.getSubscriptions();
      setSubscriptions(res.data);
    } catch {
      // Best effort load
    }
  }, []);

  // Fetch Feature Flags
  const loadFeatureFlags = useCallback(async () => {
    try {
      const res = await superAdminApi.getFeatureFlags();
      setFeatureFlags(res.data);
    } catch {
      // Best effort load
    }
  }, []);

  // Fetch Central Audit Logs
  const loadAuditLogs = useCallback(async () => {
    setAuditLoading(true);
    try {
      const res = await auditApi.getGlobalAuditLogs({
        search: auditSearch.trim() || undefined,
        event: auditEventFilter !== 'all' ? auditEventFilter : undefined,
        per_page: 50,
      });
      setAuditLogs(res.data);
    } catch {
      // Best effort load
    } finally {
      setAuditLoading(false);
    }
  }, [auditSearch, auditEventFilter]);

  // Initial Load
  useEffect(() => {
    loadStats();
    loadPackages();
  }, [loadStats, loadPackages]);

  useEffect(() => {
    if (activeTab === 'overview') {
      loadStats();
    } else if (activeTab === 'tenants') {
      loadTenants();
    } else if (activeTab === 'packages') {
      loadPackages();
    } else if (activeTab === 'subscriptions') {
      loadSubscriptions();
    } else if (activeTab === 'feature-flags') {
      loadFeatureFlags();
    } else if (activeTab === 'audit-logs') {
      loadAuditLogs();
    }
  }, [activeTab, loadStats, loadTenants, loadPackages, loadSubscriptions, loadFeatureFlags, loadAuditLogs]);

  // Actions
  const handleReactivateTenant = async (id: string) => {
    try {
      const res = await superAdminApi.reactivateTenant(id);
      setFeedback({ type: 'success', message: res.message });
      loadTenants();
      loadStats();
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to reactivate tenant.',
      });
    }
  };

  const handleRetryProvisioning = async (id: string) => {
    try {
      const res = await superAdminApi.retryProvisioning(id);
      setFeedback({ type: 'success', message: res.message });
      loadTenants();
      loadStats();
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to retry provisioning.',
      });
    }
  };

  const handleDeletePackage = async (id: number) => {
    if (!confirm('Are you sure you want to delete this package?')) return;
    try {
      await superAdminApi.deletePackage(id);
      setFeedback({ type: 'success', message: 'Package deleted successfully.' });
      loadPackages();
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to delete package.',
      });
    }
  };

  const handleToggleFlag = async (key: string, current: boolean) => {
    try {
      const res = await superAdminApi.toggleFeatureFlag(key, !current);
      setFeatureFlags((prev) =>
        prev.map((f) => (f.key === key ? res.data : f))
      );
      setFeedback({ type: 'success', message: res.message });
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to toggle feature flag.',
      });
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active':
        return <Badge variant="outline" className="border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">Active</Badge>;
      case 'suspended':
        return <Badge variant="outline" className="border-amber-500/30 bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">Suspended</Badge>;
      case 'provisioning_failed':
        return <Badge variant="outline" className="border-destructive/30 bg-destructive/10 text-destructive">Provisioning Failed</Badge>;
      default:
        return <Badge variant="outline" className="border-blue-500/30 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">Provisioning</Badge>;
    }
  };

  return (
    <AdminLayout activeNav={activeTab} onNavChange={setActiveTab}>
      <div className="space-y-6">
        {/* Global Feedback Banner */}
        {feedback && (
          <Alert
            variant={feedback.type === 'error' ? 'destructive' : 'default'}
            className="flex items-center justify-between"
          >
            <AlertDescription>{feedback.message}</AlertDescription>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => setFeedback(null)}
              className="h-7 px-2 text-xs"
            >
              Dismiss
            </Button>
          </Alert>
        )}

        {/* Failed Provisioning Alert Banner */}
        {stats && stats.tenants.provisioning_failed > 0 && (
          <div className="flex flex-col gap-3 rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-destructive sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 shrink-0" />
              <div>
                <span className="font-semibold">
                  {stats.tenants.provisioning_failed} Tenant Provisioning Failure(s) Detected
                </span>
                <p className="text-xs text-destructive/80 mt-0.5">
                  Tenant database migrations or setup encountered errors. Review logs and retry provisioning.
                </p>
              </div>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setActiveTab('tenants');
                setTenantStatus('provisioning_failed');
              }}
              className="border-destructive/30 text-destructive hover:bg-destructive/10 self-start sm:self-auto"
            >
              View Failed Tenants
            </Button>
          </div>
        )}

        {/* OVERVIEW TAB */}
        {activeTab === 'overview' && (
          <div className="space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">
                  Platform Overview
                </h2>
                <p className="text-sm text-muted-foreground">
                  Central tenant health, subscription metrics, and infrastructure state.
                </p>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadStats}
                className="gap-2"
              >
                <RotateCw className="h-4 w-4" /> Refresh Stats
              </Button>
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
                  <span>Total Tenants</span>
                  <Building2 className="h-4 w-4 text-primary" />
                </div>
                <div className="mt-2 text-3xl font-bold tracking-tight text-foreground">
                  {stats?.tenants.total ?? 0}
                </div>
                <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                  <span className="text-emerald-600 font-medium">
                    {stats?.tenants.active ?? 0} Active
                  </span>
                  <span>•</span>
                  <span className="text-amber-600 font-medium">
                    {stats?.tenants.suspended ?? 0} Suspended
                  </span>
                </div>
              </div>

              <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
                  <span>Active Subscriptions</span>
                  <CreditCard className="h-4 w-4 text-primary" />
                </div>
                <div className="mt-2 text-3xl font-bold tracking-tight text-foreground">
                  {stats?.active_subscriptions_count ?? 0}
                </div>
                <div className="mt-2 text-xs text-muted-foreground">
                  Monetized enterprise & professional tiers
                </div>
              </div>

              <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
                  <span>Available Packages</span>
                  <PackageIcon className="h-4 w-4 text-primary" />
                </div>
                <div className="mt-2 text-3xl font-bold tracking-tight text-foreground">
                  {stats?.packages_count ?? 0}
                </div>
                <div className="mt-2 text-xs text-muted-foreground">
                  Configured subscription tiers & limits
                </div>
              </div>

              <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
                  <span>Platform Health</span>
                  <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                </div>
                <div className="mt-2 text-xl font-bold text-emerald-600">
                  Operational
                </div>
                <div className="mt-2 text-xs text-muted-foreground">
                  PostgreSQL Central, Redis & Horizon Connected
                </div>
              </div>
            </div>

            {/* Recent Tenants Card */}
            <div className="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
              <div className="flex items-center justify-between border-b border-border p-4">
                <h3 className="font-semibold text-foreground">Recently Registered Tenants</h3>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setActiveTab('tenants')}
                >
                  View All Tenants →
                </Button>
              </div>
              <div className="divide-y divide-border">
                {stats?.recent_tenants && stats.recent_tenants.length > 0 ? (
                  stats.recent_tenants.map((t) => (
                    <div
                      key={t.id}
                      className="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors"
                    >
                      <div>
                        <div className="font-medium text-foreground">{t.name}</div>
                        <div className="text-xs text-muted-foreground font-mono">
                          {t.slug}.nexuserp.test • {t.database_name}
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        {getStatusBadge(t.status)}
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => {
                            setTenantDetails(t);
                            setDetailsModalOpen(true);
                          }}
                        >
                          Inspect
                        </Button>
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="p-8 text-center text-sm text-muted-foreground">
                    No tenants found.
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        {/* TENANTS TAB */}
        {activeTab === 'tenants' && (
          <div className="space-y-4">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">
                  Tenants Directory
                </h2>
                <p className="text-sm text-muted-foreground">
                  Manage tenant databases, domains, suspend/reactivate access, and retry provisioning.
                </p>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadTenants}
                disabled={loading}
                className="gap-2"
              >
                {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCw className="h-4 w-4" />}
                Refresh
              </Button>
            </div>

            {/* Filter Bar */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <div className="relative flex-1">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search by company name, slug, or domain..."
                  value={tenantSearch}
                  onChange={(e) => setTenantSearch(e.target.value)}
                  className="pl-9"
                />
              </div>

              <select
                value={tenantStatus}
                onChange={(e) => setTenantStatus(e.target.value)}
                className="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              >
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="provisioning">Provisioning</option>
                <option value="provisioning_failed">Provisioning Failed</option>
              </select>
            </div>

            {/* Tenants Table */}
            <div className="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-border bg-muted/30 text-xs font-semibold uppercase text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3">Tenant / Company</th>
                      <th className="px-4 py-3">Status</th>
                      <th className="px-4 py-3">Domain</th>
                      <th className="px-4 py-3">Database</th>
                      <th className="px-4 py-3">Owner</th>
                      <th className="px-4 py-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {tenants.map((t) => (
                      <tr key={t.id} className="hover:bg-muted/20 transition-colors">
                        <td className="px-4 py-3">
                          <div className="font-semibold text-foreground">{t.name}</div>
                          <div className="text-xs font-mono text-muted-foreground">{t.slug}</div>
                        </td>
                        <td className="px-4 py-3">{getStatusBadge(t.status)}</td>
                        <td className="px-4 py-3 font-mono text-xs">
                          {t.primary_domain || `${t.slug}.nexuserp.test`}
                        </td>
                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                          {t.database_name}
                        </td>
                        <td className="px-4 py-3 text-xs">
                          {t.owner_email || '—'}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            {t.status === 'provisioning_failed' && (
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => handleRetryProvisioning(t.id)}
                                className="border-primary/40 text-primary text-xs"
                              >
                                Retry
                              </Button>
                            )}

                            {t.status === 'active' && (
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                  setTenantToSuspend(t);
                                  setSuspendModalOpen(true);
                                }}
                                className="text-xs text-amber-600 hover:bg-amber-50"
                              >
                                Suspend
                              </Button>
                            )}

                            {t.status === 'suspended' && (
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => handleReactivateTenant(t.id)}
                                className="text-xs text-emerald-600 hover:bg-emerald-50"
                              >
                                Reactivate
                              </Button>
                            )}

                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() => {
                                setTenantDetails(t);
                                setDetailsModalOpen(true);
                              }}
                              className="text-xs"
                            >
                              Details
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}

                    {tenants.length === 0 && (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                          No tenants matched your query.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* PACKAGES TAB */}
        {activeTab === 'packages' && (
          <div className="space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">
                  Subscription Packages
                </h2>
                <p className="text-sm text-muted-foreground">
                  Define user, warehouse, and document quotas alongside commercial feature toggles.
                </p>
              </div>
              <Button
                type="button"
                variant="primary"
                size="sm"
                onClick={() => {
                  setPackageToEdit(null);
                  setPackageModalOpen(true);
                }}
                className="gap-2"
              >
                <Plus className="h-4 w-4" /> Create Package
              </Button>
            </div>

            <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
              {packages.map((pkg) => (
                <div
                  key={pkg.id}
                  className="flex flex-col rounded-xl border border-border bg-card p-6 shadow-sm justify-between"
                >
                  <div className="space-y-4">
                    <div className="flex items-center justify-between">
                      <h3 className="text-lg font-bold text-foreground">{pkg.name}</h3>
                      <Badge variant="outline" className="font-mono text-xs uppercase">
                        {pkg.slug}
                      </Badge>
                    </div>

                    <div className="text-3xl font-extrabold text-foreground">
                      ${pkg.price_formatted}
                      <span className="text-xs font-normal text-muted-foreground"> / month</span>
                    </div>

                    {/* Limits */}
                    <div className="space-y-2 border-t border-b border-border py-4 text-sm text-muted-foreground">
                      <div className="flex items-center justify-between">
                        <span className="flex items-center gap-2">
                          <Users className="h-4 w-4" /> Users
                        </span>
                        <span className="font-semibold text-foreground">{pkg.max_users}</span>
                      </div>
                      <div className="flex items-center justify-between">
                        <span className="flex items-center gap-2">
                          <Warehouse className="h-4 w-4" /> Warehouses
                        </span>
                        <span className="font-semibold text-foreground">{pkg.max_warehouses}</span>
                      </div>
                      <div className="flex items-center justify-between">
                        <span className="flex items-center gap-2">
                          <Receipt className="h-4 w-4" /> Invoices / Month
                        </span>
                        <span className="font-semibold text-foreground">
                          {pkg.max_invoices_per_month}
                        </span>
                      </div>
                    </div>

                    {/* Feature Toggles */}
                    <div className="space-y-2 text-xs">
                      <div className="font-semibold uppercase text-muted-foreground">Features</div>
                      <div className="flex items-center gap-2">
                        {pkg.features?.multi_warehouse ? (
                          <Check className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <X className="h-4 w-4 text-muted-foreground" />
                        )}
                        <span>Multi-Warehouse Operations</span>
                      </div>
                      <div className="flex items-center gap-2">
                        {pkg.features?.api_access ? (
                          <Check className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <X className="h-4 w-4 text-muted-foreground" />
                        )}
                        <span>API Access & Webhooks</span>
                      </div>
                      <div className="flex items-center gap-2">
                        {pkg.features?.advanced_reports ? (
                          <Check className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <X className="h-4 w-4 text-muted-foreground" />
                        )}
                        <span>Advanced Financial Analytics</span>
                      </div>
                    </div>
                  </div>

                  <div className="mt-6 flex items-center justify-between border-t border-border pt-4">
                    <span className="text-xs text-muted-foreground">
                      {pkg.subscriptions_count} active tenants
                    </span>
                    <div className="flex items-center gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                          setPackageToEdit(pkg);
                          setPackageModalOpen(true);
                        }}
                      >
                        Edit
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={pkg.subscriptions_count > 0}
                        onClick={() => handleDeletePackage(pkg.id)}
                        className="text-destructive hover:bg-destructive/10"
                      >
                        Delete
                      </Button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* SUBSCRIPTIONS TAB */}
        {activeTab === 'subscriptions' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">
                  Tenant Subscriptions
                </h2>
                <p className="text-sm text-muted-foreground">
                  History and tier associations across active customer tenants.
                </p>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadSubscriptions}
                className="gap-2"
              >
                <RotateCw className="h-4 w-4" /> Refresh
              </Button>
            </div>

            <div className="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-muted/30 text-xs font-semibold uppercase text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3">Tenant ID</th>
                    <th className="px-4 py-3">Package Tier</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3">Starts At</th>
                    <th className="px-4 py-3">Ends At</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {subscriptions.map((sub) => (
                    <tr key={sub.id} className="hover:bg-muted/20 transition-colors">
                      <td className="px-4 py-3 font-mono text-xs">{sub.tenant_id}</td>
                      <td className="px-4 py-3 font-semibold text-foreground">
                        {sub.package?.name ?? `Package #${sub.package_id}`}
                      </td>
                      <td className="px-4 py-3">
                        <Badge
                          variant="outline"
                          className={
                            sub.status === 'active'
                              ? 'border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                              : 'border-muted text-muted-foreground'
                          }
                        >
                          {sub.status_label}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {new Date(sub.starts_at).toLocaleDateString()}
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {sub.ends_at ? new Date(sub.ends_at).toLocaleDateString() : 'Ongoing'}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {sub.status === 'active' && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={async () => {
                              if (!confirm('Cancel this active subscription?')) return;
                              await superAdminApi.cancelSubscription(sub.id);
                              loadSubscriptions();
                            }}
                            className="text-xs text-destructive hover:bg-destructive/10"
                          >
                            Cancel
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}

                  {subscriptions.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                        No subscriptions found.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* FEATURE FLAGS TAB */}
        {activeTab === 'feature-flags' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">
                  Global Feature Toggles
                </h2>
                <p className="text-sm text-muted-foreground">
                  Platform-wide runtime switches controlling registration, maintenance, and beta capabilities.
                </p>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadFeatureFlags}
                className="gap-2"
              >
                <RotateCw className="h-4 w-4" /> Refresh
              </Button>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {featureFlags.map((flag) => (
                <div
                  key={flag.id}
                  className="flex items-center justify-between rounded-xl border border-border bg-card p-5 shadow-sm"
                >
                  <div className="space-y-1 pr-4">
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-foreground">{flag.name}</span>
                      <Badge variant="outline" className="font-mono text-[10px]">
                        {flag.key}
                      </Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {flag.description || 'No description provided.'}
                    </p>
                  </div>

                  <Button
                    type="button"
                    variant={flag.is_enabled ? 'primary' : 'outline'}
                    size="sm"
                    onClick={() => handleToggleFlag(flag.key, flag.is_enabled)}
                    className={
                      flag.is_enabled
                        ? 'bg-emerald-600 hover:bg-emerald-700 text-white min-w-[90px]'
                        : 'min-w-[90px]'
                    }
                  >
                    {flag.is_enabled ? 'Enabled' : 'Disabled'}
                  </Button>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* CENTRAL AUDIT LOGS TAB */}
        {activeTab === 'audit-logs' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">
                  Central Immutable Audit Trail
                </h2>
                <p className="text-sm text-muted-foreground">
                  Platform-level audit log recording tenant provisioning, package adjustments, and feature toggles.
                </p>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadAuditLogs}
                disabled={auditLoading}
                className="gap-2"
              >
                <RotateCw className={`h-4 w-4 ${auditLoading ? 'animate-spin' : ''}`} /> Refresh
              </Button>
            </div>

            {/* Toolbar */}
            <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between bg-card p-3 rounded-lg border border-border">
              <div className="flex items-center gap-1 overflow-x-auto pb-1 sm:pb-0">
                {[
                  { label: 'All Events', value: 'all' },
                  { label: 'Tenants', value: 'tenant' },
                  { label: 'Packages', value: 'package' },
                  { label: 'Subscriptions', value: 'subscription' },
                  { label: 'Feature Flags', value: 'feature_flag' },
                ].map((tab) => (
                  <Button
                    key={tab.value}
                    variant={auditEventFilter === tab.value ? 'primary' : 'ghost'}
                    size="sm"
                    className="text-xs h-8"
                    onClick={() => setAuditEventFilter(tab.value)}
                  >
                    {tab.label}
                  </Button>
                ))}
              </div>

              <div className="relative w-full sm:w-64">
                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                <Input
                  placeholder="Search central audit..."
                  value={auditSearch}
                  onChange={(e) => setAuditSearch(e.target.value)}
                  className="pl-8 h-8 text-xs"
                />
              </div>
            </div>

            {/* Central Audit Table */}
            <div className="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-border bg-muted/40 text-xs font-semibold text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3">Timestamp</th>
                      <th className="px-4 py-3">Event</th>
                      <th className="px-4 py-3">Tenant ID</th>
                      <th className="px-4 py-3">Target / Model</th>
                      <th className="px-4 py-3">Actor / IP</th>
                      <th className="px-4 py-3 text-right">Details</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {auditLogs.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-8 text-center text-sm text-muted-foreground">
                          {auditLoading ? 'Loading audit records...' : 'No central audit records found.'}
                        </td>
                      </tr>
                    ) : (
                      auditLogs.map((log) => (
                        <tr key={log.id} className="hover:bg-muted/30 transition-colors">
                          <td className="px-4 py-3 whitespace-nowrap text-xs text-muted-foreground">
                            {log.created_at ? new Date(log.created_at).toLocaleString() : '—'}
                          </td>
                          <td className="px-4 py-3">
                            <Badge variant="outline" className="font-mono text-xs">
                              {log.event}
                            </Badge>
                          </td>
                          <td className="px-4 py-3 text-xs font-mono text-muted-foreground">
                            {log.tenant_id ? log.tenant_id.slice(0, 10) + '...' : 'System / Central'}
                          </td>
                          <td className="px-4 py-3 text-xs">
                            <span className="font-medium text-foreground">
                              {log.auditable_type ? log.auditable_type.split('\\').pop() : '—'}
                            </span>
                            {log.auditable_id && (
                              <span className="font-mono text-muted-foreground ml-1">
                                (#{log.auditable_id})
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-xs font-mono text-muted-foreground">
                            {log.ip_address ?? '—'}
                          </td>
                          <td className="px-4 py-3 text-right">
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-7 px-2 text-xs"
                              onClick={() => {
                                setSelectedGlobalLog(log);
                                setAuditDetailsModalOpen(true);
                              }}
                            >
                              <Eye className="h-3.5 w-3.5 mr-1" />
                              View
                            </Button>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* MODALS */}
        <SuspendTenantModal
          open={suspendModalOpen}
          onOpenChange={setSuspendModalOpen}
          tenant={tenantToSuspend}
          onSuccess={(updated) => {
            setFeedback({
              type: 'success',
              message: `Tenant '${updated.name}' has been suspended.`,
            });
            loadTenants();
            loadStats();
          }}
        />

        <PackageFormModal
          open={packageModalOpen}
          onOpenChange={setPackageModalOpen}
          pkg={packageToEdit}
          onSuccess={(saved) => {
            setFeedback({
              type: 'success',
              message: `Package '${saved.name}' saved successfully.`,
            });
            loadPackages();
            loadStats();
          }}
        />

        <AssignSubscriptionModal
          open={subscriptionModalOpen}
          onOpenChange={setSubscriptionModalOpen}
          tenant={tenantForSub}
          packages={packages}
          onSuccess={() => {
            setFeedback({
              type: 'success',
              message: 'Subscription assigned successfully.',
            });
            loadSubscriptions();
            loadStats();
          }}
        />

        <TenantDetailsModal
          open={detailsModalOpen}
          onOpenChange={setDetailsModalOpen}
          tenant={tenantDetails}
          onSuspend={() => {
            setTenantToSuspend(tenantDetails);
            setSuspendModalOpen(true);
          }}
          onReactivate={() => {
            if (tenantDetails) handleReactivateTenant(tenantDetails.id);
          }}
          onRetry={() => {
            if (tenantDetails) handleRetryProvisioning(tenantDetails.id);
          }}
          onAssignSub={() => {
            setTenantForSub(tenantDetails);
            setSubscriptionModalOpen(true);
          }}
        />

        {/* Global Audit Log Details Dialog */}
        <Dialog open={auditDetailsModalOpen} onOpenChange={setAuditDetailsModalOpen}>
          <DialogHeader>
            <div className="flex items-center gap-2 mb-1">
              <Badge variant="outline" className="font-mono text-xs">
                {selectedGlobalLog?.event}
              </Badge>
              <span className="text-xs text-muted-foreground">ID #{selectedGlobalLog?.id}</span>
            </div>
            <DialogTitle>Central Audit Record Details</DialogTitle>
            <DialogDescription>
              Captured platform event with snapshot of state before and after execution.
            </DialogDescription>
          </DialogHeader>

          {selectedGlobalLog && (
            <div className="space-y-4 my-2 text-xs">
              <div className="grid grid-cols-2 gap-2 bg-muted/40 p-3 rounded-md border border-border">
                <div>
                  <span className="font-semibold text-foreground">Tenant: </span>
                  <span className="font-mono text-muted-foreground">{selectedGlobalLog.tenant_id ?? 'None (Platform-wide)'}</span>
                </div>
                <div>
                  <span className="font-semibold text-foreground">Actor ID: </span>
                  <span className="font-mono text-muted-foreground">{selectedGlobalLog.user_id ?? 'System'}</span>
                </div>
                <div>
                  <span className="font-semibold text-foreground">Target Type: </span>
                  <span className="font-mono text-muted-foreground">{selectedGlobalLog.auditable_type ?? '—'}</span>
                </div>
                <div>
                  <span className="font-semibold text-foreground">Target ID: </span>
                  <span className="font-mono text-muted-foreground">{selectedGlobalLog.auditable_id ?? '—'}</span>
                </div>
                <div>
                  <span className="font-semibold text-foreground">IP Address: </span>
                  <span className="font-mono text-muted-foreground">{selectedGlobalLog.ip_address ?? '—'}</span>
                </div>
                <div>
                  <span className="font-semibold text-foreground">Date: </span>
                  <span className="text-muted-foreground">{selectedGlobalLog.created_at ? new Date(selectedGlobalLog.created_at).toLocaleString() : '—'}</span>
                </div>
              </div>

              {selectedGlobalLog.old_values && (
                <div>
                  <h4 className="font-semibold text-foreground mb-1">Previous Values (Before):</h4>
                  <pre className="p-3 bg-muted/50 rounded-md border border-border overflow-x-auto font-mono text-[11px] text-rose-600 dark:text-rose-400">
                    {JSON.stringify(selectedGlobalLog.old_values, null, 2)}
                  </pre>
                </div>
              )}

              {selectedGlobalLog.new_values && (
                <div>
                  <h4 className="font-semibold text-foreground mb-1">New Values (After):</h4>
                  <pre className="p-3 bg-muted/50 rounded-md border border-border overflow-x-auto font-mono text-[11px] text-emerald-600 dark:text-emerald-400">
                    {JSON.stringify(selectedGlobalLog.new_values, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setAuditDetailsModalOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </Dialog>
      </div>
    </AdminLayout>
  );
}
