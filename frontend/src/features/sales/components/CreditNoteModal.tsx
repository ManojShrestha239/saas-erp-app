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
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { salesApi } from '../api/salesApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { WarehouseItem } from '@/features/inventory/types/inventory.types';
import type { CreditNote, CreateCreditNoteFormData, SalesInvoice } from '../types/sales.types';
import { Plus, Trash2, AlertCircle, CheckCircle } from 'lucide-react';

interface CreditNoteModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  creditNote?: CreditNote | null;
  initialInvoice?: SalesInvoice | null;
  onSuccess: () => void;
}

interface LineItemState {
  product_id: string;
  description: string;
  quantity: number;
  unit_price: number; // in dollars
  tax_rate_percent: number;
  cogs_unit_cost_cents: number;
}

interface ProductOption {
  id: string;
  sku: string;
  name: string;
  selling_price_cents: number;
  cost_price_cents: number;
  tax_rate_percent: number;
}

function formatMoneyCents(cents: number, currency = 'USD'): string {
  const amount = cents / 100;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

export function CreditNoteModal({
  open,
  onOpenChange,
  creditNote,
  initialInvoice,
  onSuccess,
}: CreditNoteModalProps): React.JSX.Element {
  const isViewMode = !!creditNote;

  const [customers, setCustomers] = useState<ContactItem[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseItem[]>([]);
  const [products, setProducts] = useState<ProductOption[]>([]);

  const [customerId, setCustomerId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [salesInvoiceId, setSalesInvoiceId] = useState<string | null>(null);
  const [issueDate, setIssueDate] = useState(new Date().toISOString().split('T')[0]);
  const [restock, setRestock] = useState(true);
  const [reason, setReason] = useState('customer_return');
  const [notes, setNotes] = useState('');
  const [items, setItems] = useState<LineItemState[]>([]);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;

    contactsApi
      .getContacts({ type: 'customer', per_page: 100 })
      .then((res) => setCustomers(res.data))
      .catch(() => {});

    inventoryApi
      .getWarehouses()
      .then((res) => {
        setWarehouses(res.data);
        if (res.data.length > 0) {
          const defaultWh = res.data.find((w) => w.is_default) ?? res.data[0];
          if (defaultWh) {
            setWarehouseId((prev) => prev || defaultWh.id);
          }
        }
      })
      .catch(() => {});

    inventoryApi
      .getProducts({ per_page: 100 })
      .then((res) => {
        setProducts(
          res.data.map((p) => ({
            id: p.id,
            sku: p.sku,
            name: p.name,
            selling_price_cents: p.selling_price_cents,
            cost_price_cents: p.cost_price_cents,
            tax_rate_percent: p.tax_category === 'standard' ? 13 : 0,
          }))
        );
      })
      .catch(() => {});

    if (creditNote) {
      setCustomerId(creditNote.customer_id);
      setWarehouseId(creditNote.warehouse_id);
      setSalesInvoiceId(creditNote.sales_invoice_id);
      setIssueDate(creditNote.issue_date);
      setRestock(creditNote.restock);
      setReason(creditNote.reason);
      setNotes(creditNote.notes || '');
      setItems(
        (creditNote.items || []).map((i) => ({
          product_id: i.product_id,
          description: i.description || '',
          quantity: i.quantity,
          unit_price: i.unit_price_cents / 100,
          tax_rate_percent: i.tax_rate_percent,
          cogs_unit_cost_cents: i.cogs_unit_cost_cents,
        }))
      );
    } else if (initialInvoice) {
      setCustomerId(initialInvoice.customer_id);
      setWarehouseId(initialInvoice.warehouse_id || '');
      setSalesInvoiceId(initialInvoice.id);
      setIssueDate(new Date().toISOString().split('T')[0] ?? '');
      setRestock(true);
      setReason('customer_return');
      setNotes(`Return against Invoice ${initialInvoice.invoice_number}`);
      setItems(
        (initialInvoice.items || []).map((i) => ({
          product_id: i.product_id,
          description: i.description || '',
          quantity: i.quantity,
          unit_price: i.unit_price_cents / 100,
          tax_rate_percent: i.tax_rate_percent,
          cogs_unit_cost_cents: 0,
        }))
      );
    } else {
      setCustomerId('');
      setSalesInvoiceId(null);
      setIssueDate(new Date().toISOString().split('T')[0] ?? '');
      setRestock(true);
      setReason('customer_return');
      setNotes('');
      setItems([
        {
          product_id: '',
          description: '',
          quantity: 1,
          unit_price: 0,
          tax_rate_percent: 13,
          cogs_unit_cost_cents: 0,
        },
      ]);
    }
    setError(null);
  }, [open, creditNote, initialInvoice]);

  const handleProductChange = (index: number, productId: string) => {
    const prod = products.find((p) => p.id === productId);
    const updated = [...items];
    const current = updated[index];
    if (!current) return;
    updated[index] = {
      ...current,
      product_id: productId,
      description: prod ? `${prod.sku} - ${prod.name}` : '',
      unit_price: prod ? prod.selling_price_cents / 100 : 0,
      tax_rate_percent: prod ? prod.tax_rate_percent : 13,
      cogs_unit_cost_cents: prod ? prod.cost_price_cents : 0,
    };
    setItems(updated);
  };

  const handleItemFieldChange = (
    index: number,
    field: keyof LineItemState,
    value: string | number
  ) => {
    const updated = [...items];
    const current = updated[index];
    if (!current) return;
    updated[index] = {
      ...current,
      [field]: value,
    };
    setItems(updated);
  };

  const addItem = () => {
    setItems([
      ...items,
      {
        product_id: '',
        description: '',
        quantity: 1,
        unit_price: 0,
        tax_rate_percent: 13,
        cogs_unit_cost_cents: 0,
      },
    ]);
  };

  const removeItem = (index: number) => {
    if (items.length <= 1) return;
    setItems(items.filter((_, i) => i !== index));
  };

  const subtotalCents = items.reduce((acc, i) => acc + Math.round(i.quantity * i.unit_price * 100), 0);
  const taxCents = items.reduce((acc, i) => {
    const lineSubtotal = Math.round(i.quantity * i.unit_price * 100);
    return acc + Math.round((lineSubtotal * (i.tax_rate_percent || 0)) / 100);
  }, 0);
  const totalCents = subtotalCents + taxCents;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!customerId) {
      setError('Please select a customer.');
      return;
    }
    if (!warehouseId) {
      setError('Please select a warehouse.');
      return;
    }
    if (items.length === 0 || items.some((i) => !i.product_id || i.quantity <= 0)) {
      setError('Please provide valid products and quantities for all lines.');
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const payload: CreateCreditNoteFormData = {
        customer_id: customerId,
        warehouse_id: warehouseId,
        sales_invoice_id: salesInvoiceId,
        issue_date: issueDate || (new Date().toISOString().split('T')[0] ?? ''),
        currency: 'USD',
        restock,
        reason,
        notes: notes || undefined,
        items: items.map((i) => ({
          product_id: i.product_id,
          description: i.description || undefined,
          quantity: i.quantity,
          unit_price_cents: Math.round(i.unit_price * 100),
          tax_rate_percent: i.tax_rate_percent,
          cogs_unit_cost_cents: i.cogs_unit_cost_cents,
        })),
      };

      await salesApi.createCreditNote(payload);
      onSuccess();
      onOpenChange(false);
    } catch (err: unknown) {
      const apiErr = err as { message?: string };
      setError(apiErr?.message || 'Failed to create credit note.');
    } finally {
      setLoading(false);
    }
  };

  const handlePost = async () => {
    if (!creditNote) return;
    setLoading(true);
    setError(null);
    try {
      await salesApi.postCreditNote(creditNote.id);
      onSuccess();
      onOpenChange(false);
    } catch (err: unknown) {
      const apiErr = err as { message?: string };
      setError(apiErr?.message || 'Failed to post credit note.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-4xl max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <div className="flex items-center justify-between">
          <DialogTitle className="text-xl font-bold">
            {isViewMode ? `Credit Note ${creditNote.credit_note_number}` : 'New Credit Note (Sales Return)'}
          </DialogTitle>
            {isViewMode && (
              <Badge variant={creditNote.status === 'posted' ? 'success' : 'secondary'}>
                {creditNote.status_label || creditNote.status}
              </Badge>
            )}
          </div>
          <DialogDescription>
            {isViewMode
              ? 'Review credit note details, stock return status, and journal impact.'
              : 'Issue a credit note to refund or adjust customer receivables and restore inventory.'}
          </DialogDescription>
        </DialogHeader>

        {error && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <Label htmlFor="customer_id">Customer *</Label>
              {isViewMode ? (
                <div className="mt-1.5 p-2 bg-muted rounded-md text-sm font-medium">
                  {customers.find((c) => c.id === customerId)?.name || customerId}
                </div>
              ) : (
                <select
                  id="customer_id"
                  className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  value={customerId}
                  onChange={(e) => setCustomerId(e.target.value)}
                  disabled={!!initialInvoice}
                  required
                >
                  <option value="">Select Customer...</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.code})
                    </option>
                  ))}
                </select>
              )}
            </div>

            <div>
              <Label htmlFor="warehouse_id">Receiving Warehouse *</Label>
              {isViewMode ? (
                <div className="mt-1.5 p-2 bg-muted rounded-md text-sm font-medium">
                  {warehouses.find((w) => w.id === warehouseId)?.name || warehouseId}
                </div>
              ) : (
                <select
                  id="warehouse_id"
                  className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  value={warehouseId}
                  onChange={(e) => setWarehouseId(e.target.value)}
                  required
                >
                  <option value="">Select Warehouse...</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>
              )}
            </div>

            <div>
              <Label htmlFor="issue_date">Issue Date *</Label>
              <Input
                id="issue_date"
                type="date"
                className="mt-1.5"
                value={issueDate}
                onChange={(e) => setIssueDate(e.target.value)}
                disabled={isViewMode}
                required
              />
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <Label htmlFor="reason">Reason *</Label>
              {isViewMode ? (
                <div className="mt-1.5 p-2 bg-muted rounded-md text-sm capitalize">
                  {reason.replace('_', ' ')}
                </div>
              ) : (
                <select
                  id="reason"
                  className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                >
                  <option value="customer_return">Customer Return</option>
                  <option value="damaged_goods">Damaged / Defective Goods</option>
                  <option value="billing_error">Billing / Pricing Error</option>
                  <option value="discount_rebate">Discount / Rebate</option>
                  <option value="other">Other</option>
                </select>
              )}
            </div>

            <div className="flex items-center space-x-2 pt-6">
              <input
                id="restock"
                type="checkbox"
                className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                checked={restock}
                onChange={(e) => setRestock(e.target.checked)}
                disabled={isViewMode}
              />
              <Label htmlFor="restock" className="cursor-pointer text-sm font-medium">
                Restock returned goods into warehouse
              </Label>
            </div>

            {salesInvoiceId && (
              <div>
                <Label>Linked Sales Invoice</Label>
                <div className="mt-1.5 p-2 bg-muted rounded-md text-sm font-mono text-primary font-medium">
                  {creditNote?.invoice?.invoice_number || initialInvoice?.invoice_number || salesInvoiceId}
                </div>
              </div>
            )}
          </div>

          {/* Line Items */}
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <Label className="text-sm font-semibold">Credited Items</Label>
              {!isViewMode && (
                <Button type="button" variant="outline" size="sm" onClick={addItem}>
                  <Plus className="h-4 w-4 mr-1" /> Add Item
                </Button>
              )}
            </div>

            <div className="border rounded-md overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-muted text-muted-foreground border-b text-xs uppercase font-medium">
                  <tr>
                    <th className="py-2.5 px-3 text-left">Product</th>
                    <th className="py-2.5 px-3 text-right w-24">Qty</th>
                    <th className="py-2.5 px-3 text-right w-28">Price ($)</th>
                    <th className="py-2.5 px-3 text-right w-20">Tax %</th>
                    <th className="py-2.5 px-3 text-right w-32">Total ($)</th>
                    {!isViewMode && <th className="py-2.5 px-2 w-10"></th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {items.map((item, idx) => {
                    const lineSubtotal = Math.round(item.quantity * item.unit_price * 100);
                    const lineTax = Math.round((lineSubtotal * (item.tax_rate_percent || 0)) / 100);
                    const lineTotal = lineSubtotal + lineTax;

                    return (
                      <tr key={idx} className="hover:bg-muted/50">
                        <td className="p-2">
                          {isViewMode ? (
                            <div>
                              <div className="font-medium text-foreground">
                                {products.find((p) => p.id === item.product_id)?.name || item.product_id}
                              </div>
                              <div className="text-xs text-muted-foreground font-mono">
                                {products.find((p) => p.id === item.product_id)?.sku}
                              </div>
                            </div>
                          ) : (
                            <select
                              className="w-full rounded border border-input bg-background px-2.5 py-1.5 text-sm"
                              value={item.product_id}
                              onChange={(e) => handleProductChange(idx, e.target.value)}
                              required
                            >
                              <option value="">Select Product...</option>
                              {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                  {p.sku} - {p.name}
                                </option>
                              ))}
                            </select>
                          )}
                        </td>
                        <td className="p-2 text-right">
                          {isViewMode ? (
                            <span className="tabular-nums font-medium">{item.quantity}</span>
                          ) : (
                            <Input
                              type="number"
                              min="1"
                              className="text-right h-8 tabular-nums"
                              value={item.quantity}
                              onChange={(e) =>
                                handleItemFieldChange(idx, 'quantity', parseInt(e.target.value, 10) || 0)
                              }
                              required
                            />
                          )}
                        </td>
                        <td className="p-2 text-right">
                          {isViewMode ? (
                            <span className="tabular-nums font-medium">
                              {formatMoneyCents(Math.round(item.unit_price * 100))}
                            </span>
                          ) : (
                            <Input
                              type="number"
                              step="0.01"
                              min="0"
                              className="text-right h-8 tabular-nums"
                              value={item.unit_price}
                              onChange={(e) =>
                                handleItemFieldChange(idx, 'unit_price', parseFloat(e.target.value) || 0)
                              }
                              required
                            />
                          )}
                        </td>
                        <td className="p-2 text-right tabular-nums">
                          {item.tax_rate_percent}%
                        </td>
                        <td className="p-2 text-right tabular-nums font-semibold">
                          {formatMoneyCents(lineTotal)}
                        </td>
                        {!isViewMode && (
                          <td className="p-2 text-center">
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8 text-destructive hover:bg-destructive/10"
                              onClick={() => removeItem(idx)}
                              disabled={items.length <= 1}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {/* Financial Summary Strip */}
            <div className="flex justify-end pt-2">
              <div className="w-64 space-y-1.5 text-sm">
                <div className="flex justify-between text-muted-foreground">
                  <span>Subtotal:</span>
                  <span className="tabular-nums font-medium">{formatMoneyCents(subtotalCents)}</span>
                </div>
                <div className="flex justify-between text-muted-foreground">
                  <span>VAT (13%):</span>
                  <span className="tabular-nums font-medium">{formatMoneyCents(taxCents)}</span>
                </div>
                <div className="flex justify-between border-t pt-1.5 text-base font-bold text-foreground">
                  <span>Total Credit:</span>
                  <span className="tabular-nums text-primary">{formatMoneyCents(totalCents)}</span>
                </div>
              </div>
            </div>
          </div>

          <div>
            <Label htmlFor="notes">Notes / Internal Remarks</Label>
            <Input
              id="notes"
              className="mt-1.5"
              placeholder="e.g. Return authorized by customer service..."
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              disabled={isViewMode}
            />
          </div>

          <DialogFooter className="gap-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Close
            </Button>
            {isViewMode && creditNote.status === 'draft' && (
              <Button
                type="button"
                variant="primary"
                className="bg-emerald-600 hover:bg-emerald-700 text-white"
                onClick={handlePost}
                disabled={loading}
              >
                <CheckCircle className="h-4 w-4 mr-1.5" /> Post Credit Note
              </Button>
            )}
            {!isViewMode && (
              <Button type="submit" variant="primary" disabled={loading}>
                {loading ? 'Creating...' : 'Create Credit Note'}
              </Button>
            )}
          </DialogFooter>
        </form>
    </Dialog>
  );
}
