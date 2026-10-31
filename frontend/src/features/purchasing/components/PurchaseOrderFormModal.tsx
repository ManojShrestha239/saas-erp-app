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
import type { PurchaseOrderFormData } from '../types/purchasing.types';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { ProductItem, WarehouseItem } from '@/features/inventory/types/inventory.types';
import { AlertCircle, Loader2, Plus, Trash2 } from 'lucide-react';

interface PurchaseOrderFormModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  suppliers: ContactItem[];
  warehouses: WarehouseItem[];
  products: ProductItem[];
  onSuccess: (message: string) => void;
}

interface FormItemRow {
  productId: string;
  quantityOrdered: string;
  unitCost: string;
  taxRatePercent: string;
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

function todayYmd(): string {
  return new Date().toISOString().split('T')[0] || '';
}

export function PurchaseOrderFormModal({
  open,
  onOpenChange,
  suppliers,
  warehouses,
  products,
  onSuccess,
}: PurchaseOrderFormModalProps) {
  const [supplierId, setSupplierId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [orderDate, setOrderDate] = useState(todayYmd());
  const [expectedDeliveryDate, setExpectedDeliveryDate] = useState('');
  const [notes, setNotes] = useState('');

  const [items, setItems] = useState<FormItemRow[]>([
    { productId: '', quantityOrdered: '1', unitCost: '0.00', taxRatePercent: '13' },
  ]);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
      setSupplierId(suppliers[0]?.id || '');
      const defaultWh = warehouses.find((w) => w.is_default)?.id || warehouses[0]?.id || '';
      setWarehouseId(defaultWh);
      setOrderDate(todayYmd());
      setExpectedDeliveryDate('');
      setNotes('');

      const firstProd = products[0];
      const initialCost = firstProd ? (firstProd.cost_price_cents / 100).toFixed(2) : '0.00';
      setItems([
        {
          productId: firstProd?.id || '',
          quantityOrdered: '1',
          unitCost: initialCost,
          taxRatePercent: '13',
        },
      ]);
    }
  }, [open, suppliers, warehouses, products]);

  const handleProductChange = (index: number, newProdId: string) => {
    const prod = products.find((p) => p.id === newProdId);
    const cost = prod ? (prod.cost_price_cents / 100).toFixed(2) : '0.00';
    setItems((prev) =>
      prev.map((row, i) =>
        i === index ? { ...row, productId: newProdId, unitCost: cost } : row
      )
    );
  };

  const handleFieldChange = (index: number, field: keyof FormItemRow, val: string) => {
    setItems((prev) =>
      prev.map((row, i) => (i === index ? { ...row, [field]: val } : row))
    );
  };

  const addRow = () => {
    const firstProd = products[0];
    setItems((prev) => [
      ...prev,
      {
        productId: firstProd?.id || '',
        quantityOrdered: '1',
        unitCost: firstProd ? (firstProd.cost_price_cents / 100).toFixed(2) : '0.00',
        taxRatePercent: '13',
      },
    ]);
  };

  const removeRow = (index: number) => {
    if (items.length <= 1) return;
    setItems((prev) => prev.filter((_, i) => i !== index));
  };

  // Calculations
  const calculatedTotals = items.reduce(
    (acc, row) => {
      const qty = parseInt(row.quantityOrdered, 10) || 0;
      const unitCents = Math.round((parseFloat(row.unitCost) || 0) * 100);
      const taxRate = parseInt(row.taxRatePercent, 10) || 0;

      const lineSubtotal = qty * unitCents;
      const lineTax = Math.round((lineSubtotal * taxRate) / 100);
      const lineTotal = lineSubtotal + lineTax;

      return {
        subtotalCents: acc.subtotalCents + lineSubtotal,
        taxCents: acc.taxCents + lineTax,
        totalCents: acc.totalCents + lineTotal,
      };
    },
    { subtotalCents: 0, taxCents: 0, totalCents: 0 }
  );

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!supplierId) {
      setError('Please select a supplier.');
      return;
    }
    if (!warehouseId) {
      setError('Please select a destination warehouse.');
      return;
    }
    if (!orderDate) {
      setError('Please provide an order date.');
      return;
    }
    if (items.length === 0) {
      setError('Please add at least one line item.');
      return;
    }

    const parsedItems: PurchaseOrderFormData['items'] = [];
    for (let i = 0; i < items.length; i++) {
      const row = items[i];
      if (!row || !row.productId) {
        setError(`Please select a valid product for row #${i + 1}.`);
        return;
      }
      const qty = parseInt(row.quantityOrdered, 10);
      if (isNaN(qty) || qty <= 0) {
        setError(`Row #${i + 1}: Quantity must be greater than zero.`);
        return;
      }
      const unitCents = Math.round((parseFloat(row.unitCost) || 0) * 100);
      if (unitCents < 0) {
        setError(`Row #${i + 1}: Unit cost cannot be negative.`);
        return;
      }
      const taxRate = parseInt(row.taxRatePercent, 10);

      parsedItems.push({
        product_id: row.productId,
        quantity_ordered: qty,
        unit_cost_cents: unitCents,
        tax_rate_percent: isNaN(taxRate) ? 0 : taxRate,
      });
    }

    setSubmitting(true);
    setError(null);

    const payload: PurchaseOrderFormData = {
      supplier_id: supplierId,
      warehouse_id: warehouseId,
      order_date: orderDate,
      expected_delivery_date: expectedDeliveryDate || null,
      currency: 'NPR',
      notes: notes.trim() || null,
      items: parsedItems,
    };

    try {
      const res = await purchasingApi.createPurchaseOrder(payload);
      onSuccess(`Purchase Order "${res.data.order_number}" created in Draft status.`);
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to create purchase order.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div className="bg-background rounded-lg border shadow-lg w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b">
            <DialogTitle>Create Purchase Order</DialogTitle>
            <DialogDescription>
              Draft an official purchase order for commercial suppliers with destination warehouse and pricing.
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

              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="po-supplier" className="text-xs font-semibold">
                    Supplier <span className="text-destructive">*</span>
                  </Label>
                  <select
                    id="po-supplier"
                    value={supplierId}
                    onChange={(e) => setSupplierId(e.target.value)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                    required
                  >
                    <option value="">Select Supplier</option>
                    {suppliers.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name} ({s.code})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="po-warehouse" className="text-xs font-semibold">
                    Destination Warehouse <span className="text-destructive">*</span>
                  </Label>
                  <select
                    id="po-warehouse"
                    value={warehouseId}
                    onChange={(e) => setWarehouseId(e.target.value)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                    required
                  >
                    <option value="">Select Warehouse</option>
                    {warehouses.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name} ({w.code}) {w.is_default ? '— Default' : ''}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="po-order-date" className="text-xs font-semibold">
                    Order Date <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="po-order-date"
                    type="date"
                    value={orderDate}
                    onChange={(e) => setOrderDate(e.target.value)}
                    required
                    className="h-9 text-xs"
                  />
                </div>

                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="po-delivery-date" className="text-xs font-semibold">
                    Expected Delivery Date
                  </Label>
                  <Input
                    id="po-delivery-date"
                    type="date"
                    value={expectedDeliveryDate}
                    onChange={(e) => setExpectedDeliveryDate(e.target.value)}
                    className="h-9 text-xs"
                  />
                </div>
              </div>

              {/* Items Section */}
              <div className="space-y-2 pt-2">
                <div className="flex items-center justify-between">
                  <Label className="text-xs font-semibold">
                    Ordered Products <span className="text-destructive">*</span>
                  </Label>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addRow}
                    className="h-7 text-xs gap-1"
                  >
                    <Plus className="h-3.5 w-3.5" /> Add Product Row
                  </Button>
                </div>

                <div className="border rounded-md overflow-hidden">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/50 border-b text-muted-foreground">
                      <tr>
                        <th className="p-2.5 text-left font-medium">Product</th>
                        <th className="p-2.5 text-right font-medium w-24">Qty</th>
                        <th className="p-2.5 text-right font-medium w-32">Unit Cost (NPR)</th>
                        <th className="p-2.5 text-right font-medium w-20">Tax %</th>
                        <th className="p-2.5 text-right font-medium w-32">Total</th>
                        <th className="p-2.5 text-center font-medium w-10"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {items.map((row, idx) => {
                        const qty = parseInt(row.quantityOrdered, 10) || 0;
                        const unitCents = Math.round((parseFloat(row.unitCost) || 0) * 100);
                        const taxRate = parseInt(row.taxRatePercent, 10) || 0;
                        const lineSubtotal = qty * unitCents;
                        const lineTax = Math.round((lineSubtotal * taxRate) / 100);
                        const lineTotal = lineSubtotal + lineTax;

                        return (
                          <tr key={idx} className="hover:bg-muted/10">
                            <td className="p-2">
                              <select
                                value={row.productId}
                                onChange={(e) => handleProductChange(idx, e.target.value)}
                                className="w-full h-8 px-2 rounded border text-xs bg-background text-foreground"
                              >
                                {products.map((p) => (
                                  <option key={p.id} value={p.id}>
                                    {p.name} ({p.sku})
                                  </option>
                                ))}
                              </select>
                            </td>
                            <td className="p-2 text-right">
                              <Input
                                type="number"
                                min="1"
                                step="1"
                                value={row.quantityOrdered}
                                onChange={(e) => handleFieldChange(idx, 'quantityOrdered', e.target.value)}
                                className="h-8 text-xs text-right"
                              />
                            </td>
                            <td className="p-2 text-right">
                              <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={row.unitCost}
                                onChange={(e) => handleFieldChange(idx, 'unitCost', e.target.value)}
                                className="h-8 text-xs text-right"
                              />
                            </td>
                            <td className="p-2 text-right">
                              <Input
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                value={row.taxRatePercent}
                                onChange={(e) => handleFieldChange(idx, 'taxRatePercent', e.target.value)}
                                className="h-8 text-xs text-right"
                              />
                            </td>
                            <td className="p-2 text-right font-mono font-medium">
                              {formatMoneyCents(lineTotal)}
                            </td>
                            <td className="p-2 text-center">
                              <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={items.length <= 1}
                                onClick={() => removeRow(idx)}
                                className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive"
                                title="Remove row"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {/* Totals Summary */}
                <div className="flex justify-end pt-2">
                  <div className="w-72 rounded-md bg-muted/40 p-3 text-xs space-y-1.5">
                    <div className="flex justify-between text-muted-foreground">
                      <span>Subtotal:</span>
                      <span className="font-mono">{formatMoneyCents(calculatedTotals.subtotalCents)}</span>
                    </div>
                    <div className="flex justify-between text-muted-foreground">
                      <span>VAT (13%):</span>
                      <span className="font-mono">{formatMoneyCents(calculatedTotals.taxCents)}</span>
                    </div>
                    <div className="border-t pt-1 flex justify-between font-semibold text-foreground text-sm">
                      <span>Grand Total:</span>
                      <span className="font-mono text-primary">
                        {formatMoneyCents(calculatedTotals.totalCents)}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="space-y-1.5 pt-2">
                  <Label htmlFor="po-notes" className="text-xs font-semibold">
                    Order Terms & Notes
                  </Label>
                  <textarea
                    id="po-notes"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Enter shipping instructions, payment terms agreement, or vendor notes..."
                    rows={2}
                    className="w-full p-2.5 rounded-md border text-xs bg-background text-foreground resize-none focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                  />
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
                Save Purchase Order
              </Button>
            </DialogFooter>
          </form>
        </div>
      </div>
    </Dialog>
  );
}
