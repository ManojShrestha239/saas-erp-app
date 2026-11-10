import { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, AlertTriangle, RefreshCw, Calendar, Scale } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { BalanceSheetData } from '../types/reports.types';
import { formatMoneyCents } from '../utils/formatters';
import { ReportExportButtons } from './ReportExportButtons';

export function BalanceSheetViewer() {
  const [data, setData] = useState<BalanceSheetData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [asOfDate, setAsOfDate] = useState<string>(() => new Date().toISOString().split('T')[0] ?? '');

  const fetchReport = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await reportsApi.getBalanceSheet({
        as_of_date: asOfDate || undefined,
      });
      setData(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load Balance Sheet.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [asOfDate]);

  return (
    <div className="space-y-6">
      {/* Header and Controls */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
          <h2 className="text-xl font-bold tracking-tight text-foreground">Balance Sheet</h2>
          <p className="text-sm text-muted-foreground">
            Statement of financial position showing assets, liabilities, and net equity satisfying the fundamental accounting equation.
          </p>
        </div>
        <div className="flex items-center gap-3">
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
            reportType="balance-sheet"
            filters={{ as_of_date: asOfDate }}
          />
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-md bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20">
          {error}
        </div>
      )}

      {/* KPI Cards */}
      {data && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Assets</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.assets.total_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Liabilities</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.liabilities.total_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Equity</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.equity.total_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Balance Equation</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                {data.is_balanced ? (
                  <Badge variant="outline" className="bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border-emerald-300 gap-1.5 py-1 px-2.5">
                    <CheckCircle2 className="h-4 w-4" />
                    Assets = Liab + Equity
                  </Badge>
                ) : (
                  <Badge variant="destructive" className="gap-1.5 py-1 px-2.5">
                    <AlertTriangle className="h-4 w-4" />
                    Diff: {formatMoneyCents(data.net_difference_cents)}
                  </Badge>
                )}
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Main Balance Sheet Multi-group Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 border-b border-border text-xs uppercase text-muted-foreground font-semibold">
                <tr>
                  <th className="px-6 py-3 text-left w-36">Code</th>
                  <th className="px-6 py-3 text-left">Account Description</th>
                  <th className="px-6 py-3 text-right w-48">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={3} className="px-6 py-12 text-center text-muted-foreground">
                      <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-muted-foreground" />
                      Loading balance sheet statement...
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
                    {/* SECTION 1: ASSETS */}
                    <tr className="bg-muted/40 font-bold text-foreground">
                      <td colSpan={2} className="px-6 py-3 uppercase text-xs tracking-wider">
                        1. Assets
                      </td>
                      <td className="px-6 py-3 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.assets.total_cents)}
                      </td>
                    </tr>

                    {/* Current Assets */}
                    <tr className="bg-muted/10 font-semibold text-xs text-muted-foreground">
                      <td colSpan={3} className="px-6 py-1.5 pl-8 uppercase">
                        Current Assets
                      </td>
                    </tr>
                    {data.assets.current.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-1.5 pl-12 text-xs text-muted-foreground italic">
                          No current asset accounts.
                        </td>
                      </tr>
                    ) : (
                      data.assets.current.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-12">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* Non-Current Assets */}
                    <tr className="bg-muted/10 font-semibold text-xs text-muted-foreground">
                      <td colSpan={3} className="px-6 py-1.5 pl-8 uppercase">
                        Non-Current Assets
                      </td>
                    </tr>
                    {data.assets.non_current.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-1.5 pl-12 text-xs text-muted-foreground italic">
                          No non-current asset accounts.
                        </td>
                      </tr>
                    ) : (
                      data.assets.non_current.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-12">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* Total Assets Summary Line */}
                    <tr className="bg-primary/5 font-bold text-foreground border-t border-b border-primary/20">
                      <td colSpan={2} className="px-6 py-2.5 uppercase text-xs tracking-wider text-primary">
                        Total Assets
                      </td>
                      <td className="px-6 py-2.5 text-right font-mono tabular-nums text-primary">
                        {formatMoneyCents(data.assets.total_cents)}
                      </td>
                    </tr>

                    {/* SECTION 2: LIABILITIES */}
                    <tr className="bg-muted/40 font-bold text-foreground">
                      <td colSpan={2} className="px-6 py-3 uppercase text-xs tracking-wider">
                        2. Liabilities
                      </td>
                      <td className="px-6 py-3 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.liabilities.total_cents)}
                      </td>
                    </tr>

                    {/* Current Liabilities */}
                    <tr className="bg-muted/10 font-semibold text-xs text-muted-foreground">
                      <td colSpan={3} className="px-6 py-1.5 pl-8 uppercase">
                        Current Liabilities
                      </td>
                    </tr>
                    {data.liabilities.current.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-1.5 pl-12 text-xs text-muted-foreground italic">
                          No current liability accounts.
                        </td>
                      </tr>
                    ) : (
                      data.liabilities.current.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-12">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* Non-Current Liabilities */}
                    <tr className="bg-muted/10 font-semibold text-xs text-muted-foreground">
                      <td colSpan={3} className="px-6 py-1.5 pl-8 uppercase">
                        Long-Term Liabilities
                      </td>
                    </tr>
                    {data.liabilities.non_current.length === 0 ? (
                      <tr>
                        <td colSpan={3} className="px-6 py-1.5 pl-12 text-xs text-muted-foreground italic">
                          No long-term liability accounts.
                        </td>
                      </tr>
                    ) : (
                      data.liabilities.non_current.map((acc) => (
                        <tr key={acc.account_id} className="hover:bg-muted/20">
                          <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-12">{acc.code}</td>
                          <td className="px-6 py-2 text-foreground">{acc.name}</td>
                          <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                            {formatMoneyCents(acc.amount_cents)}
                          </td>
                        </tr>
                      ))
                    )}

                    {/* Total Liabilities Summary Line */}
                    <tr className="bg-muted/20 font-bold text-foreground">
                      <td colSpan={2} className="px-6 py-2.5 uppercase text-xs tracking-wider">
                        Total Liabilities
                      </td>
                      <td className="px-6 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.liabilities.total_cents)}
                      </td>
                    </tr>

                    {/* SECTION 3: EQUITY */}
                    <tr className="bg-muted/40 font-bold text-foreground">
                      <td colSpan={2} className="px-6 py-3 uppercase text-xs tracking-wider">
                        3. Equity
                      </td>
                      <td className="px-6 py-3 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.equity.total_cents)}
                      </td>
                    </tr>

                    {/* Capital / Equity Accounts */}
                    {data.equity.accounts.map((acc) => (
                      <tr key={acc.account_id} className="hover:bg-muted/20">
                        <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-12">{acc.code}</td>
                        <td className="px-6 py-2 text-foreground">{acc.name}</td>
                        <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                          {formatMoneyCents(acc.amount_cents)}
                        </td>
                      </tr>
                    ))}

                    {/* Current Period Retained Net Earnings */}
                    <tr className="hover:bg-muted/20">
                      <td className="px-6 py-2 font-mono text-xs text-muted-foreground pl-12">Earnings</td>
                      <td className="px-6 py-2 text-foreground italic">Current Period Net Earnings (Retained)</td>
                      <td className="px-6 py-2 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.equity.current_period_earnings_cents)}
                      </td>
                    </tr>

                    {/* Total Equity Summary Line */}
                    <tr className="bg-muted/20 font-bold text-foreground">
                      <td colSpan={2} className="px-6 py-2.5 uppercase text-xs tracking-wider">
                        Total Equity
                      </td>
                      <td className="px-6 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {formatMoneyCents(data.equity.total_cents)}
                      </td>
                    </tr>

                    {/* GRAND TOTAL: Liabilities + Equity */}
                    <tr className="bg-card font-bold text-base border-t-2 border-b-2 border-border">
                      <td colSpan={2} className="px-6 py-3.5 uppercase text-sm tracking-wider flex items-center gap-2">
                        <Scale className="h-4 w-4 text-primary" />
                        Total Liabilities &amp; Equity
                      </td>
                      <td className="px-6 py-3.5 text-right font-mono tabular-nums text-base text-foreground">
                        {formatMoneyCents(data.total_liabilities_and_equity_cents)}
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
