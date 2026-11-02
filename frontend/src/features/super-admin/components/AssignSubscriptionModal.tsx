import { useState, useEffect } from 'react';
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
import { Loader2, CreditCard } from 'lucide-react';
import { superAdminApi } from '../api/superAdminApi';
import type {
  SuperAdminTenantItem,
  SuperAdminPackageItem,
  SuperAdminSubscriptionItem,
} from '../types/superAdmin.types';

interface AssignSubscriptionModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: SuperAdminTenantItem | null;
  packages: SuperAdminPackageItem[];
  onSuccess: (sub: SuperAdminSubscriptionItem) => void;
}

export function AssignSubscriptionModal({
  open,
  onOpenChange,
  tenant,
  packages,
  onSuccess,
}: AssignSubscriptionModalProps) {
  const [selectedPackageId, setSelectedPackageId] = useState<number | ''>('');
  const [startsAt, setStartsAt] = useState(() => new Date().toISOString().split('T')[0] ?? '');
  const [endsAt, setEndsAt] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (packages.length > 0 && !selectedPackageId) {
      setSelectedPackageId(packages[0]?.id ?? '');
    }
  }, [packages, selectedPackageId]);

  if (!tenant) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedPackageId) return;

    setError(null);
    setSubmitting(true);

    try {
      const res = await superAdminApi.assignSubscription({
        tenant_id: tenant.id,
        package_id: Number(selectedPackageId),
        starts_at: startsAt,
        ends_at: endsAt || undefined,
      });

      onSuccess(res.data);
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to assign subscription.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-md">
      <form onSubmit={handleSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <CreditCard className="h-5 w-5 text-primary" />
              Assign Subscription: {tenant.name}
            </DialogTitle>
            <DialogDescription>
              Assign or upgrade the active subscription tier for {tenant.slug}. Any current active tier will be superseded.
            </DialogDescription>
          </DialogHeader>

          {error && (
            <div className="rounded-md bg-destructive/15 p-3 text-sm text-destructive font-medium">
              {error}
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="sub-package">Select Package</Label>
            <select
              id="sub-package"
              required
              value={selectedPackageId}
              onChange={(e) => setSelectedPackageId(Number(e.target.value))}
              className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            >
              {packages.map((pkg) => (
                <option key={pkg.id} value={pkg.id}>
                  {pkg.name} (${pkg.price_formatted}/mo) — {pkg.max_users} users, {pkg.max_warehouses} wh, {pkg.max_invoices_per_month} inv/mo
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="sub-starts-at">Starts At</Label>
              <Input
                id="sub-starts-at"
                type="date"
                required
                value={startsAt}
                onChange={(e) => setStartsAt(e.target.value)}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="sub-ends-at">Ends At (Optional)</Label>
              <Input
                id="sub-ends-at"
                type="date"
                value={endsAt}
                onChange={(e) => setEndsAt(e.target.value)}
              />
            </div>
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
            <Button type="submit" variant="primary" disabled={submitting} className="gap-2">
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Save Subscription
            </Button>
          </DialogFooter>
        </form>
    </Dialog>
  );
}
