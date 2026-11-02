import { useState } from 'react';
import {
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AlertCircle, Loader2 } from 'lucide-react';
import { superAdminApi } from '../api/superAdminApi';
import type { SuperAdminTenantItem } from '../types/superAdmin.types';

interface SuspendTenantModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: SuperAdminTenantItem | null;
  onSuccess: (updated: SuperAdminTenantItem) => void;
}

export function SuspendTenantModal({
  open,
  onOpenChange,
  tenant,
  onSuccess,
}: SuspendTenantModalProps) {
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!tenant) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      const res = await superAdminApi.suspendTenant(tenant.id, {
        reason: reason.trim() || undefined,
      });
      onSuccess(res.data);
      onOpenChange(false);
      setReason('');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to suspend tenant.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-md">
      <form onSubmit={handleSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-destructive">
              <AlertCircle className="h-5 w-5" />
              Suspend Tenant: {tenant.name}
            </DialogTitle>
            <DialogDescription>
              Suspending this tenant will immediately lock access for all users on{' '}
              <span className="font-semibold text-foreground">
                {tenant.primary_domain || `${tenant.slug}.nexuserp.test`}
              </span>
              . The tenant database will remain intact.
            </DialogDescription>
          </DialogHeader>

          {error && (
            <div className="rounded-md bg-destructive/15 p-3 text-sm text-destructive font-medium">
              {error}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="suspend-reason">Reason for Suspension (Optional)</Label>
            <Input
              id="suspend-reason"
              placeholder="e.g. Non-payment, violation of terms, requested by owner"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </div>

          <DialogFooter className="gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="destructive"
              disabled={submitting}
              className="gap-2"
            >
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Confirm Suspension
            </Button>
          </DialogFooter>
        </form>
    </Dialog>
  );
}
