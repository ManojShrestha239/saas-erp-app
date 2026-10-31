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
import type { PurchaseOrder, SupplierBillFormData } from '../types/purchasing.types';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { ProductItem } from '@/features/inventory/types/inventory.types';
import { AlertCircle, Loader2, Plus, ReceiptText, Trash2 } from 'lucide-react';

interface SupplierBillFormModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  suppliers: ContactItem[];
  products: ProductItem[];
  purchaseOrders: PurchaseOrder[];
  onSuccess: (message: string) => void;
}

interface BillLineRow {
  productId: string;
  description: string;
  quantity: string;
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

function addDaysYmd(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().split('T')[0] || '';
}

export function SupplierBillFormModal({
  open,
  onOpenChange,
  suppliers,
  products,
  purchaseOrders,
  onSuccess,
}: SupplierBillFormModalProps) {
  const [supplierId, setSupplierId] = useState('');
  const [purchaseOrderId, setPurchaseOrderId] = useState('');
  const [supplierInvoiceNumber, setSupplierInvoiceNumber] = useState('');
  const [billDate, setBillDate] = useState(todayYmd());
  const [dueDate, setDueDate] = useState(addDaysYmd(30));
  const [notes, setNotes] = useState('');

  const [items, setItems] = useState<BillLineRow[]>([
    {
      productId: '',
      description: 'Raw Materials & Supplies',
      quantity: '1',
      unitCost: '0.00',
      taxRatePercent: '13',
    },
  ]);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
      setSupplierId(suppliers[0]?.id || '');
      setPurchaseOrderId('');
      setSupplierInvoiceNumber('');
      setBillDate(todayYmd());
      setDueDate(addDaysYmd(30));
      setNotes('');

      const firstProd = products[0];
      const initialCost = firstProd ? (firstProd.cost_price_cents / 100).toFixed(2) : '0.00';
      setItems([
        {
          productId: firstProd?.id || '',
          description: firstProd?.name || 'Raw Materials & Supplies',
          quantity: '1',
          unitCost: initialCost,
          taxRatePercent: '13',
        },
      ]);
    }
  }, [open, suppliers, products]);

  // When PO is selected, auto-populate line items from PO if available
  const handlePoSelect = (poId: string) => {
    setPurchaseOrderId(poId);
    if (!poId) return;

    const po = purchaseOrders.find((p) => p.id === poId);
    if (po && po.items && po.items.length > 0) {
      const mappedLines: BillLineRow[] = po.items.map((item) => {
        const prod = products.find((p) => p.id === item.product_id);
        return {
          productId: item.product_id,
          description: prod ? prod.name : 'Goods ordered under ' + po.order_number,
          quantity: String(item.quantity_ordered),
          unitCost: (item.unit_cost_cents / 100).toFixed(2),
          taxRatePercent: String(item.tax_rate_percent),
        };
      });
      setItems(mappedLines);
    }
  };

  const handleProductChange = (index: number, newProdId: string) => {
    const prod = products.find((p) => p.id === newProdId);
    const cost = prod ? (prod.cost_price_cents / 100).toFixed(2) : '0.00';
    setItems((prev) =>
      prev.map((row, i) =>
        i === index
          ? {
              ...row,
              productId: newProdId,
              description: prod ? prod.name : row.description,
              unitCost: cost,
            }
          : row
      )
    );
  };

  const handleFieldChange = (index: number, field: keyof BillLineRow, val: string) => {
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
        description: firstProd?.name || 'General Inventory Item',
        quantity: '1',
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
      const qty = parseInt(row.quantity, 10) || 0;
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
    if (!billDate) {
      setError('Please provide a bill date.');
      return;
    }
    if (!dueDate) {
      setError('Please provide a payment due date.');
      return;
    }
    if (items.length === 0) {
      setError('Please add at least one line item.');
      return;
    }

    const parsedItems: SupplierBillFormData['items'] = [];
    for (let i = 0; i < items.length; i++) {
      const row = items[i];
      if (!row || !row.description.trim()) {
        setError(`Row #${i + 1}: Line description is required.`);
        return;
      }
      const qty = parseInt(row.quantity, 10);
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
        description: row.description.trim(),
        product_id: row.productId || null,
        quantity: qty,
        unit_cost_cents: unitCents,
        tax_rate_percent: isNaN(taxRate) ? 0 : taxRate,
      });
    }

    setSubmitting(true);
    setError(null);

    const payload: SupplierBillFormData = {
      supplier_id: supplierId,
      purchase_order_id: purchaseOrderId || null,
      supplier_invoice_number: supplierInvoiceNumber.trim() || null,
      bill_date: billDate,
      due_date: dueDate,
      currency: 'NPR',
      notes: notes.trim() || null,
      items: parsedItems,
    };

    try {
      const res = await purchasingApi.createBill(payload);
      onSuccess(`Supplier Bill "${res.data.bill_number}" created in Draft status.`);
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to create supplier bill.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const filteredPos = purchaseOrders.filter((p) => !supplierId || p.supplier_id === supplierId);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div className="bg-background rounded-lg border shadow-lg w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b">
            <DialogTitle className="flex items-center gap-2">
              <ReceiptText className="h-5 w-5 text-primary" /> Create Supplier Bill
            </DialogTitle>
            <DialogDescription>
              Record an incoming bill or invoice from a supplier for payable liability tracking.
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
                  <Label htmlFor="bill-supplier" className="text-xs font-semibold">
                    Supplier <span className="text-destructive">*</span>
                  </Label>
                  <select
                    id="bill-supplier"
                    value={supplierId}
                    onChange={(e) => {
                      setSupplierId(e.target.value);
                      setPurchaseOrderId('');
                    }}
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
                  <Label htmlFor="bill-po" className="text-xs font-semibold">
                    Linked Purchase Order (Optional)
                  </Label>
                  <select
                    id="bill-po"
                    value={purchaseOrderId}
                    onChange={(e) => handlePoSelect(e.target.value)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                  >
                    <option value="">No PO Linked (Standalone)</option>
                    {filteredPos.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.order_number} ({p.status_label})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1.5 sm:col-span-2">
                  <Label htmlFor="bill-inv-number" className="text-xs font-semibold">
                    Supplier Invoice # / Reference
                  </Label>
                  <Input
                    id="bill-inv-number"
                    value={supplierInvoiceNumber}
                    onChange={(e) => setSupplierInvoiceNumber(e.target.value)}
                    placeholder="e.g. TAX-INV-2026-99"
                    className="h-9 text-xs font-mono"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="bill-date" className="text-xs font-semibold">
                    Bill Date <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="bill-date"
                    type="date"
                    value={billDate}
                    onChange={(e) => setBillDate(e.target.value)}
                    required
                    className="h-9 text-xs"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="bill-due-date" className="text-xs font-semibold">
                    Due Date <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="bill-due-date"
                    type="date"
                    value={dueDate}
                    onChange={(e) => setDueDate(e.target.value)}
                    required
                    className="h-9 text-xs"
                  />
                </div>
              </div>

              {/* Items Section */}
              <div className="space-y-2 pt-2">
                <div className="flex items-center justify-between">
                  <Label className="text-xs font-semibold">
                    Billed Line Items <span className="text-destructive">*</span>
                  </Label>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addRow}
                    className="h-7 text-xs gap-1"
                  >
                    <Plus className="h-3.5 w-3.5" /> Add Bill Line
                  </Button>
                </div>

                <div className="border rounded-md overflow-hidden">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/50 border-b text-muted-foreground">
                      <tr>
                        <th className="p-2.5 text-left font-medium w-48">Product (Optional)</th>
                        <th className="p-2.5 text-left font-medium">Description</th>
                        <th className="p-2.5 text-right font-medium w-20">Qty</th>
                        <th className="p-2.5 text-right font-medium w-28">Cost (NPR)</th>
                        <th className="p-2.5 text-right font-medium w-20">Tax %</th>
                        <th className="p-2.5 text-right font-medium w-28">Total</th>
                        <th className="p-2.5 text-center font-medium w-10"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {items.map((row, idx) => {
                        const qty = parseInt(row.quantity, 10) || 0;
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
                                <option value="">Custom Item</option>
                                {products.map((p) => (
                                  <option key={p.id} value={p.id}>
                                    {p.name}
                                  </option>
                                ))}
                              </select>
                            </td>
                            <td className="p-2">
                              <Input
                                value={row.description}
                                onChange={(e) => handleFieldChange(idx, 'description', e.target.value)}
                                placeholder="Item description"
                                className="h-8 text-xs"
                                required
                              />
                            </td>
                            <td className="p-2 text-right">
                              <Input
                                type="number"
                                min="1"
                                step="1"
                                value={row.quantity}
                                onChange={(e) => handleFieldChange(idx, 'quantity', e.target.value)}
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
                      <span>Total Payable:</span>
                      <span className="font-mono text-primary">
                        {formatMoneyCents(calculatedTotals.totalCents)}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="space-y-1.5 pt-2">
                  <Label htmlFor="bill-notes" className="text-xs font-semibold">
                    Payment Instructions / Notes
                  </Label>
                  <textarea
                    id="bill-notes"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Enter bank wire details or payable notes..."
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
                Save Draft Bill
              </Button>
            </DialogFooter>
          </form>
        </div>
      </div>
    </Dialog>
  );
}
