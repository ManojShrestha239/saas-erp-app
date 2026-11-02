import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { accountingApi } from '../api/accountingApi';
import type { VatReturnItem } from '../types/accounting.types';
import { CheckCircle, AlertCircle, Download, History, Loader2 } from 'lucide-react';

interface FiledReturnsModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  currency?: string;
}

export function FiledReturnsModal({
  open,
  onOpenChange,
  currency = 'USD',
}: FiledReturnsModalProps) {
  const [returns, setReturns] = useState<VatReturnItem[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (open) {
      setLoading(true);
      accountingApi
        .getVatReturns()
        .then((res) => {
          setReturns(res.data || []);
        })
        .catch(() => {
          setReturns([]);
        })
        .finally(() => {
          setLoading(false);
        });
    }
  }, [open]);

  const formatMoney = (cents: number) => {
    return (cents / 100).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="space-y-4">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <History className="h-5 w-5 text-primary" />
            <DialogTitle>Filed VAT Returns History</DialogTitle>
          </div>
          <DialogDescription>
            Historical record of finalized statutory VAT returns. All filed snapshots are immutable and preserved for audit.
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="flex items-center justify-center py-12 text-muted-foreground">
            <Loader2 className="h-6 w-6 animate-spin mr-2" /> Loading filed returns...
          </div>
        ) : returns.length === 0 ? (
          <div className="py-8 text-center text-sm text-muted-foreground">
            No filed VAT returns found. Calculate an interim return and click &quot;File Return&quot; to snapshot.
          </div>
        ) : (
          <div className="max-h-[60vh] overflow-y-auto space-y-3">
            {returns.map((ret) => (
              <div
                key={ret.id}
                className="rounded-lg border border-border bg-card p-3.5 space-y-2 hover:border-primary/40 transition-colors"
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span className="font-mono font-bold text-sm text-foreground">
                      {ret.return_number}
                    </span>
                    <Badge variant="outline" className="border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                      Filed
                    </Badge>
                  </div>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-8 gap-1.5 text-xs"
                    onClick={() => {
                      const url = accountingApi.getPdfUrl({
                        from_date: ret.from_date,
                        to_date: ret.to_date,
                        fiscal_period_id: ret.fiscal_period_id,
                      });
                      window.open(url, '_blank');
                    }}
                  >
                    <Download className="h-3.5 w-3.5" /> PDF
                  </Button>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                  <div>
                    <span className="text-muted-foreground">Period:</span>
                    <div className="font-medium text-foreground">
                      {ret.fiscal_period?.name ?? `${ret.from_date} to ${ret.to_date}`}
                    </div>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Output VAT:</span>
                    <div className="font-medium tabular-nums text-foreground">
                      {currency} {formatMoney(ret.output_vat_cents)}
                    </div>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Input VAT:</span>
                    <div className="font-medium tabular-nums text-foreground">
                      {currency} {formatMoney(ret.input_vat_cents)}
                    </div>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Net Position:</span>
                    <div
                      className={`font-bold tabular-nums ${
                        ret.net_vat_cents >= 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'
                      }`}
                    >
                      {currency} {formatMoney(Math.abs(ret.net_vat_cents))}
                    </div>
                  </div>
                </div>

                <div className="flex items-center justify-between pt-1 border-t border-border text-xs text-muted-foreground">
                  <div>
                    Filed on {ret.filed_at ? new Date(ret.filed_at).toLocaleDateString() : '—'}
                    {ret.notes && <span className="ml-2 italic">&quot;{ret.notes}&quot;</span>}
                  </div>
                  <div>
                    {ret.is_reconciled ? (
                      <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                        <CheckCircle className="h-3 w-3" /> Reconciled
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-rose-600 dark:text-rose-400">
                        <AlertCircle className="h-3 w-3" /> Discrepancy
                      </span>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
      </div>
    </Dialog>
  );
}
