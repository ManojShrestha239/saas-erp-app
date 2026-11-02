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
import { Loader2, Package as PackageIcon } from 'lucide-react';
import { superAdminApi } from '../api/superAdminApi';
import type { SuperAdminPackageItem } from '../types/superAdmin.types';

interface PackageFormModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  pkg: SuperAdminPackageItem | null;
  onSuccess: (saved: SuperAdminPackageItem) => void;
}

export function PackageFormModal({
  open,
  onOpenChange,
  pkg,
  onSuccess,
}: PackageFormModalProps) {
  const isEditing = Boolean(pkg);

  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [maxUsers, setMaxUsers] = useState('5');
  const [maxWarehouses, setMaxWarehouses] = useState('2');
  const [maxInvoices, setMaxInvoices] = useState('250');
  const [priceDollars, setPriceDollars] = useState('49.00');

  const [multiWarehouse, setMultiWarehouse] = useState(false);
  const [apiAccess, setApiAccess] = useState(false);
  const [advancedReports, setAdvancedReports] = useState(false);
  const [prioritySupport, setPrioritySupport] = useState(false);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (pkg) {
      setName(pkg.name);
      setSlug(pkg.slug);
      setMaxUsers(String(pkg.max_users));
      setMaxWarehouses(String(pkg.max_warehouses));
      setMaxInvoices(String(pkg.max_invoices_per_month));
      setPriceDollars((pkg.price_cents / 100).toFixed(2));
      setMultiWarehouse(Boolean(pkg.features?.multi_warehouse));
      setApiAccess(Boolean(pkg.features?.api_access));
      setAdvancedReports(Boolean(pkg.features?.advanced_reports));
      setPrioritySupport(Boolean(pkg.features?.priority_support));
    } else {
      setName('');
      setSlug('');
      setMaxUsers('5');
      setMaxWarehouses('2');
      setMaxInvoices('250');
      setPriceDollars('49.00');
      setMultiWarehouse(false);
      setApiAccess(false);
      setAdvancedReports(false);
      setPrioritySupport(false);
    }
    setError(null);
  }, [pkg, open]);

  const handleNameChange = (val: string) => {
    setName(val);
    if (!isEditing) {
      setSlug(
        val
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-|-$/g, '')
      );
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      const priceCents = Math.round(parseFloat(priceDollars || '0') * 100);
      const features = {
        multi_warehouse: multiWarehouse,
        api_access: apiAccess,
        advanced_reports: advancedReports,
        priority_support: prioritySupport,
      };

      if (isEditing && pkg) {
        const res = await superAdminApi.updatePackage(pkg.id, {
          name,
          slug,
          max_users: parseInt(maxUsers, 10),
          max_warehouses: parseInt(maxWarehouses, 10),
          max_invoices_per_month: parseInt(maxInvoices, 10),
          features,
          price_cents: priceCents,
        });
        onSuccess(res.data);
      } else {
        const res = await superAdminApi.createPackage({
          name,
          slug,
          max_users: parseInt(maxUsers, 10),
          max_warehouses: parseInt(maxWarehouses, 10),
          max_invoices_per_month: parseInt(maxInvoices, 10),
          features,
          price_cents: priceCents,
        });
        onSuccess(res.data);
      }

      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save package.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-lg">
      <form onSubmit={handleSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <PackageIcon className="h-5 w-5 text-primary" />
              {isEditing ? `Edit Package: ${pkg?.name}` : 'Create Subscription Package'}
            </DialogTitle>
            <DialogDescription>
              Configure package limits, pricing, and feature flags enforced across customer tenants.
            </DialogDescription>
          </DialogHeader>

          {error && (
            <div className="rounded-md bg-destructive/15 p-3 text-sm text-destructive font-medium">
              {error}
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="pkg-name">Package Name</Label>
              <Input
                id="pkg-name"
                required
                value={name}
                onChange={(e) => handleNameChange(e.target.value)}
                placeholder="e.g. Professional"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="pkg-slug">Slug Identifier</Label>
              <Input
                id="pkg-slug"
                required
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                placeholder="e.g. professional"
              />
            </div>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="pkg-users">Max Users</Label>
              <Input
                id="pkg-users"
                type="number"
                min="1"
                required
                value={maxUsers}
                onChange={(e) => setMaxUsers(e.target.value)}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="pkg-warehouses">Max Warehouses</Label>
              <Input
                id="pkg-warehouses"
                type="number"
                min="1"
                required
                value={maxWarehouses}
                onChange={(e) => setMaxWarehouses(e.target.value)}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="pkg-invoices">Max Invoices / Mo</Label>
              <Input
                id="pkg-invoices"
                type="number"
                min="1"
                required
                value={maxInvoices}
                onChange={(e) => setMaxInvoices(e.target.value)}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="pkg-price">Monthly Price (USD)</Label>
            <Input
              id="pkg-price"
              type="number"
              step="0.01"
              min="0"
              required
              value={priceDollars}
              onChange={(e) => setPriceDollars(e.target.value)}
              placeholder="49.00"
            />
          </div>

          <div className="space-y-2 rounded-lg border border-border p-3 bg-muted/20">
            <Label className="text-xs font-semibold uppercase text-muted-foreground">
              Package Feature Toggles
            </Label>
            <div className="grid grid-cols-2 gap-2 text-sm">
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={multiWarehouse}
                  onChange={(e) => setMultiWarehouse(e.target.checked)}
                  className="rounded border-input text-primary focus:ring-primary"
                />
                <span>Multi-Warehouse</span>
              </label>

              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={apiAccess}
                  onChange={(e) => setApiAccess(e.target.checked)}
                  className="rounded border-input text-primary focus:ring-primary"
                />
                <span>API & Webhooks</span>
              </label>

              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={advancedReports}
                  onChange={(e) => setAdvancedReports(e.target.checked)}
                  className="rounded border-input text-primary focus:ring-primary"
                />
                <span>Advanced Reports</span>
              </label>

              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={prioritySupport}
                  onChange={(e) => setPrioritySupport(e.target.checked)}
                  className="rounded border-input text-primary focus:ring-primary"
                />
                <span>Priority Support</span>
              </label>
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
              {isEditing ? 'Save Changes' : 'Create Package'}
            </Button>
          </DialogFooter>
        </form>
    </Dialog>
  );
}
