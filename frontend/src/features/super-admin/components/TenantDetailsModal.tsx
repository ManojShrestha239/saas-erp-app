import {
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Building2, Globe, Database, Mail, Calendar, AlertTriangle } from 'lucide-react';
import type { SuperAdminTenantItem } from '../types/superAdmin.types';

interface TenantDetailsModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: SuperAdminTenantItem | null;
  onSuspend?: () => void;
  onReactivate?: () => void;
  onRetry?: () => void;
  onAssignSub?: () => void;
}

export function TenantDetailsModal({
  open,
  onOpenChange,
  tenant,
  onSuspend,
  onReactivate,
  onRetry,
  onAssignSub,
}: TenantDetailsModalProps) {
  if (!tenant) return null;

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
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-lg">
      <DialogHeader>
          <div className="flex items-center justify-between">
            <DialogTitle className="flex items-center gap-2">
              <Building2 className="h-5 w-5 text-primary" />
              {tenant.name}
            </DialogTitle>
            {getStatusBadge(tenant.status)}
          </div>
          <DialogDescription>
            Slug: <span className="font-mono font-medium text-foreground">{tenant.slug}</span>
          </DialogDescription>
        </DialogHeader>

        {tenant.status === 'provisioning_failed' && tenant.provisioning_error && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
            <div className="flex items-center gap-1.5 font-semibold">
              <AlertTriangle className="h-4 w-4" /> Provisioning Error
            </div>
            <p className="mt-1 font-mono text-xs break-all">{tenant.provisioning_error}</p>
          </div>
        )}

        <div className="space-y-3 rounded-xl border border-border bg-card p-4 text-sm">
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-muted-foreground">
              <Database className="h-4 w-4" /> Database Name
            </span>
            <span className="font-mono font-medium text-foreground">{tenant.database_name}</span>
          </div>

          <div className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-muted-foreground">
              <Mail className="h-4 w-4" /> Owner Email
            </span>
            <span className="font-medium text-foreground">{tenant.owner_email || 'Not specified'}</span>
          </div>

          <div className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-muted-foreground">
              <Calendar className="h-4 w-4" /> Registered
            </span>
            <span className="text-foreground">
              {tenant.created_at ? new Date(tenant.created_at).toLocaleDateString() : 'N/A'}
            </span>
          </div>

          <div className="pt-2 border-t border-border">
            <span className="flex items-center gap-2 text-xs font-semibold uppercase text-muted-foreground mb-2">
              <Globe className="h-4 w-4" /> Bound Domains
            </span>
            <div className="flex flex-wrap gap-1.5">
              {tenant.domains.map((dom) => (
                <Badge key={dom} variant="secondary" className="font-mono text-xs">
                  {dom}
                </Badge>
              ))}
            </div>
          </div>
        </div>

        <DialogFooter className="flex flex-wrap gap-2 sm:justify-between">
          <div className="flex items-center gap-2">
            {tenant.status === 'provisioning_failed' && onRetry && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  onRetry();
                  onOpenChange(false);
                }}
                className="border-primary/40 text-primary"
              >
                Retry Provisioning
              </Button>
            )}

            {tenant.status === 'active' && onSuspend && (
              <Button
                type="button"
                variant="destructive"
                size="sm"
                onClick={() => {
                  onOpenChange(false);
                  onSuspend();
                }}
              >
                Suspend
              </Button>
            )}

            {tenant.status === 'suspended' && onReactivate && (
              <Button
                type="button"
                variant="primary"
                size="sm"
                onClick={() => {
                  onOpenChange(false);
                  onReactivate();
                }}
              >
                Reactivate
              </Button>
            )}

            {onAssignSub && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  onOpenChange(false);
                  onAssignSub();
                }}
              >
                Subscription
              </Button>
            )}
          </div>

          <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
    </Dialog>
  );
}
