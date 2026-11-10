import { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { RefreshCw, Calendar, TrendingUp, TrendingDown, DollarSign } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { ProfitAndLossData } from '../types/reports.types';
import { formatMoneyCents } from '../utils/formatters';
import { ReportExportButtons } from './ReportExportButtons';

export function ProfitAndLossViewer() {
  const [data, setData] = useState<ProfitAndLossData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [startDate, setStartDate] = useState<string>(() => {
    const d = new Date();
    return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0] ?? '';
  });

  const [endDate, setEndDate] = useState<string>(() => {
    return new Date().toISOString().split('T')[0] ?? '';
  });

  const fetchReport = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await reportsApi.getProfitAndLoss({
        start_date: startDate || undefined,
        end_date: endDate || undefined,
      });
      setData(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load Profit & Loss.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [startDate, endDate]);

  const applyThisMonth = () => {
    const d = new Date();
    const firstDay = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0] ?? '';
    const today = d.toISOString().split('T')[0] ?? '';
    setStartDate(firstDay);
    setEndDate(today);
  };

  const applyThisYear = () => {
    const d = new Date();
    const firstDay = new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0] ?? '';
    const today = d.toISOString().split('T')[0] ?? '';
    setStartDate(firstDay);
    setEndDate(today);
  };

  return (
    <div className="space-y-6">
      {/* Controls & Filter Bar */}
      <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
          <h2 className="text-xl font-bold tracking-tight text-foreground">Profit &amp; Loss Statement</h2>
          <p className="text-sm text-muted-foreground">
            Financial performance summary showing revenue, costs, and net profitability over the selected period.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">From:</span>
            <Input
              type="date"
              aria-label="Start Date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              className="w-36 h-9"
            />
          </div>
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">To:</span>
            <Input
              type="date"
              aria-label="End Date"
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
              className="w-36 h-9"
            />
          </div>
          <div className="flex items-center gap-1.5">
            <Button variant="outline" size="sm" onClick={applyThisMonth} className="h-9 text-xs">
              This Month
            </Button>
            <Button variant="outline" size="sm" onClick={applyThisYear} className="h-9 text-xs">
              This Year
            </Button>
            <Button variant="outline" size="sm" onClick={fetchReport} disabled={loading} className="gap-1.5 h-9">
              <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            <ReportExportButtons
              reportType="profit-and-loss"
              filters={{ start_date: startDate, end_date: endDate }}
            />
          </div>
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-md bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20">
          {error}
        </div>
      )}

      {/* KPI Headline Cards */}
      {data && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground flex items-center gap-1.5">
                <TrendingUp className="h-3.5 w-3.5 text-emerald-600" />
                Revenue
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.total_revenue_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground flex items-center gap-1.5">
                <TrendingDown className="h-3.5 w-3.5 text-amber-600" />
                COGS
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.total_cogs_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground flex items-center gap-1.5">
                <DollarSign className="h-3.5 w-3.5 text-blue-600" />
                Gross Profit
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.gross_profit_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground flex items-center gap-1.5">
                <Calendar className="h-3.5 w-3.5 text-purple-600" />
                Operating Expenses
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.total_operating_expenses_cents)}
              </div>
            </CardContent>
          </Card>

          <Card className={data.net_income_cents >= 0 ? 'border-emerald-300 dark:border-emerald-800' : 'border-destructive/40'}>
            <CardHeader className="pb-1">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Net Income</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <span className={`text-xl font-bold font-mono tracking-tight ${data.net_income_cents >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'}`}>
                  {formatMoneyCents(data.net_income_cents)}
                </span>
                <Badge variant={data.net_income_cents >= 0 ? 'outline' : 'destructive'} className="text-[10px] py-0">
                  {data.net_income_cents >= 0 ? 'Profit' : 'Loss'}
                </Badge>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Main Income Statement Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 border-b border-border text-xs uppercase text-muted-foreground font-semibold">
                <tr>
                  <th className="px-6 py-3 text-left w-36">Code</th>
                  <th className="px-6 py-3 text-left">Category &amp; Account</th>
                  <th className="px-6 py-3 text-right w-48">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={3} className="px-6 py-12 text-center text-muted-foreground">
                      <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-muted-foreground" />
                      Loading income statement...
                    </td>
                  </tr>
                ) : !data ? (
                  <tr>
                    <td colSpan={3} className="px-6 py-12 text-center text-muted-foreground">
                      No data available.
                    </td>
                  </tr>
                ) : (
                  <>
                    {/* 1. Operating Revenue */}
                    <tr className="bg-muted/30 font-semibold text-foreground">
                      <td colSpan={2} className="px-6 py-2.5 uppercase text-xs tracking-wider">
                        1. Operating Revenue
                      </td>
                      <td className="px-6 py-2.5 text-right font-mono tabular-nums">
                        {formatMoneyCents(data.total_revenue_cents)}
                      </td>
                    </tr>
                    {data.revenue_accounts.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-2 pl-12 text-xs text-muted-foreground italic">
                          No revenue accounts posted in this period.
                        </td>
                      </tr>
                    ) : (
                      data.revenue_accounts.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-10">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* 2. Cost of Goods Sold */}
                    <tr className="bg-muted/30 font-semibold text-foreground">
                      <td colSpan={2} className="px-6 py-2.5 uppercase text-xs tracking-wider">
                        2. Cost of Goods Sold (COGS)
                      </td>
                      <td className="px-6 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.total_cogs_cents)}
                      </td>
                    </tr>
                    {data.cogs_accounts.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-2 pl-12 text-xs text-muted-foreground italic">
                          No COGS accounts posted in this period.
                        </td>
                      </tr>
                    ) : (
                      data.cogs_accounts.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-10">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* Gross Profit Highlight Row */}
                    <tr className="bg-primary/5 font-bold text-foreground border-t border-b border-primary/20">
                      <td colSpan={2} className="px-6 py-3 uppercase text-xs tracking-wider text-primary">
                        Gross Profit (Revenue - COGS)
                      </td>
                      <td className="px-6 py-3 text-right font-mono tabular-nums text-primary">
                        {formatMoneyCents(data.gross_profit_cents)}
                      </td>
                    </tr>

                    {/* 3. Operating Expenses */}
                    <tr className="bg-muted/30 font-semibold text-foreground">
                      <td colSpan={2} className="px-6 py-2.5 uppercase text-xs tracking-wider">
                        3. Operating Expenses
                      </td>
                      <td className="px-6 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.total_operating_expenses_cents)}
                      </td>
                    </tr>
                    {data.expense_accounts.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-2 pl-12 text-xs text-muted-foreground italic">
                          No operating expenses posted in this period.
                        </td>
                      </tr>
                    ) : (
                      data.expense_accounts.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-10">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* Final Net Income Row */}
                    <tr className={`font-bold text-base border-t-2 border-border ${data.net_income_cents >= 0 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-destructive/10 text-destructive'}`}>
                      <td colSpan={2} className="px-6 py-3.5 uppercase text-sm tracking-wider">
                        Net Income / (Loss)
                      </td>
                      <td className="px-6 py-3.5 text-right font-mono tabular-nums text-base">
                        {formatMoneyCents(data.net_income_cents)}
                      </td>
                    </tr>
                  </>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
