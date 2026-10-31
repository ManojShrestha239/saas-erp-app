import { useState } from 'react';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { purchasingApi } from '../api/purchasingApi';
import type { SupplierBill } from '../types/purchasing.types';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import { AlertCircle, CheckCircle2, Loader2, ReceiptText } from 'lucide-react';

interface SupplierBillDetailModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  bill: SupplierBill | null;
  suppliers: ContactItem[];
  canPostBill: boolean;
  onSuccess: (message: string) => void;
}

function formatMoneyCents(cents: number): string {
  const amount = cents / 100;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'NPR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
    .format(amount)
    .replace('NPR', 'NPR ');
}

export function SupplierBillDetailModal({
  open,
  onOpenChange,
  bill,
  suppliers,
  canPostBill,
  onSuccess,
}: SupplierBillDetailModalProps) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!bill) return null;

  const supplier = suppliers.find((s) => s.id === bill.supplier_id);

  const handlePost = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await purchasingApi.postBill(bill.id);
      onSuccess(`Supplier Bill "${bill.bill_number}" posted to Accounts Payable successfully.`);
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to post supplier bill.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div className="bg-background rounded-lg border shadow-lg w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b">
            <div className="flex items-center gap-3">
              <ReceiptText className="h-6 w-6 text-primary" />
              <div>
                <DialogTitle className="flex items-center gap-2 text-lg">
                  Supplier Bill {bill.bill_number}
                  <Badge variant="secondary" className="capitalize text-xs font-normal">
                    {bill.status_label}
                  </Badge>
                </DialogTitle>
                <DialogDescription className="text-xs">
                  From <strong className="text-foreground">{supplier?.name || bill.supplier_id}</strong> •
                  Invoice Ref: <span className="font-mono">{bill.supplier_invoice_number || 'N/A'}</span>
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="flex-1 overflow-y-auto p-6 space-y-4">
            {error && (
              <Alert variant="destructive" className="py-2.5">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription className="text-xs">{error}</AlertDescription>
              </Alert>
            )}

            {/* Bill Metadata Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Bill Date</span>
                <span className="text-xs font-semibold text-foreground">{bill.bill_date}</span>
              </div>
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Payment Due</span>
                <span className="text-xs font-semibold text-foreground">{bill.due_date}</span>
              </div>
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Amount Paid</span>
                <span className="text-xs font-semibold font-mono text-emerald-600">
                  {formatMoneyCents(bill.amount_paid_cents)}
                </span>
              </div>
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Balance Due</span>
                <span className="text-xs font-bold font-mono text-primary">
                  {formatMoneyCents(bill.amount_due_cents)}
                </span>
              </div>
            </div>

            {/* Billed Items Table */}
            <div className="space-y-1.5">
              <h4 className="text-xs font-semibold text-foreground">Billed Items</h4>
              <div className="border rounded-md overflow-hidden">
                <table className="w-full text-xs">
                  <thead className="bg-muted/50 border-b text-muted-foreground">
                    <tr>
                      <th className="p-2.5 text-left font-medium">Description</th>
                      <th className="p-2.5 text-right font-medium w-20">Qty</th>
                      <th className="p-2.5 text-right font-medium w-28">Cost</th>
                      <th className="p-2.5 text-right font-medium w-16">Tax %</th>
                      <th className="p-2.5 text-right font-medium w-28">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {bill.items?.map((item) => (
                      <tr key={item.id} className="hover:bg-muted/10">
                        <td className="p-2.5 font-medium text-foreground">{item.description}</td>
                        <td className="p-2.5 text-right font-mono text-muted-foreground">{item.quantity}</td>
                        <td className="p-2.5 text-right font-mono text-muted-foreground">
                          {formatMoneyCents(item.unit_cost_cents)}
                        </td>
                        <td className="p-2.5 text-right font-mono text-muted-foreground">
                          {item.tax_rate_percent}%
                        </td>
                        <td className="p-2.5 text-right font-mono font-medium text-foreground">
                          {formatMoneyCents(item.total_cents)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Totals */}
            <div className="flex justify-end pt-2">
              <div className="w-72 rounded-md bg-muted/40 p-3 text-xs space-y-1.5 border">
                <div className="flex justify-between text-muted-foreground">
                  <span>Subtotal:</span>
                  <span className="font-mono">{formatMoneyCents(bill.subtotal_cents)}</span>
                </div>
                <div className="flex justify-between text-muted-foreground">
                  <span>VAT (13%):</span>
                  <span className="font-mono">{formatMoneyCents(bill.tax_cents)}</span>
                </div>
                <div className="border-t pt-1 flex justify-between font-bold text-foreground text-sm">
                  <span>Total Payable:</span>
                  <span className="font-mono text-primary">
                    {formatMoneyCents(bill.total_cents)}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <DialogFooter className="p-4 border-t bg-muted/20 flex items-center justify-between gap-2">
            <div>
              {bill.status === 'draft' && canPostBill && (
                <Button
                  size="sm"
                  onClick={handlePost}
                  disabled={submitting}
                  className="gap-1.5 text-xs bg-primary text-primary-foreground"
                >
                  {submitting ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <CheckCircle2 className="h-3.5 w-3.5" />
                  )}
                  Post Bill to Accounts Payable
                </Button>
              )}
            </div>

            <Button
              variant="outline"
              size="sm"
              onClick={() => onOpenChange(false)}
            >
              Close
            </Button>
          </DialogFooter>
        </div>
      </div>
    </Dialog>
  );
}
