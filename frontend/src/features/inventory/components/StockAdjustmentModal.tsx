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
import { inventoryApi } from '../api/inventoryApi';
import type {
  ProductItem,
  StockAdjustmentFormData,
  WarehouseItem,
} from '../types/inventory.types';
import { AlertCircle, Loader2, Plus, Trash2 } from 'lucide-react';

interface StockAdjustmentModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  warehouses: WarehouseItem[];
  products: ProductItem[];
  defaultWarehouseId?: string;
  defaultProductId?: string;
  onSuccess: (message: string) => void;
}

interface AdjustmentRow {
  productId: string;
  quantityDelta: string;
  unitCost: string;
}

const COMMON_REASONS = [
  'Cycle count discrepancy',
  'Damaged stock',
  'Theft / shrinkage',
  'Expired goods',
  'Found stock',
  'Initial inventory setup',
  'Other',
];

function formatCurrency(cents: number): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'NPR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
    .format(cents / 100)
    .replace('NPR', 'NPR ');
}

export function StockAdjustmentModal({
  open,
  onOpenChange,
  warehouses,
  products,
  defaultWarehouseId,
  defaultProductId,
  onSuccess,
}: StockAdjustmentModalProps) {
  const [warehouseId, setWarehouseId] = useState('');
  const [reasonPreset, setReasonPreset] = useState(COMMON_REASONS[0]);
  const [customReason, setCustomReason] = useState('');
  const [items, setItems] = useState<AdjustmentRow[]>([
    { productId: '', quantityDelta: '1', unitCost: '0.00' },
  ]);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
      const defaultWh = defaultWarehouseId || warehouses.find((w) => w.is_default)?.id || warehouses[0]?.id || '';
      setWarehouseId(defaultWh);
      setReasonPreset(COMMON_REASONS[0]);
      setCustomReason('');

      const initialProdId = defaultProductId || products[0]?.id || '';
      const initialProd = products.find((p) => p.id === initialProdId);
      const initialCost = initialProd ? (initialProd.cost_price_cents / 100).toFixed(2) : '0.00';

      setItems([{ productId: initialProdId, quantityDelta: '1', unitCost: initialCost }]);
    }
  }, [open, defaultWarehouseId, defaultProductId, warehouses, products]);

  const handleProductChange = (index: number, newProdId: string) => {
    const prod = products.find((p) => p.id === newProdId);
    const cost = prod ? (prod.cost_price_cents / 100).toFixed(2) : '0.00';
    setItems((prev) =>
      prev.map((row, i) =>
        i === index ? { ...row, productId: newProdId, unitCost: cost } : row
      )
    );
  };

  const handleDeltaChange = (index: number, val: string) => {
    setItems((prev) =>
      prev.map((row, i) => (i === index ? { ...row, quantityDelta: val } : row))
    );
  };

  const handleCostChange = (index: number, val: string) => {
    setItems((prev) =>
      prev.map((row, i) => (i === index ? { ...row, unitCost: val } : row))
    );
  };

  const addRow = () => {
    const firstProd = products[0];
    setItems((prev) => [
      ...prev,
      {
        productId: firstProd?.id || '',
        quantityDelta: '1',
        unitCost: firstProd ? (firstProd.cost_price_cents / 100).toFixed(2) : '0.00',
      },
    ]);
  };

  const removeRow = (index: number) => {
    if (items.length <= 1) return;
    setItems((prev) => prev.filter((_, i) => i !== index));
  };

  // Calculate total impact
  const totalCostImpactCents = items.reduce((acc, row) => {
    const delta = parseInt(row.quantityDelta, 10) || 0;
    const costCents = Math.round((parseFloat(row.unitCost) || 0) * 100);
    return acc + delta * costCents;
  }, 0);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!warehouseId) {
      setError('Please select a warehouse.');
      return;
    }

    const finalReason = reasonPreset === 'Other' ? customReason.trim() : reasonPreset;
    if (!finalReason || finalReason.length < 3) {
      setError('A mandatory reason (at least 3 characters) is required for manual stock adjustments.');
      return;
    }

    if (items.length === 0) {
      setError('Please add at least one line item.');
      return;
    }

    const parsedItems: StockAdjustmentFormData['items'] = [];
    for (let i = 0; i < items.length; i++) {
      const row = items[i];
      if (!row || !row.productId) {
        setError(`Please select a valid product for row #${i + 1}.`);
        return;
      }
      const delta = parseInt(row.quantityDelta, 10);
      if (isNaN(delta) || delta === 0) {
        setError(`Row #${i + 1}: Quantity delta cannot be 0. Enter positive for stock addition, negative for stock reduction.`);
        return;
      }
      const costCents = Math.round((parseFloat(row.unitCost) || 0) * 100);
      parsedItems.push({
        product_id: row.productId,
        quantity_delta: delta,
        unit_cost_cents: costCents >= 0 ? costCents : undefined,
      });
    }

    setSubmitting(true);
    setError(null);

    try {
      const res = await inventoryApi.createStockAdjustment({
        warehouse_id: warehouseId,
        reason: finalReason,
        items: parsedItems,
      });

      onSuccess(`Stock Adjustment "${res.data.adjustment_number}" recorded successfully.`);
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to record stock adjustment.';
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
            <DialogTitle>Record Stock Adjustment</DialogTitle>
            <DialogDescription>
              Adjust product stock counts directly with an audited reason and financial cost impact.
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
                  <Label htmlFor="adj-warehouse" className="text-xs font-semibold">
                    Warehouse <span className="text-destructive">*</span>
                  </Label>
                  <select
                    id="adj-warehouse"
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

                <div className="space-y-1.5">
                  <Label htmlFor="adj-reason-preset" className="text-xs font-semibold">
                    Reason for Adjustment <span className="text-destructive">*</span>
                  </Label>
                  <select
                    id="adj-reason-preset"
                    value={reasonPreset}
                    onChange={(e) => setReasonPreset(e.target.value)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                    required
                  >
                    {COMMON_REASONS.map((r) => (
                      <option key={r} value={r}>
                        {r}
                      </option>
                    ))}
                  </select>
                </div>

                {reasonPreset === 'Other' && (
                  <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor="adj-custom-reason" className="text-xs font-semibold">
                      Specify Custom Reason <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="adj-custom-reason"
                      value={customReason}
                      onChange={(e) => setCustomReason(e.target.value)}
                      placeholder="e.g. Returned by customer under warranty testing"
                      required
                      className="h-9 text-xs"
                    />
                  </div>
                )}
              </div>

              {/* Items Section */}
              <div className="space-y-2 pt-2">
                <div className="flex items-center justify-between">
                  <Label className="text-xs font-semibold">
                    Products to Adjust <span className="text-destructive">*</span>
                  </Label>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addRow}
                    className="h-7 text-xs gap-1"
                  >
                    <Plus className="h-3.5 w-3.5" /> Add Row
                  </Button>
                </div>

                <div className="border rounded-md overflow-hidden">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/50 border-b text-muted-foreground">
                      <tr>
                        <th className="p-2.5 text-left font-medium">Product</th>
                        <th className="p-2.5 text-right font-medium w-28">Delta (Qty)</th>
                        <th className="p-2.5 text-right font-medium w-32">Unit Cost (NPR)</th>
                        <th className="p-2.5 text-right font-medium w-32">Cost Impact</th>
                        <th className="p-2.5 text-center font-medium w-10"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {items.map((row, idx) => {
                        const delta = parseInt(row.quantityDelta, 10) || 0;
                        const costCents = Math.round((parseFloat(row.unitCost) || 0) * 100);
                        const rowImpactCents = delta * costCents;

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
                                step="1"
                                value={row.quantityDelta}
                                onChange={(e) => handleDeltaChange(idx, e.target.value)}
                                placeholder="+1 / -1"
                                className="h-8 text-xs text-right"
                              />
                            </td>
                            <td className="p-2 text-right">
                              <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={row.unitCost}
                                onChange={(e) => handleCostChange(idx, e.target.value)}
                                className="h-8 text-xs text-right"
                              />
                            </td>
                            <td className="p-2 text-right font-mono font-medium">
                              <span
                                className={
                                  rowImpactCents < 0
                                    ? 'text-destructive font-semibold'
                                    : rowImpactCents > 0
                                    ? 'text-emerald-600 font-semibold'
                                    : 'text-muted-foreground'
                                }
                              >
                                {rowImpactCents < 0 ? '-' : rowImpactCents > 0 ? '+' : ''}
                                {formatCurrency(Math.abs(rowImpactCents))}
                              </span>
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

                <div className="flex items-center justify-between p-3 rounded-md bg-muted/40 text-xs">
                  <span className="text-muted-foreground">
                    Tip: Use negative delta (-5) for write-offs or damages; positive (+10) for found items.
                  </span>
                  <div className="flex items-center gap-2">
                    <span className="font-semibold text-foreground">Total Cost Impact:</span>
                    <span
                      className={`font-mono font-bold text-sm ${
                        totalCostImpactCents < 0
                          ? 'text-destructive'
                          : totalCostImpactCents > 0
                          ? 'text-emerald-600'
                          : 'text-foreground'
                      }`}
                    >
                      {totalCostImpactCents < 0 ? '-' : totalCostImpactCents > 0 ? '+' : ''}
                      {formatCurrency(Math.abs(totalCostImpactCents))}
                    </span>
                  </div>
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
                Post Stock Adjustment
              </Button>
            </DialogFooter>
          </form>
        </div>
      </div>
    </Dialog>
  );
}
