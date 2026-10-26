import React, { useEffect, useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Dialog, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Can } from '@/features/auth/components/Can';
import type { SharedProps } from '@/shared/types/auth';
import {
  UserPlus,
  Shield,
  UserCheck,
  UserX,
  AlertCircle,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  RefreshCw,
} from 'lucide-react';

interface TenantUserItem {
  id: string;
  name: string;
  email: string;
  status: 'active' | 'deactivated';
  roles: string[];
  email_verified_at: string | null;
  created_at: string | null;
}

interface UsersApiResponse {
  success: boolean;
  message?: string;
  data: TenantUserItem[];
  meta?: {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
  };
}

const ROLES = [
  { value: 'Admin', label: 'Admin (Full Tenant Management)' },
  { value: 'Accountant', label: 'Accountant (Financials & Reports)' },
  { value: 'WarehouseManager', label: 'Warehouse Manager (Stock & Goods Receipt)' },
  { value: 'SalesRep', label: 'Sales Rep (Quotations & Customers)' },
  { value: 'Staff', label: 'Staff (Standard Access)' },
];

export default function UsersIndex() {
  const { props } = usePage<SharedProps>();
  const currentUserId = props.auth?.user?.id;

  const [users, setUsers] = useState<TenantUserItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalUsers, setTotalUsers] = useState(0);

  // Invite modal state
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteName, setInviteName] = useState('');
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState('Staff');
  const [inviteLoading, setInviteLoading] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);

  // Notifications
  const [notification, setNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const fetchUsers = useCallback(async (targetPage = 1) => {
    setLoading(true);
    try {
      const response = await fetch(`/api/v1/users?page=${targetPage}`, {
        headers: { Accept: 'application/json' },
      });
      const data = (await response.json()) as UsersApiResponse;
      if (data.success && Array.isArray(data.data)) {
        setUsers(data.data);
        if (data.meta) {
          setPage(data.meta.current_page);
          setTotalPages(data.meta.last_page);
          setTotalUsers(data.meta.total);
        }
      }
    } catch {
      setNotification({ type: 'error', message: 'Failed to load user list.' });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchUsers(1);
  }, [fetchUsers]);

  const handleInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    setInviteError(null);
    setInviteLoading(true);

    try {
      const response = await fetch('/api/v1/users/invite', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          name: inviteName,
          email: inviteEmail,
          role: inviteRole,
        }),
      });

      const data = (await response.json()) as { success: boolean; message?: string };

      if (!response.ok || !data.success) {
        setInviteError(data.message || 'Failed to send invite.');
        setInviteLoading(false);
        return;
      }

      setInviteOpen(false);
      setInviteName('');
      setInviteEmail('');
      setInviteRole('Staff');
      setNotification({ type: 'success', message: 'Team member invited successfully.' });
      fetchUsers(page);
    } catch {
      setInviteError('Network error. Please try again.');
    } finally {
      setInviteLoading(false);
    }
  };

  const handleStatusToggle = async (user: TenantUserItem) => {
    const newStatus = user.status === 'active' ? 'deactivated' : 'active';
    try {
      const response = await fetch(`/api/v1/users/${user.id}/status`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ status: newStatus }),
      });

      const data = (await response.json()) as { success: boolean; message?: string };

      if (!response.ok || !data.success) {
        setNotification({ type: 'error', message: data.message || 'Failed to update user status.' });
        return;
      }

      setNotification({
        type: 'success',
        message: `User ${newStatus === 'active' ? 'activated' : 'deactivated'} successfully.`,
      });
      fetchUsers(page);
    } catch {
      setNotification({ type: 'error', message: 'Network error. Please try again.' });
    }
  };

  const handleRoleChange = async (userId: string, newRole: string) => {
    try {
      const response = await fetch(`/api/v1/users/${userId}/role`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ role: newRole }),
      });

      const data = (await response.json()) as { success: boolean; message?: string };

      if (!response.ok || !data.success) {
        setNotification({ type: 'error', message: data.message || 'Failed to update user role.' });
        return;
      }

      setNotification({ type: 'success', message: 'User role updated successfully.' });
      fetchUsers(page);
    } catch {
      setNotification({ type: 'error', message: 'Network error. Please try again.' });
    }
  };

  return (
    <AppLayout
      title="Team Members & Roles"
      activeNav="users"
      breadcrumbs={[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Users' }]}
    >
      <div className="space-y-6">
        {notification && (
          <Alert variant={notification.type === 'success' ? 'success' : 'destructive'}>
            {notification.type === 'success' ? (
              <CheckCircle2 className="h-4 w-4" />
            ) : (
              <AlertCircle className="h-4 w-4" />
            )}
            <AlertDescription className="flex items-center justify-between">
              <span>{notification.message}</span>
              <button
                type="button"
                onClick={() => setNotification(null)}
                className="text-xs underline ml-4 cursor-pointer"
              >
                Dismiss
              </button>
            </AlertDescription>
          </Alert>
        )}

        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h2 className="text-xl font-bold tracking-tight text-foreground">Organization Users</h2>
            <p className="text-sm text-muted-foreground mt-0.5">
              Manage member roles, permissions, and account activation states.
            </p>
          </div>

          <Can permission="users.manage">
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => fetchUsers(page)}
                disabled={loading}
              >
                <RefreshCw className={`h-4 w-4 mr-1.5 ${loading ? 'animate-spin' : ''}`} />
                Refresh
              </Button>

              <Button
                size="sm"
                onClick={() => setInviteOpen(true)}
                className="flex items-center gap-1.5"
              >
                <UserPlus className="h-4 w-4" />
                Invite Member
              </Button>
            </div>
          </Can>
        </div>

        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-base font-semibold">Active Members</CardTitle>
                <CardDescription className="text-xs">
                  {totalUsers} user{totalUsers !== 1 ? 's' : ''} registered in this organization
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-muted/40 text-xs font-semibold uppercase text-muted-foreground">
                  <tr>
                    <th className="px-6 py-3">User</th>
                    <th className="px-6 py-3">Role</th>
                    <th className="px-6 py-3">Status</th>
                    <th className="px-6 py-3">Email Verified</th>
                    <th className="px-6 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {users.length === 0 && !loading && (
                    <tr>
                      <td colSpan={5} className="px-6 py-8 text-center text-muted-foreground text-sm">
                        No team members found.
                      </td>
                    </tr>
                  )}

                  {users.map((userItem) => {
                    const isSelf = userItem.id === currentUserId;
                    const isOwner = userItem.roles.includes('Owner');

                    return (
                      <tr key={userItem.id} className="hover:bg-muted/20 transition-colors">
                        <td className="px-6 py-4">
                          <div className="flex flex-col">
                            <span className="font-medium text-foreground flex items-center gap-1.5">
                              {userItem.name}
                              {isSelf && (
                                <span className="text-[10px] bg-primary/10 text-primary px-1.5 py-0.5 rounded font-normal">
                                  You
                                </span>
                              )}
                            </span>
                            <span className="text-xs text-muted-foreground">{userItem.email}</span>
                          </div>
                        </td>

                        <td className="px-6 py-4">
                          <Can
                            permission="users.manage"
                            fallback={
                              <Badge variant="secondary" className="font-normal text-xs">
                                {userItem.roles[0] || 'Member'}
                              </Badge>
                            }
                          >
                            {isOwner ? (
                              <Badge variant="default" className="font-semibold text-xs flex items-center gap-1 w-fit">
                                <Shield className="h-3 w-3" />
                                Owner
                              </Badge>
                            ) : (
                              <select
                                value={userItem.roles[0] || 'Staff'}
                                onChange={(e) => handleRoleChange(userItem.id, e.target.value)}
                                className="h-8 rounded-md border border-input bg-card px-2.5 py-1 text-xs text-foreground shadow-xs hover:border-muted-foreground/50 focus:outline-hidden focus:ring-1 focus:ring-ring"
                              >
                                {ROLES.map((r) => (
                                  <option key={r.value} value={r.value}>
                                    {r.value}
                                  </option>
                                ))}
                              </select>
                            )}
                          </Can>
                        </td>

                        <td className="px-6 py-4">
                          {userItem.status === 'active' ? (
                            <Badge variant="outline" className="text-emerald-700 bg-emerald-50 border-emerald-300 dark:text-emerald-400 dark:bg-emerald-950/30 text-xs">
                              Active
                            </Badge>
                          ) : (
                            <Badge variant="outline" className="text-rose-700 bg-rose-50 border-rose-300 dark:text-rose-400 dark:bg-rose-950/30 text-xs">
                              Deactivated
                            </Badge>
                          )}
                        </td>

                        <td className="px-6 py-4 text-xs text-muted-foreground">
                          {userItem.email_verified_at ? (
                            <span className="text-emerald-600 font-medium">Verified</span>
                          ) : (
                            <span className="text-amber-600">Pending</span>
                          )}
                        </td>

                        <td className="px-6 py-4 text-right">
                          <Can permission="users.manage">
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              disabled={isSelf || isOwner}
                              onClick={() => handleStatusToggle(userItem)}
                              className="text-xs h-8"
                              title={isSelf ? 'Cannot deactivate your own account' : isOwner ? 'Cannot deactivate sole organization owner' : undefined}
                            >
                              {userItem.status === 'active' ? (
                                <>
                                  <UserX className="h-3.5 w-3.5 mr-1 text-destructive" />
                                  Deactivate
                                </>
                              ) : (
                                <>
                                  <UserCheck className="h-3.5 w-3.5 mr-1 text-emerald-600" />
                                  Activate
                                </>
                              )}
                            </Button>
                          </Can>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {/* Pagination footer */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between border-t border-border px-6 py-3">
                <span className="text-xs text-muted-foreground">
                  Page {page} of {totalPages}
                </span>
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => fetchUsers(page - 1)}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= totalPages}
                    onClick={() => fetchUsers(page + 1)}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Invite Member Dialog */}
      <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
        <DialogHeader>
          <DialogTitle>Invite Team Member</DialogTitle>
          <DialogDescription>
            Add a new member to this organization. Package user limits apply.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleInvite} className="space-y-4">
          {inviteError && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{inviteError}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="invite-name" required>
              Full name
            </Label>
            <Input
              id="invite-name"
              value={inviteName}
              onChange={(e) => setInviteName(e.target.value)}
              placeholder="e.g. Alex Morgan"
              required
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="invite-email" required>
              Email address
            </Label>
            <Input
              id="invite-email"
              type="email"
              value={inviteEmail}
              onChange={(e) => setInviteEmail(e.target.value)}
              placeholder="alex@company.com"
              required
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="invite-role" required>
              Role & Permissions
            </Label>
            <select
              id="invite-role"
              value={inviteRole}
              onChange={(e) => setInviteRole(e.target.value)}
              className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm text-foreground shadow-xs hover:border-muted-foreground/50 focus:outline-hidden focus:ring-1 focus:ring-ring"
            >
              {ROLES.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </select>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setInviteOpen(false)}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={inviteLoading}>
              {inviteLoading ? 'Sending invite...' : 'Invite member'}
            </Button>
          </DialogFooter>
        </form>
      </Dialog>
    </AppLayout>
  );
}
