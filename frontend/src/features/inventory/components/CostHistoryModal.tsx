import { useState, useEffect } from 'react';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { inventoryApi } from '../api/inventoryApi';
import type { CostHistoryItem, ProductCostHistoryResponse } from '../types/inventory.types';
import { RefreshCw, History, ArrowDownRight, ArrowUpRight, Clock, AlertCircle } from 'lucide-react';

interface CostHistoryModalProps {
  productId: string | null;
  isOpen: boolean;
  onClose: () => void;
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

function formatDate(dateStr?: string): string {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return dateStr;
  }
}

export function CostHistoryModal({ productId, isOpen, onClose }: CostHistoryModalProps) {
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [data, setData] = useState<ProductCostHistoryResponse | null>(null);

  useEffect(() => {
    if (!isOpen || !productId) {
      setData(null);
      setError(null);
      return;
    }

    let isMounted = true;
    setLoading(true);
    setError(null);

    inventoryApi
      .getCostHistory(productId)
      .then((res) => {
        if (isMounted) {
          setData(res.data);
        }
      })
      .catch((err: Error) => {
        if (isMounted) {
          setError(err.message || 'Failed to load stock costing audit trail.');
        }
      })
      .finally(() => {
        if (isMounted) {
          setLoading(false);
        }
      });

    return () => {
      isMounted = false;
    };
  }, [productId, isOpen]);

  const getMovementBadgeVariant = (type: string) => {
    switch (type) {
      case 'purchase_receipt':
        return 'success';
      case 'sales_delivery':
        return 'secondary';
      case 'adjustment_in':
        return 'outline';
      case 'adjustment_out':
        return 'destructive';
      default:
        return 'default';
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in-0">
        <div className="bg-card w-full max-w-4xl max-h-[85vh] rounded-lg shadow-xl border border-border flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b border-border bg-surface-muted/40">
            <div className="flex items-center justify-between">
              <div className="flex items-center space-x-3">
                <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                  <History className="w-5 h-5" />
                </div>
                <div>
                  <DialogTitle className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2">
                    Moving Weighted Average Cost Audit Trail
                    <Badge variant="outline" className="text-xs font-mono font-medium">
                      AVCO
                    </Badge>
                  </DialogTitle>
                  <DialogDescription className="text-sm text-muted-foreground mt-0.5">
                    Continuous chronological ledger of all physical movements and unit cost recalculations.
                  </DialogDescription>
                </div>
              </div>
            </div>

            {/* Product Summary Strip */}
            {data?.product && (
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 pt-3 border-t border-border/60">
                <div className="bg-background/80 rounded-md p-2.5 border border-border/50">
                  <span className="text-xs font-medium text-muted-foreground block">Product / SKU</span>
                  <span className="text-sm font-semibold text-foreground truncate block">
                    {data.product.name}
                  </span>
                  <span className="text-xs text-muted-foreground font-mono">{data.product.sku}</span>
                </div>

                <div className="bg-background/80 rounded-md p-2.5 border border-border/50">
                  <span className="text-xs font-medium text-muted-foreground block">Moving Avg Cost</span>
                  <span className="text-sm font-bold text-primary tabular-nums block">
                    {formatMoneyCents(data.product.current_moving_average_cost_cents)}
                  </span>
                  <span className="text-xs text-muted-foreground">Per unit on-hand</span>
                </div>

                <div className="bg-background/80 rounded-md p-2.5 border border-border/50">
                  <span className="text-xs font-medium text-muted-foreground block">Total On Hand</span>
                  <span className="text-sm font-semibold text-foreground tabular-nums block">
                    {data.product.total_quantity_on_hand.toLocaleString()} units
                  </span>
                  <span className="text-xs text-muted-foreground">Across all warehouses</span>
                </div>

                <div className="bg-background/80 rounded-md p-2.5 border border-border/50">
                  <span className="text-xs font-medium text-muted-foreground block">Total Stock Valuation</span>
                  <span className="text-sm font-bold text-foreground tabular-nums block">
                    {formatMoneyCents(data.product.total_valuation_cents)}
                  </span>
                  <span className="text-xs text-muted-foreground">Current total asset value</span>
                </div>
              </div>
            )}
          </DialogHeader>

          {/* Modal Body / Table */}
          <div className="flex-1 overflow-y-auto p-6">
            {loading ? (
              <div className="py-12 flex flex-col items-center justify-center space-y-3">
                <RefreshCw className="w-8 h-8 text-primary animate-spin" />
                <p className="text-sm text-muted-foreground">Reconstructing moving average cost history...</p>
              </div>
            ) : error ? (
              <div className="py-8 flex flex-col items-center justify-center text-center space-y-2">
                <AlertCircle className="w-8 h-8 text-destructive" />
                <p className="text-sm font-medium text-destructive">{error}</p>
              </div>
            ) : data?.history && data.history.length > 0 ? (
              <div className="border border-border rounded-md overflow-hidden bg-background">
                <table className="w-full text-xs text-left">
                  <thead className="bg-surface-muted/60 text-muted-foreground border-b border-border uppercase font-semibold tracking-wider">
                    <tr>
                      <th className="py-2.5 px-3">Date & Time</th>
                      <th className="py-2.5 px-3">Type</th>
                      <th className="py-2.5 px-3">Warehouse</th>
                      <th className="py-2.5 px-3 text-right">Qty Delta</th>
                      <th className="py-2.5 px-3 text-right">Movement Cost</th>
                      <th className="py-2.5 px-3 text-right">Resulting Avg Cost</th>
                      <th className="py-2.5 px-3 text-right">Stock Valuation</th>
                      <th className="py-2.5 px-3">Reference / Notes</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {data.history.map((item: CostHistoryItem) => (
                      <tr key={item.movement_id} className="hover:bg-surface-muted/30 transition-colors">
                        <td className="py-2 px-3 whitespace-nowrap text-muted-foreground font-mono">
                          <span className="flex items-center gap-1.5">
                            <Clock className="w-3 h-3" />
                            {formatDate(item.created_at)}
                          </span>
                        </td>
                        <td className="py-2 px-3 whitespace-nowrap">
                          <Badge variant={getMovementBadgeVariant(item.type)} className="text-[10px] font-medium">
                            {item.type_label}
                          </Badge>
                        </td>
                        <td className="py-2 px-3 whitespace-nowrap font-medium text-foreground">
                          {item.warehouse_name}
                        </td>
                        <td className="py-2 px-3 whitespace-nowrap text-right font-mono font-semibold">
                          <span
                            className={`inline-flex items-center gap-0.5 tabular-nums ${
                              item.quantity_delta > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-foreground'
                            }`}
                          >
                            {item.quantity_delta > 0 ? (
                              <ArrowUpRight className="w-3 h-3" />
                            ) : (
                              <ArrowDownRight className="w-3 h-3 text-muted-foreground" />
                            )}
                            {item.quantity_delta > 0 ? `+${item.quantity_delta}` : item.quantity_delta}
                          </span>
                        </td>
                        <td className="py-2 px-3 whitespace-nowrap text-right font-mono tabular-nums text-foreground">
                          {formatMoneyCents(item.movement_unit_cost_cents)}
                        </td>
                        <td className="py-2 px-3 whitespace-nowrap text-right font-mono font-bold tabular-nums text-primary">
                          {formatMoneyCents(item.resulting_average_cost_cents)}
                        </td>
                        <td className="py-2 px-3 whitespace-nowrap text-right font-mono font-medium tabular-nums text-foreground">
                          {formatMoneyCents(item.resulting_valuation_cents)}
                        </td>
                        <td className="py-2 px-3 text-muted-foreground truncate max-w-xs">
                          {item.notes || item.source_type || '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="py-12 text-center text-muted-foreground text-sm">
                No inventory stock movements recorded yet for this product.
              </div>
            )}
          </div>

          <DialogFooter className="p-4 border-t border-border bg-surface-muted/20 flex items-center justify-end">
            <Button variant="outline" onClick={onClose} size="sm">
              Close
            </Button>
          </DialogFooter>
        </div>
      </div>
    </Dialog>
  );
}
