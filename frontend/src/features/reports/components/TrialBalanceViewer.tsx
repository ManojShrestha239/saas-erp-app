import { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, AlertTriangle, RefreshCw, Calendar } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { TrialBalanceData } from '../types/reports.types';
import { formatMoneyCents } from '../utils/formatters';
import { ReportExportButtons } from './ReportExportButtons';

export function TrialBalanceViewer() {
  const [data, setData] = useState<TrialBalanceData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [asOfDate, setAsOfDate] = useState<string>(() => new Date().toISOString().split('T')[0] ?? '');
  const [includeZeros, setIncludeZeros] = useState(false);

  const fetchReport = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await reportsApi.getTrialBalance({
        as_of_date: asOfDate || undefined,
        include_zero_balances: includeZeros,
      });
      setData(res.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load trial balance.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [asOfDate, includeZeros]);

  return (
    <div className="space-y-6">
      {/* Header and Controls */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
          <h2 className="text-xl font-bold tracking-tight text-foreground">Trial Balance</h2>
          <p className="text-sm text-muted-foreground">
            Summary of all general ledger accounts as of a specific date verifying double-entry equilibrium.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
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
          <label className="flex items-center gap-2 text-xs font-medium cursor-pointer text-muted-foreground hover:text-foreground">
            <input
              type="checkbox"
              checked={includeZeros}
              onChange={(e) => setIncludeZeros(e.target.checked)}
              className="rounded border-border text-primary focus:ring-primary"
            />
            Include Zero Balances
          </label>
          <Button variant="outline" size="sm" onClick={fetchReport} disabled={loading} className="gap-1.5 h-9">
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
          <ReportExportButtons
            reportType="trial-balance"
            filters={{ as_of_date: asOfDate, include_zero_balances: includeZeros }}
          />
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-md bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20">
          {error}
        </div>
      )}

      {/* Summary KPI Cards */}
      {data && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Debits</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.total_debits_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Total Credits</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-mono tracking-tight text-foreground">
                {formatMoneyCents(data.total_credits_cents)}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs uppercase font-medium text-muted-foreground">Equilibrium Status</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                {data.is_balanced ? (
                  <Badge variant="outline" className="bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border-emerald-300 gap-1.5 py-1 px-2.5">
                    <CheckCircle2 className="h-4 w-4" />
                    Balanced (Net Diff: 0.00)
                  </Badge>
                ) : (
                  <Badge variant="destructive" className="gap-1.5 py-1 px-2.5">
                    <AlertTriangle className="h-4 w-4" />
                    Discrepancy: {formatMoneyCents(data.net_difference_cents)}
                  </Badge>
                )}
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Accounts Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead className="bg-muted/50 border-b border-border text-xs uppercase text-muted-foreground font-semibold">
                <tr>
                  <th className="px-4 py-3 w-28">Code</th>
                  <th className="px-4 py-3">Account Name</th>
                  <th className="px-4 py-3 w-32">Type</th>
                  <th className="px-4 py-3 text-right w-40">Debit Balance</th>
                  <th className="px-4 py-3 text-right w-40">Credit Balance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-12 text-center text-muted-foreground">
                      <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-muted-foreground" />
                      Loading trial balance accounts...
                    </td>
                  </tr>
                ) : !data || data.accounts.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-12 text-center text-muted-foreground">
                      No journal entries recorded for this period.
                    </td>
                  </tr>
                ) : (
                  data.accounts.map((acc) => (
                    <tr key={acc.account_id} className="hover:bg-muted/30 transition-colors">
                      <td className="px-4 py-2.5 font-mono text-xs font-semibold text-foreground">{acc.code}</td>
                      <td className="px-4 py-2.5 font-medium text-foreground">{acc.name}</td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground capitalize">{acc.type}</td>
                      <td className="px-4 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {acc.debit_balance_cents > 0 ? formatMoneyCents(acc.debit_balance_cents) : '—'}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono tabular-nums text-foreground">
                        {acc.credit_balance_cents > 0 ? formatMoneyCents(acc.credit_balance_cents) : '—'}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
              {data && data.accounts.length > 0 && (
                <tfoot className="bg-muted/60 font-bold border-t-2 border-border">
                  <tr>
                    <td colSpan={3} className="px-4 py-3 text-right uppercase text-xs tracking-wider">
                      Total
                    </td>
                    <td className="px-4 py-3 text-right font-mono tabular-nums text-foreground">
                      {formatMoneyCents(data.total_debits_cents)}
                    </td>
                    <td className="px-4 py-3 text-right font-mono tabular-nums text-foreground">
                      {formatMoneyCents(data.total_credits_cents)}
                    </td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
