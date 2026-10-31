import React, { useState, useEffect } from 'react';
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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { purchasingApi } from '../api/purchasingApi';
import type { GoodsReceiptFormData, PurchaseOrder } from '../types/purchasing.types';
import type { ProductItem } from '@/features/inventory/types/inventory.types';
import { AlertCircle, Loader2, PackageCheck } from 'lucide-react';

interface GoodsReceiptModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  purchaseOrder: PurchaseOrder | null;
  products: ProductItem[];
  onSuccess: (message: string) => void;
}

interface ReceiptLineState {
  purchaseOrderItemId: string;
  productId: string;
  quantityOrdered: number;
  quantityAlreadyReceived: number;
  quantityRemaining: number;
  quantityToReceive: string;
}

function todayYmd(): string {
  return new Date().toISOString().split('T')[0] || '';
}

export function GoodsReceiptModal({
  open,
  onOpenChange,
  purchaseOrder,
  products,
  onSuccess,
}: GoodsReceiptModalProps) {
  const [receivedDate, setReceivedDate] = useState(todayYmd());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<ReceiptLineState[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open && purchaseOrder) {
      setError(null);
      setReceivedDate(todayYmd());
      setNotes('');

      const orderItems = purchaseOrder.items || [];
      const initLines: ReceiptLineState[] = orderItems.map((item) => {
        const remaining = Math.max(0, item.quantity_ordered - item.quantity_received);
        return {
          purchaseOrderItemId: item.id,
          productId: item.product_id,
          quantityOrdered: item.quantity_ordered,
          quantityAlreadyReceived: item.quantity_received,
          quantityRemaining: remaining,
          quantityToReceive: String(remaining),
        };
      });

      setLines(initLines);
    }
  }, [open, purchaseOrder]);

  const handleQtyChange = (index: number, val: string) => {
    setLines((prev) =>
      prev.map((line, i) => (i === index ? { ...line, quantityToReceive: val } : line))
    );
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!purchaseOrder) return;

    if (!receivedDate) {
      setError('Please provide a goods receipt date.');
      return;
    }

    const itemsToReceive: GoodsReceiptFormData['items'] = [];
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (!line) continue;
      const qty = parseInt(line.quantityToReceive, 10);
      if (isNaN(qty) || qty < 0) {
        setError(`Line #${i + 1}: Received quantity must be zero or a positive integer.`);
        return;
      }
      if (qty > line.quantityRemaining) {
        setError(
          `Line #${i + 1}: Received quantity (${qty}) exceeds remaining order balance (${line.quantityRemaining}).`
        );
        return;
      }
      if (qty > 0) {
        itemsToReceive.push({
          purchase_order_item_id: line.purchaseOrderItemId,
          quantity_received: qty,
        });
      }
    }

    if (itemsToReceive.length === 0) {
      setError('Please enter at least one item quantity to receive into stock.');
      return;
    }

    setSubmitting(true);
    setError(null);

    const payload: GoodsReceiptFormData = {
      received_date: receivedDate,
      notes: notes.trim() || null,
      items: itemsToReceive,
    };

    try {
      const res = await purchasingApi.recordGoodsReceipt(purchaseOrder.id, payload);
      onSuccess(
        `Goods Receipt "${res.data.receipt_number}" recorded successfully. Stock levels incremented.`
      );
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to record goods receipt.';
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
            <DialogTitle className="flex items-center gap-2">
              <PackageCheck className="h-5 w-5 text-primary" /> Record Goods Receipt
            </DialogTitle>
            <DialogDescription>
              Receive delivered goods for Purchase Order{' '}
              <strong className="text-foreground">{purchaseOrder?.order_number}</strong>. Physical inventory
              will be atomically incremented at destination warehouse.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
            <div className="p-6 overflow-y-auto space-y-4">
              {error && (
                <Alert variant="destructive" className="py-2.5">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription className="text-xs">{error}</AlertDescription>
                </Alert>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label htmlFor="gr-date" className="text-xs font-semibold">
                    Receipt Date <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="gr-date"
                    type="date"
                    value={receivedDate}
                    onChange={(e) => setReceivedDate(e.target.value)}
                    required
                    className="h-9 text-xs"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="gr-notes" className="text-xs font-semibold">
                    Delivery Note / Inspection Remarks
                  </Label>
                  <Input
                    id="gr-notes"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="e.g. Received via Truck #BA-2-KHA-1029, verified QC"
                    className="h-9 text-xs"
                  />
                </div>
              </div>

              {/* Lines Table */}
              <div className="space-y-2 pt-2">
                <Label className="text-xs font-semibold">
                  Items to Receive <span className="text-destructive">*</span>
                </Label>

                <div className="border rounded-md overflow-hidden">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/50 border-b text-muted-foreground">
                      <tr>
                        <th className="p-2.5 text-left font-medium">Product</th>
                        <th className="p-2.5 text-right font-medium w-24">Ordered</th>
                        <th className="p-2.5 text-right font-medium w-24">Received</th>
                        <th className="p-2.5 text-right font-medium w-24">Remaining</th>
                        <th className="p-2.5 text-right font-medium w-32">Qty to Receive</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {lines.map((line, idx) => {
                        const prod = products.find((p) => p.id === line.productId);
                        const isFullyReceived = line.quantityRemaining <= 0;

                        return (
                          <tr key={line.purchaseOrderItemId} className="hover:bg-muted/10">
                            <td className="p-2.5">
                              <div className="flex flex-col">
                                <span className="font-semibold text-foreground">
                                  {prod?.name || line.productId}
                                </span>
                                {prod?.sku && (
                                  <span className="font-mono text-[11px] text-muted-foreground">
                                    {prod.sku}
                                  </span>
                                )}
                              </div>
                            </td>
                            <td className="p-2.5 text-right font-mono text-muted-foreground">
                              {line.quantityOrdered}
                            </td>
                            <td className="p-2.5 text-right font-mono text-muted-foreground">
                              {line.quantityAlreadyReceived}
                            </td>
                            <td className="p-2.5 text-right font-mono font-medium text-foreground">
                              {line.quantityRemaining}
                            </td>
                            <td className="p-2 text-right">
                              <Input
                                type="number"
                                min="0"
                                max={line.quantityRemaining}
                                step="1"
                                disabled={isFullyReceived}
                                value={line.quantityToReceive}
                                onChange={(e) => handleQtyChange(idx, e.target.value)}
                                className="h-8 text-xs text-right"
                              />
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <DialogFooter className="p-4 border-t bg-muted/20 flex items-center justify-end gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => onOpenChange(false)}
                disabled={submitting}
              >
                Cancel
              </Button>
              <Button type="submit" size="sm" disabled={submitting}>
                {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Confirm Goods Receipt
              </Button>
            </DialogFooter>
          </form>
        </div>
      </div>
    </Dialog>
  );
}
