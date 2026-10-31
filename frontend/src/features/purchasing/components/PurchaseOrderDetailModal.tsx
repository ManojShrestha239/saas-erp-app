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
import type { PurchaseOrder } from '../types/purchasing.types';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { ProductItem, WarehouseItem } from '@/features/inventory/types/inventory.types';
import {
  AlertCircle,
  CheckCircle2,
  FileText,
  Loader2,
  PackageCheck,
  Printer,
  XCircle,
} from 'lucide-react';

interface PurchaseOrderDetailModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  purchaseOrder: PurchaseOrder | null;
  suppliers: ContactItem[];
  warehouses: WarehouseItem[];
  products: ProductItem[];
  canApprove: boolean;
  canReceive: boolean;
  onOpenReceiveGoods: (po: PurchaseOrder) => void;
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

export function PurchaseOrderDetailModal({
  open,
  onOpenChange,
  purchaseOrder,
  suppliers,
  warehouses,
  products,
  canApprove,
  canReceive,
  onOpenReceiveGoods,
  onSuccess,
}: PurchaseOrderDetailModalProps) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!purchaseOrder) return null;

  const supplier = suppliers.find((s) => s.id === purchaseOrder.supplier_id);
  const warehouse = warehouses.find((w) => w.id === purchaseOrder.warehouse_id);

  const handleApprove = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await purchasingApi.approvePurchaseOrder(purchaseOrder.id);
      onSuccess(`Purchase Order "${purchaseOrder.order_number}" has been approved.`);
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to approve purchase order.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handleCancel = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await purchasingApi.cancelPurchaseOrder(purchaseOrder.id);
      onSuccess(`Purchase Order "${purchaseOrder.order_number}" has been cancelled.`);
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to cancel purchase order.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handlePrintPdf = () => {
    const url = purchasingApi.getPurchaseOrderPdfUrl(purchaseOrder.id);
    window.open(url, '_blank');
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div className="bg-background rounded-lg border shadow-lg w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
              <div className="flex items-center gap-3">
                <FileText className="h-6 w-6 text-primary" />
                <div>
                  <DialogTitle className="flex items-center gap-2 text-lg">
                    Purchase Order {purchaseOrder.order_number}
                    <Badge variant="secondary" className="capitalize text-xs font-normal">
                      {purchaseOrder.status_label}
                    </Badge>
                  </DialogTitle>
                  <DialogDescription className="text-xs">
                    Issued to <strong className="text-foreground">{supplier?.name || purchaseOrder.supplier_id}</strong> on{' '}
                    {purchaseOrder.order_date}
                  </DialogDescription>
                </div>
              </div>

              <Button
                variant="outline"
                size="sm"
                onClick={handlePrintPdf}
                className="gap-1.5 text-xs h-8"
              >
                <Printer className="h-3.5 w-3.5" /> View / Print PDF
              </Button>
            </div>
          </DialogHeader>

          <div className="flex-1 overflow-y-auto p-6 space-y-4">
            {error && (
              <Alert variant="destructive" className="py-2.5">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription className="text-xs">{error}</AlertDescription>
              </Alert>
            )}

            {/* PO Metadata Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Supplier</span>
                <span className="text-xs font-semibold text-foreground truncate block">
                  {supplier?.name || '—'}
                </span>
                <span className="text-[10px] font-mono text-muted-foreground">{supplier?.code}</span>
              </div>
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Destination Warehouse</span>
                <span className="text-xs font-semibold text-foreground truncate block">
                  {warehouse?.name || '—'}
                </span>
                <span className="text-[10px] font-mono text-muted-foreground">{warehouse?.code}</span>
              </div>
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Expected Delivery</span>
                <span className="text-xs font-semibold text-foreground">
                  {purchaseOrder.expected_delivery_date || 'Standard Delivery'}
                </span>
              </div>
              <div className="p-3 rounded-md bg-muted/40 border">
                <span className="text-[11px] text-muted-foreground block">Currency</span>
                <span className="text-xs font-semibold text-foreground font-mono">
                  {purchaseOrder.currency}
                </span>
              </div>
            </div>

            {/* Ordered Items Table */}
            <div className="space-y-1.5">
              <h4 className="text-xs font-semibold text-foreground">Ordered Line Items</h4>
              <div className="border rounded-md overflow-hidden">
                <table className="w-full text-xs">
                  <thead className="bg-muted/50 border-b text-muted-foreground">
                    <tr>
                      <th className="p-2.5 text-left font-medium">Product</th>
                      <th className="p-2.5 text-right font-medium w-20">Ordered</th>
                      <th className="p-2.5 text-right font-medium w-20">Received</th>
                      <th className="p-2.5 text-right font-medium w-20">Remaining</th>
                      <th className="p-2.5 text-right font-medium w-28">Unit Cost</th>
                      <th className="p-2.5 text-right font-medium w-16">Tax %</th>
                      <th className="p-2.5 text-right font-medium w-28">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {purchaseOrder.items?.map((item) => {
                      const prod = products.find((p) => p.id === item.product_id);
                      return (
                        <tr key={item.id} className="hover:bg-muted/10">
                          <td className="p-2.5">
                            <div className="flex flex-col">
                              <span className="font-semibold text-foreground">
                                {prod?.name || item.product_id}
                              </span>
                              {prod?.sku && (
                                <span className="font-mono text-[11px] text-muted-foreground">
                                  {prod.sku}
                                </span>
                              )}
                            </div>
                          </td>
                          <td className="p-2.5 text-right font-mono text-foreground font-medium">
                            {item.quantity_ordered}
                          </td>
                          <td className="p-2.5 text-right font-mono text-emerald-600 font-semibold">
                            {item.quantity_received}
                          </td>
                          <td className="p-2.5 text-right font-mono text-muted-foreground">
                            {item.quantity_remaining}
                          </td>
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
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Totals and Notes */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
              <div className="p-3 rounded-md bg-muted/20 border text-xs space-y-1">
                <span className="font-semibold text-muted-foreground">Notes & Terms:</span>
                <p className="text-foreground">{purchaseOrder.notes || 'No special terms noted.'}</p>
              </div>

              <div className="rounded-md bg-muted/40 p-3 text-xs space-y-1.5 border">
                <div className="flex justify-between text-muted-foreground">
                  <span>Subtotal:</span>
                  <span className="font-mono">{formatMoneyCents(purchaseOrder.subtotal_cents)}</span>
                </div>
                <div className="flex justify-between text-muted-foreground">
                  <span>VAT (13%):</span>
                  <span className="font-mono">{formatMoneyCents(purchaseOrder.tax_cents)}</span>
                </div>
                <div className="border-t pt-1 flex justify-between font-bold text-foreground text-sm">
                  <span>Grand Total:</span>
                  <span className="font-mono text-primary">
                    {formatMoneyCents(purchaseOrder.total_cents)}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <DialogFooter className="p-4 border-t bg-muted/20 flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              {purchaseOrder.status === 'draft' && canApprove && (
                <Button
                  size="sm"
                  onClick={handleApprove}
                  disabled={submitting}
                  className="gap-1.5 text-xs bg-emerald-600 hover:bg-emerald-700 text-white"
                >
                  {submitting ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <CheckCircle2 className="h-3.5 w-3.5" />
                  )}
                  Approve Order
                </Button>
              )}

              {(purchaseOrder.status === 'approved' || purchaseOrder.status === 'partially_received') && canReceive && (
                <Button
                  size="sm"
                  onClick={() => {
                    onOpenChange(false);
                    onOpenReceiveGoods(purchaseOrder);
                  }}
                  className="gap-1.5 text-xs"
                >
                  <PackageCheck className="h-3.5 w-3.5" /> Receive Delivered Goods
                </Button>
              )}

              {(purchaseOrder.status === 'draft' || purchaseOrder.status === 'approved') && canApprove && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleCancel}
                  disabled={submitting}
                  className="gap-1.5 text-xs text-destructive hover:text-destructive hover:bg-destructive/10"
                >
                  <XCircle className="h-3.5 w-3.5" /> Cancel Order
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
