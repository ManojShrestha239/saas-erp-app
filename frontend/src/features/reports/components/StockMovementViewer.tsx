import { useEffect, useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { RefreshCw, ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { StockMovementData } from '../types/reports.types';
import { formatDate, formatMoneyCents } from '../utils/formatters';

export function StockMovementViewer() {
  const [data, setData] = useState<StockMovementData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [movementType, setMovementType] = useState<string>('');
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [page, setPage] = useState(1);

  const fetchReport = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await reportsApi.getStockMovements({
        type: movementType || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page,
        per_page: 25,
      });
      setData(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load stock movements.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [movementType, dateFrom, dateTo, page]);

  return (
    <div className="space-y-6">
      {/* Header and Controls */}
      <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
          <h2 className="text-xl font-bold tracking-tight text-foreground">Stock Movement History</h2>
          <p className="text-sm text-muted-foreground">
            Complete audit trail of all inventory receipts, issues, adjustments, and transfers across warehouses.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <select
            value={movementType}
            onChange={(e) => {
              setMovementType(e.target.value);
              setPage(1);
            }}
            className="h-9 px-3 text-xs bg-background border border-border rounded-md text-foreground focus:ring-1 focus:ring-primary"
          >
            <option value="">All Movement Types</option>
            <option value="opening_balance">Opening Balance</option>
            <option value="purchase_received">Purchase Received</option>
            <option value="sales_delivered">Sales Delivered</option>
            <option value="adjustment_increase">Adjustment Increase</option>
            <option value="adjustment_decrease">Adjustment Decrease</option>
            <option value="transfer_in">Transfer In</option>
            <option value="transfer_out">Transfer Out</option>
          </select>

          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">From:</span>
            <Input
              type="date"
              aria-label="Date From"
              value={dateFrom}
              onChange={(e) => {
                setDateFrom(e.target.value);
                setPage(1);
              }}
              className="w-36 h-9"
            />
          </div>

          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">To:</span>
            <Input
              type="date"
              aria-label="Date To"
              value={dateTo}
              onChange={(e) => {
                setDateTo(e.target.value);
                setPage(1);
              }}
              className="w-36 h-9"
            />
          </div>

          <Button variant="outline" size="sm" onClick={fetchReport} disabled={loading} className="gap-1.5 h-9">
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-md bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20">
          {error}
        </div>
      )}

      {/* Main Movement History Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 border-b border-border text-xs uppercase text-muted-foreground font-semibold">
                <tr>
                  <th className="px-4 py-3 text-left w-36">Timestamp</th>
                  <th className="px-4 py-3 text-left">Product</th>
                  <th className="px-4 py-3 text-left w-44">Warehouse</th>
                  <th className="px-4 py-3 text-left w-36">Type</th>
                  <th className="px-4 py-3 text-right w-28">Quantity</th>
                  <th className="px-4 py-3 text-right w-36">Unit Cost</th>
                  <th className="px-4 py-3 text-left w-48">Notes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                      <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-muted-foreground" />
                      Loading stock movements...
                    </td>
                  </tr>
                ) : !data || data.items.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                      No stock movements recorded for the selected criteria.
                    </td>
                  </tr>
                ) : (
                  data.items.map((mov) => {
                    const isPositive = mov.quantity > 0;
                    return (
                      <tr key={mov.id} className="hover:bg-muted/20 transition-colors">
                        <td className="px-4 py-2.5 text-xs text-muted-foreground font-mono">
                          {formatDate(mov.created_at)}
                        </td>
                        <td className="px-4 py-2.5">
                          <div className="font-medium text-foreground">{mov.product?.name ?? mov.product_id}</div>
                          {mov.product?.sku && (
                            <div className="text-xs font-mono text-muted-foreground">{mov.product.sku}</div>
                          )}
                        </td>
                        <td className="px-4 py-2.5 text-xs text-foreground font-medium">
                          {mov.warehouse?.name ?? mov.warehouse_id}
                        </td>
                        <td className="px-4 py-2.5">
                          <Badge variant="outline" className="text-[11px] capitalize font-medium">
                            {mov.type_label ?? mov.type.replace(/_/g, ' ')}
                          </Badge>
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono tabular-nums font-semibold">
                          <span className={`inline-flex items-center gap-1 ${isPositive ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'}`}>
                            {isPositive ? <ArrowUpRight className="h-3.5 w-3.5" /> : <ArrowDownRight className="h-3.5 w-3.5" />}
                            {mov.quantity > 0 ? `+${mov.quantity}` : mov.quantity}
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono tabular-nums text-foreground">
                          {formatMoneyCents(mov.unit_cost_cents)}
                        </td>
                        <td className="px-4 py-2.5 text-xs text-muted-foreground truncate max-w-xs">
                          {mov.notes ?? '—'}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination Controls */}
          {data && data.meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-border">
              <span className="text-xs text-muted-foreground">
                Page {data.meta.current_page} of {data.meta.last_page} ({data.meta.total} movements)
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="h-8 text-xs"
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= data.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                  className="h-8 text-xs"
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
