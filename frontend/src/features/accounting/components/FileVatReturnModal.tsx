import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { accountingApi } from '../api/accountingApi';
import type { VatCalculationResult } from '../types/accounting.types';
import { AlertCircle, CheckCircle, FileText } from 'lucide-react';

interface FileVatReturnModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  calcResult: VatCalculationResult | null;
  onSuccess: () => void;
  currency?: string;
}

export function FileVatReturnModal({
  open,
  onOpenChange,
  calcResult,
  onSuccess,
  currency = 'USD',
}: FileVatReturnModalProps) {
  const [notes, setNotes] = useState<string>('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!calcResult) {
    return null;
  }

  const formatMoney = (cents: number) => {
    return (cents / 100).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  };

  const isNetPayable = calcResult.summary.net_vat_payable_cents >= 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await accountingApi.fileVatReturn({
        fiscal_period_id: calcResult.fiscal_period?.id ?? null,
        from_date: calcResult.from_date,
        to_date: calcResult.to_date,
        notes: notes.trim() || null,
      });

      onSuccess();
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to file VAT return.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <FileText className="h-5 w-5 text-primary" />
            <DialogTitle>File Official VAT Return</DialogTitle>
          </div>
          <DialogDescription>
            Record and finalize statutory VAT return for period{' '}
            <span className="font-semibold text-foreground">
              {calcResult.fiscal_period?.name ?? `${calcResult.from_date} to ${calcResult.to_date}`}
            </span>
            . Once filed, the return snapshot is immutable and stored for official tax audit.
          </DialogDescription>
        </DialogHeader>

        {error && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <div className="rounded-lg border border-border bg-muted/40 p-3 space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-muted-foreground">Output VAT (Sales):</span>
            <span className="font-semibold tabular-nums text-foreground">
              {currency} {formatMoney(calcResult.summary.total_output_vat_cents)}
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-muted-foreground">Input VAT (Purchases):</span>
            <span className="font-semibold tabular-nums text-foreground">
              {currency} {formatMoney(calcResult.summary.total_input_vat_cents)}
            </span>
          </div>
          <div className="border-t border-border pt-2 flex justify-between font-bold">
            <span>{isNetPayable ? 'Net VAT Payable:' : 'Net VAT Refundable:'}</span>
            <span className={`tabular-nums ${isNetPayable ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
              {currency} {formatMoney(Math.abs(calcResult.summary.net_vat_payable_cents))}
            </span>
          </div>
          <div className="flex items-center gap-1.5 pt-1 text-xs">
            {calcResult.ledger_reconciliation.is_reconciled ? (
              <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium">
                <CheckCircle className="h-3.5 w-3.5" /> 100% Reconciled with General Ledger
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-rose-600 dark:text-rose-400 font-medium">
                <AlertCircle className="h-3.5 w-3.5" /> Discrepancy detected with General Ledger
              </span>
            )}
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="notes">Filing Notes / Statutory Reference (Optional)</Label>
          <textarea
            id="notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="e.g. Inland Revenue E-filing confirmation #IR-2026-9901..."
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            rows={3}
          />
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Filing...' : 'Confirm & File Return'}
          </Button>
        </DialogFooter>
      </form>
    </Dialog>
  );
}
