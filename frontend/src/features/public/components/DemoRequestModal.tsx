import * as React from 'react';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Database, CheckCircle2, AlertCircle, ArrowRight, Loader2 } from 'lucide-react';

export interface DemoRequestModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedTierId?: string;
}

export function DemoRequestModal({
  open,
  onOpenChange,
  selectedTierId = 'distribution',
}: DemoRequestModalProps) {
  const [tenantName, setTenantName] = React.useState('');
  const [subdomain, setSubdomain] = React.useState('');
  const [adminEmail, setAdminEmail] = React.useState('');
  const [currency, setCurrency] = React.useState('USD');

  const [errors, setErrors] = React.useState<{ [key: string]: string }>({});
  const [shake, setShake] = React.useState(false);
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [provisionStep, setProvisionStep] = React.useState<number>(0);
  const [isSuccess, setIsSuccess] = React.useState(false);

  // Auto-fill subdomain based on tenant name
  const handleTenantNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const name = e.target.value;
    setTenantName(name);
    const slug = name
      .toLowerCase()
      .replace(/[^a-z0-9]/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '');
    setSubdomain(slug);
    if (errors.tenantName) {
      setErrors((prev) => ({ ...prev, tenantName: '' }));
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const newErrors: { [key: string]: string } = {};
    if (!tenantName.trim()) {
      newErrors.tenantName = 'Organization name is required';
    }
    if (!subdomain.trim() || subdomain.length < 3) {
      newErrors.subdomain = 'Subdomain must be at least 3 alphanumeric characters';
    }
    if (!adminEmail.trim() || !adminEmail.includes('@')) {
      newErrors.adminEmail = 'A valid business email address is required';
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      setShake(true);
      setTimeout(() => setShake(false), 300);
      return;
    }

    setErrors({});
    setIsSubmitting(true);
    setProvisionStep(1);

    // Simulate database-per-tenant provisioning lifecycle
    setTimeout(() => {
      setProvisionStep(2);
      setTimeout(() => {
        setProvisionStep(3);
        setTimeout(() => {
          setIsSubmitting(false);
          setIsSuccess(true);
        }, 600);
      }, 700);
    }, 600);
  };

  const handleReset = () => {
    setTenantName('');
    setSubdomain('');
    setAdminEmail('');
    setErrors({});
    setIsSuccess(false);
    setProvisionStep(0);
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="sm:max-w-lg">
      <DialogHeader>
        <div className="flex items-center gap-2 mb-1">
          <Database className="h-5 w-5 text-secondary" />
          <DialogTitle>Provision Isolated Tenant Sandbox</DialogTitle>
        </div>
        <DialogDescription>
          Spin up an isolated PostgreSQL database workspace with pre-configured Chart of Accounts, sample inventory items, and strict double-entry GL ledger ({selectedTierId.toUpperCase()} Tier).
        </DialogDescription>
      </DialogHeader>

      {!isSuccess ? (
        <form onSubmit={handleSubmit} className="space-y-4 pt-2">
          {/* Organization Name */}
          <div className="space-y-1.5">
            <Label htmlFor="tenant-name" className="text-xs font-semibold text-foreground">
              Organization Legal Name *
            </Label>
            <Input
              id="tenant-name"
              placeholder="e.g. Apex Industrial Distribution LLC"
              value={tenantName}
              onChange={handleTenantNameChange}
              error={!!errors.tenantName}
              shake={shake && !!errors.tenantName}
              disabled={isSubmitting}
            />
            {errors.tenantName && (
              <p className="flex items-center gap-1 text-xs text-destructive mt-1 font-medium">
                <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                <span>{errors.tenantName}</span>
              </p>
            )}
          </div>

          {/* Subdomain Input */}
          <div className="space-y-1.5">
            <Label htmlFor="subdomain" className="text-xs font-semibold text-foreground">
              Dedicated Workspace Subdomain *
            </Label>
            <div className="flex items-center rounded-md border border-input bg-card shadow-xs focus-within:ring-2 focus-within:ring-ring focus-within:border-transparent">
              <input
                id="subdomain"
                type="text"
                placeholder="apex-industrial"
                value={subdomain}
                onChange={(e) => {
                  setSubdomain(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''));
                  if (errors.subdomain) setErrors((prev) => ({ ...prev, subdomain: '' }));
                }}
                disabled={isSubmitting}
                className="flex-1 h-9 px-3 text-sm font-mono text-foreground bg-transparent focus:outline-none"
              />
              <span className="px-3 text-xs font-mono text-muted-foreground bg-muted/60 h-9 flex items-center border-l border-border/60">
                .nexuserp.test
              </span>
            </div>
            {errors.subdomain && (
              <p className="flex items-center gap-1 text-xs text-destructive mt-1 font-medium">
                <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                <span>{errors.subdomain}</span>
              </p>
            )}
          </div>

          {/* Admin Email */}
          <div className="space-y-1.5">
            <Label htmlFor="admin-email" className="text-xs font-semibold text-foreground">
              Workspace Administrator Email *
            </Label>
            <Input
              id="admin-email"
              type="email"
              placeholder="ops.director@apexindustrial.com"
              value={adminEmail}
              onChange={(e) => {
                setAdminEmail(e.target.value);
                if (errors.adminEmail) setErrors((prev) => ({ ...prev, adminEmail: '' }));
              }}
              error={!!errors.adminEmail}
              shake={shake && !!errors.adminEmail}
              disabled={isSubmitting}
            />
            {errors.adminEmail && (
              <p className="flex items-center gap-1 text-xs text-destructive mt-1 font-medium">
                <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                <span>{errors.adminEmail}</span>
              </p>
            )}
          </div>

          {/* Currency Selection */}
          <div className="space-y-1.5">
            <Label htmlFor="currency" className="text-xs font-semibold text-foreground">
              Primary Ledger Currency (ISO 4217)
            </Label>
            <select
              id="currency"
              value={currency}
              onChange={(e) => setCurrency(e.target.value)}
              disabled={isSubmitting}
              className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm font-mono text-foreground shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <option value="USD">USD ($) - United States Dollar</option>
              <option value="EUR">EUR (€) - Euro</option>
              <option value="GBP">GBP (£) - British Pound Sterling</option>
              <option value="CAD">CAD ($) - Canadian Dollar</option>
              <option value="AUD">AUD ($) - Australian Dollar</option>
            </select>
          </div>

          {/* Provisioning Progress Stepper */}
          {isSubmitting && (
            <div className="rounded-md border border-border bg-muted/30 p-3 space-y-2 text-xs font-mono">
              <div className="flex items-center gap-2 text-foreground">
                <Loader2 className="h-3.5 w-3.5 animate-spin text-secondary" />
                <span className="font-semibold">Provisioning Dedicated PostgreSQL Database...</span>
              </div>
              <div className="space-y-1 text-muted-foreground pl-5.5 text-[11px]">
                <div className={provisionStep >= 1 ? 'text-success font-medium' : ''}>
                  ✓ Provisioner: Created schema pg_tenant_{subdomain || 'demo'}
                </div>
                <div className={provisionStep >= 2 ? 'text-success font-medium' : ''}>
                  {provisionStep >= 2 ? '✓' : '•'} Migrated 47 tables & executed Chart of Accounts seeder
                </div>
                <div className={provisionStep >= 3 ? 'text-success font-medium' : ''}>
                  {provisionStep >= 3 ? '✓' : '•'} Registered Spatie SuperAdmin and Owner credentials
                </div>
              </div>
            </div>
          )}

          <DialogFooter className="pt-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={isSubmitting}
              className="font-semibold"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  <span>Provisioning Database...</span>
                </>
              ) : (
                <>
                  <span>Create Workspace</span>
                  <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      ) : (
        /* Success State */
        <div className="space-y-5 pt-3 text-center sm:text-left">
          <div className="flex items-center gap-3 p-4 rounded-lg bg-success-bg border border-success/30 text-success">
            <CheckCircle2 className="h-6 w-6 shrink-0" />
            <div>
              <h4 className="font-semibold text-sm">Tenant Database Successfully Provisioned</h4>
              <p className="text-xs text-success/90">
                Isolated PostgreSQL instance is online and healthy.
              </p>
            </div>
          </div>

          <div className="rounded-md border border-border bg-card p-4 space-y-2 font-mono text-xs">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Workspace URL:</span>
              <span className="font-bold text-foreground">http://{subdomain}.nexuserp.test:8000</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Admin User:</span>
              <span className="font-semibold text-foreground">{adminEmail}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Base Currency:</span>
              <span className="font-semibold text-foreground">{currency}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">General Ledger Status:</span>
              <span className="font-bold text-success">INITIALIZED (BALANCED)</span>
            </div>
          </div>

          <DialogFooter>
            <Button variant="primary" className="w-full sm:w-auto" onClick={handleReset}>
              Enter Workspace Sandbox
            </Button>
          </DialogFooter>
        </div>
      )}
    </Dialog>
  );
}
