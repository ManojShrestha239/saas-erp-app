import React, { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, AlertTriangle, RefreshCw, Calendar, Search, Package } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { StockValuationData } from '../types/reports.types';
import { formatMoneyCents } from '../utils/formatters';
import { ReportExportButtons } from './ReportExportButtons';

export function StockValuationViewer() {
  const [data, setData] = useState<StockValuationData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [asOfDate, setAsOfDate] = useState<string>(() => new Date().toISOString().split('T')[0] ?? '');

  const fetchReport = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await reportsApi.getStockValuation({
        as_of_date: asOfDate || undefined,
        search: search || undefined,
        page,
        per_page: 25,
      });
      setData(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load stock valuation.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [asOfDate, page]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchReport();
  };

  return (
    <div className="space-y-6">
      {/* Header and Controls */}
      <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
          <h2 className="text-xl font-bold tracking-tight text-foreground">Stock Valuation Report</h2>
          <p className="text-sm text-muted-foreground">
            Multi-warehouse inventory valuation based on perpetual moving weighted average cost reconciling with GL Control Account{' '}
            <span className="font-mono font-semibold text-foreground">1150</span>.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <form onSubmit={handleSearchSubmit} className="flex items-center gap-2">
            <div className="relative">
              <Search className="h-4 w-4 absolute left-2.5 top-2.5 text-muted-foreground" />
              <Input
                type="text"
                placeholder="Search SKU or Name..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-8 h-9 w-48 text-xs"
              />
            </div>
            <Button type="submit" variant="secondary" size="sm" className="h-9 text-xs">
              Search
            </Button>
          </form>

          <div className="flex items-center gap-2">
            <Calendar className="h-4 w-4 text-muted-foreground" />
            <Input
              type="date"
              aria-label="As of Date"
              value={asOfDate}
              onChange={(e) => setAsOfDate(e.target.value)}
              className="w-40 h-9"
            />
          </div>

          <Button variant="outline" size="sm" onClick={fetchReport} disabled={loading} className="gap-1.5 h-9">
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>

          <ReportExportButtons
            reportType="stock-valuation"
            filters={{ as_of_date: asOfDate, search: search || undefined }}
          />
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-md bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20">
          {error}
        </div>
      )}

      {/* Control Account 1150 Reconciliation Banner */}
      {data && (
        <div
          className={`flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 rounded-lg border gap-4 ${
            data.summary.is_reconciled
              ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-300'
              : 'bg-destructive/10 border-destructive/30 text-destructive'
          }`}
        >
          <div className="flex items-center gap-3">
            {data.summary.is_reconciled ? (
              <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            ) : (
              <AlertTriangle className="h-5 w-5 shrink-0 text-destructive" />
            )}
            <div>
              <div className="font-semibold text-sm">
                Control Account Reconciliation: {data.summary.is_reconciled ? 'Fully Reconciled' : 'Discrepancy Detected'}
              </div>
              <div className="text-xs opacity-90">
                Inventory Valuation Subledger: <span className="font-mono font-medium">{formatMoneyCents(data.summary.total_valuation_cents)}</span> | GL Control Account (1150):{' '}
                <span className="font-mono font-medium">{formatMoneyCents(data.summary.gl_control_account_cents)}</span>
              </div>
            </div>
          </div>
          <Badge
            variant={data.summary.is_reconciled ? 'outline' : 'destructive'}
            className={data.summary.is_reconciled ? 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-300 text-emerald-700 dark:text-emerald-400' : ''}
          >
            {data.summary.is_reconciled ? 'Diff: 0.00' : `Diff: ${formatMoneyCents(data.summary.reconciliation_difference_cents)}`}
          </Badge>
        </div>
      )}

      {/* Summary KPI Cards */}
      {data && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Inventory Valuation</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.summary.total_valuation_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Units On Hand</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {data.summary.total_units_on_hand.toLocaleString()}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Valued Products</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {data.summary.valued_products_count} / {data.summary.total_products_count}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Costing Method</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-sm font-semibold text-foreground flex items-center gap-1.5 pt-1">
                <Package className="h-4 w-4 text-primary" />
                Moving Weighted Average
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Main Stock Valuation Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 border-b border-border text-xs uppercase text-muted-foreground font-semibold">
                <tr>
                  <th className="px-4 py-3 text-left w-32">SKU</th>
                  <th className="px-4 py-3 text-left">Product Name</th>
                  <th className="px-4 py-3 text-left w-32">Category</th>
                  <th className="px-3 py-3 text-right w-24">Qty on Hand</th>
                  <th className="px-4 py-3 text-right w-36">Moving Avg Cost</th>
                  <th className="px-4 py-3 text-right w-36">Selling Price</th>
                  <th className="px-4 py-3 text-right w-40 font-bold">Total Valuation</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                      <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-muted-foreground" />
                      Loading inventory valuation...
                    </td>
                  </tr>
                ) : !data || data.items.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                      No stock valuation items found.
                    </td>
                  </tr>
                ) : (
                  data.items.map((item) => (
                    <tr key={item.product_id} className="hover:bg-muted/20 transition-colors">
                      <td className="px-4 py-2.5 font-mono text-xs font-semibold text-foreground">{item.sku}</td>
                      <td className="px-4 py-2.5 font-medium text-foreground">{item.name}</td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">{item.category_name ?? '—'}</td>
                      <td className="px-3 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {item.total_quantity_on_hand.toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(item.moving_average_cost_cents)}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono tabular-nums text-muted-foreground">
                        {formatMoneyCents(item.selling_price_cents)}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono tabular-nums font-bold text-foreground">
                        {formatMoneyCents(item.total_valuation_cents)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination Controls */}
          {data && data.meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-border">
              <span className="text-xs text-muted-foreground">
                Page {data.meta.current_page} of {data.meta.last_page} ({data.meta.total} products)
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
