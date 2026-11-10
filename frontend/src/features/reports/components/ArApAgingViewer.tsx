import { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, AlertTriangle, RefreshCw, Calendar, ChevronRight } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { ApAgingData, ArAgingData } from '../types/reports.types';
import { formatMoneyCents } from '../utils/formatters';
import { ReportExportButtons } from './ReportExportButtons';

export function ArApAgingViewer() {
  const [agingMode, setAgingMode] = useState<'ar' | 'ap'>('ar');
  const [arData, setArData] = useState<ArAgingData | null>(null);
  const [apData, setApData] = useState<ApAgingData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [asOfDate, setAsOfDate] = useState<string>(() => new Date().toISOString().split('T')[0] ?? '');
  const [selectedContactId, setSelectedContactId] = useState<string>('');

  const fetchReport = async () => {
    try {
      setLoading(true);
      setError(null);
      if (agingMode === 'ar') {
        const res = await reportsApi.getArAging({
          as_of_date: asOfDate || undefined,
          customer_id: selectedContactId || undefined,
        });
        setArData(res.data);
      } else {
        const res = await reportsApi.getApAging({
          as_of_date: asOfDate || undefined,
          supplier_id: selectedContactId || undefined,
        });
        setApData(res.data);
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load aging report.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [agingMode, asOfDate, selectedContactId]);

  const activeData = agingMode === 'ar' ? arData : apData;
  const controlAccountCode = agingMode === 'ar' ? '1130' : '2110';

  return (
    <div className="space-y-6">
      {/* Header and Controls */}
      <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <h2 className="text-xl font-bold tracking-tight text-foreground">
              {agingMode === 'ar' ? 'Accounts Receivable (AR) Aging' : 'Accounts Payable (AP) Aging'}
            </h2>
          </div>
          <p className="text-sm text-muted-foreground">
            Sub-ledger aging distribution across overdue brackets reconciling with General Ledger Control Account{' '}
            <span className="font-mono font-semibold text-foreground">{controlAccountCode}</span>.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {/* Mode Switcher */}
          <div className="inline-flex rounded-md shadow-sm bg-muted p-1 border border-border">
            <button
              type="button"
              onClick={() => {
                setAgingMode('ar');
                setSelectedContactId('');
              }}
              className={`px-3 py-1 text-xs font-semibold rounded transition-colors ${
                agingMode === 'ar'
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              AR (Customers)
            </button>
            <button
              type="button"
              onClick={() => {
                setAgingMode('ap');
                setSelectedContactId('');
              }}
              className={`px-3 py-1 text-xs font-semibold rounded transition-colors ${
                agingMode === 'ap'
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              AP (Suppliers)
            </button>
          </div>

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

          {selectedContactId && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setSelectedContactId('')}
              className="h-9 text-xs text-muted-foreground"
            >
              Clear Contact Filter
            </Button>
          )}

          <Button variant="outline" size="sm" onClick={fetchReport} disabled={loading} className="gap-1.5 h-9">
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>

          <ReportExportButtons
            reportType={agingMode === 'ar' ? 'ar-aging' : 'ap-aging'}
            filters={{
              as_of_date: asOfDate,
              ...(agingMode === 'ar'
                ? { customer_id: selectedContactId || undefined }
                : { supplier_id: selectedContactId || undefined }),
            }}
          />
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-md bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20">
          {error}
        </div>
      )}

      {/* Control Account Reconciliation Alert Banner */}
      {activeData && (
        <div
          className={`flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 rounded-lg border gap-4 ${
            activeData.is_reconciled
              ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-300'
              : 'bg-destructive/10 border-destructive/30 text-destructive'
          }`}
        >
          <div className="flex items-center gap-3">
            {activeData.is_reconciled ? (
              <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            ) : (
              <AlertTriangle className="h-5 w-5 shrink-0 text-destructive" />
            )}
            <div>
              <div className="font-semibold text-sm">
                Control Account Reconciliation: {activeData.is_reconciled ? 'Fully Reconciled' : 'Discrepancy Detected'}
              </div>
              <div className="text-xs opacity-90">
                Sub-ledger Total: <span className="font-mono font-medium">{formatMoneyCents(activeData.summary.total_due_cents)}</span> | GL Control Account ({controlAccountCode}):{' '}
                <span className="font-mono font-medium">{formatMoneyCents(activeData.gl_control_account_cents)}</span>
              </div>
            </div>
          </div>
          <Badge
            variant={activeData.is_reconciled ? 'outline' : 'destructive'}
            className={activeData.is_reconciled ? 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-300 text-emerald-700 dark:text-emerald-400' : ''}
          >
            {activeData.is_reconciled ? 'Diff: 0.00' : `Diff: ${formatMoneyCents(activeData.reconciliation_difference_cents)}`}
          </Badge>
        </div>
      )}

      {/* KPI Aging Brackets */}
      {activeData && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          <Card className="bg-card">
            <CardHeader className="pb-1 px-3 pt-3">
              <CardTitle className="text-[11px] uppercase font-medium text-muted-foreground">Current (Not Due)</CardTitle>
            </CardHeader>
            <CardContent className="px-3 pb-3">
              <div className="text-lg font-bold font-mono text-foreground">
                {formatMoneyCents(activeData.summary.current_cents)}
              </div>
            </CardContent>
          </Card>

          <Card className="bg-card">
            <CardHeader className="pb-1 px-3 pt-3">
              <CardTitle className="text-[11px] uppercase font-medium text-amber-600 dark:text-amber-400">1–30 Days</CardTitle>
            </CardHeader>
            <CardContent className="px-3 pb-3">
              <div className="text-lg font-bold font-mono text-amber-600 dark:text-amber-400">
                {formatMoneyCents(activeData.summary.days_1_30_cents)}
              </div>
            </CardContent>
          </Card>

          <Card className="bg-card">
            <CardHeader className="pb-1 px-3 pt-3">
              <CardTitle className="text-[11px] uppercase font-medium text-orange-600 dark:text-orange-400">31–60 Days</CardTitle>
            </CardHeader>
            <CardContent className="px-3 pb-3">
              <div className="text-lg font-bold font-mono text-orange-600 dark:text-orange-400">
                {formatMoneyCents(activeData.summary.days_31_60_cents)}
              </div>
            </CardContent>
          </Card>

          <Card className="bg-card">
            <CardHeader className="pb-1 px-3 pt-3">
              <CardTitle className="text-[11px] uppercase font-medium text-red-600 dark:text-red-400">61–90 Days</CardTitle>
            </CardHeader>
            <CardContent className="px-3 pb-3">
              <div className="text-lg font-bold font-mono text-red-600 dark:text-red-400">
                {formatMoneyCents(activeData.summary.days_61_90_cents)}
              </div>
            </CardContent>
          </Card>

          <Card className="bg-card">
            <CardHeader className="pb-1 px-3 pt-3">
              <CardTitle className="text-[11px] uppercase font-medium text-destructive">90+ Days</CardTitle>
            </CardHeader>
            <CardContent className="px-3 pb-3">
              <div className="text-lg font-bold font-mono text-destructive">
                {formatMoneyCents(activeData.summary.days_over_90_cents)}
              </div>
            </CardContent>
          </Card>

          <Card className="bg-card border-primary/30">
            <CardHeader className="pb-1 px-3 pt-3">
              <CardTitle className="text-[11px] uppercase font-bold text-primary">Total Due</CardTitle>
            </CardHeader>
            <CardContent className="px-3 pb-3">
              <div className="text-lg font-bold font-mono text-primary">
                {formatMoneyCents(activeData.summary.total_due_cents)}
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Main Aging Summary Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 border-b border-border text-xs uppercase text-muted-foreground font-semibold">
                <tr>
                  <th className="px-4 py-3 text-left w-28">Code</th>
                  <th className="px-4 py-3 text-left">
                    {agingMode === 'ar' ? 'Customer Name' : 'Supplier Name'}
                  </th>
                  <th className="px-3 py-3 text-right">Current</th>
                  <th className="px-3 py-3 text-right">1–30 d</th>
                  <th className="px-3 py-3 text-right">31–60 d</th>
                  <th className="px-3 py-3 text-right">61–90 d</th>
                  <th className="px-3 py-3 text-right">90+ d</th>
                  <th className="px-4 py-3 text-right font-bold">Total Due</th>
                  <th className="px-3 py-3 text-center w-20">Docs</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                      <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-muted-foreground" />
                      Loading aging breakdown...
                    </td>
                  </tr>
                ) : agingMode === 'ar' ? (
                  !arData || arData.customers.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                        No outstanding receivables found as of this date.
                      </td>
                    </tr>
                  ) : (
                    arData.customers.map((c) => (
                      <tr
                        key={c.customer_id}
                        onClick={() => setSelectedContactId(c.customer_id === selectedContactId ? '' : c.customer_id)}
                        className={`hover:bg-muted/30 transition-colors cursor-pointer ${
                          selectedContactId === c.customer_id ? 'bg-primary/5 font-medium' : ''
                        }`}
                      >
                        <td className="px-4 py-2.5 font-mono text-xs text-foreground font-semibold">{c.customer_code}</td>
                        <td className="px-4 py-2.5 text-foreground flex items-center gap-1.5">
                          {c.customer_name}
                          <ChevronRight className={`h-3 w-3 text-muted-foreground transition-transform ${selectedContactId === c.customer_id ? 'rotate-90' : ''}`} />
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-foreground">
                          {c.current_cents > 0 ? formatMoneyCents(c.current_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-amber-600 dark:text-amber-400">
                          {c.days_1_30_cents > 0 ? formatMoneyCents(c.days_1_30_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-orange-600 dark:text-orange-400">
                          {c.days_31_60_cents > 0 ? formatMoneyCents(c.days_31_60_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-red-600 dark:text-red-400">
                          {c.days_61_90_cents > 0 ? formatMoneyCents(c.days_61_90_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-destructive">
                          {c.days_over_90_cents > 0 ? formatMoneyCents(c.days_over_90_cents) : '—'}
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono tabular-nums font-bold text-foreground">
                          {formatMoneyCents(c.total_due_cents)}
                        </td>
                        <td className="px-3 py-2.5 text-center text-xs text-muted-foreground">
                          <span className="px-2 py-0.5 rounded-full bg-muted font-mono">{c.invoice_count}</span>
                        </td>
                      </tr>
                    ))
                  )
                ) : (
                  !apData || apData.suppliers.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                        No outstanding payables found as of this date.
                      </td>
                    </tr>
                  ) : (
                    apData.suppliers.map((s) => (
                      <tr
                        key={s.supplier_id}
                        onClick={() => setSelectedContactId(s.supplier_id === selectedContactId ? '' : s.supplier_id)}
                        className={`hover:bg-muted/30 transition-colors cursor-pointer ${
                          selectedContactId === s.supplier_id ? 'bg-primary/5 font-medium' : ''
                        }`}
                      >
                        <td className="px-4 py-2.5 font-mono text-xs text-foreground font-semibold">{s.supplier_code}</td>
                        <td className="px-4 py-2.5 text-foreground flex items-center gap-1.5">
                          {s.supplier_name}
                          <ChevronRight className={`h-3 w-3 text-muted-foreground transition-transform ${selectedContactId === s.supplier_id ? 'rotate-90' : ''}`} />
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-foreground">
                          {s.current_cents > 0 ? formatMoneyCents(s.current_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-amber-600 dark:text-amber-400">
                          {s.days_1_30_cents > 0 ? formatMoneyCents(s.days_1_30_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-orange-600 dark:text-orange-400">
                          {s.days_31_60_cents > 0 ? formatMoneyCents(s.days_31_60_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-red-600 dark:text-red-400">
                          {s.days_61_90_cents > 0 ? formatMoneyCents(s.days_61_90_cents) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono tabular-nums text-destructive">
                          {s.days_over_90_cents > 0 ? formatMoneyCents(s.days_over_90_cents) : '—'}
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono tabular-nums font-bold text-foreground">
                          {formatMoneyCents(s.total_due_cents)}
                        </td>
                        <td className="px-3 py-2.5 text-center text-xs text-muted-foreground">
                          <span className="px-2 py-0.5 rounded-full bg-muted font-mono">{s.bill_count}</span>
                        </td>
                      </tr>
                    ))
                  )
                )}
              </tbody>
              {activeData && (
                <tfoot className="bg-muted/60 font-bold border-t-2 border-border">
                  <tr>
                    <td colSpan={2} className="px-4 py-3 text-right uppercase text-xs tracking-wider">
                      Total {agingMode.toUpperCase()}
                    </td>
                    <td className="px-3 py-3 text-right font-mono tabular-nums text-foreground">
                      {formatMoneyCents(activeData.summary.current_cents)}
                    </td>
                    <td className="px-3 py-3 text-right font-mono tabular-nums text-amber-600 dark:text-amber-400">
                      {formatMoneyCents(activeData.summary.days_1_30_cents)}
                    </td>
                    <td className="px-3 py-3 text-right font-mono tabular-nums text-orange-600 dark:text-orange-400">
                      {formatMoneyCents(activeData.summary.days_31_60_cents)}
                    </td>
                    <td className="px-3 py-3 text-right font-mono tabular-nums text-red-600 dark:text-red-400">
                      {formatMoneyCents(activeData.summary.days_61_90_cents)}
                    </td>
                    <td className="px-3 py-3 text-right font-mono tabular-nums text-destructive">
                      {formatMoneyCents(activeData.summary.days_over_90_cents)}
                    </td>
                    <td className="px-4 py-3 text-right font-mono tabular-nums text-foreground font-bold">
                      {formatMoneyCents(activeData.summary.total_due_cents)}
                    </td>
                    <td></td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Drilldown Document Details if contact selected */}
      {selectedContactId && (
        <Card className="border-primary/30">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-semibold flex items-center justify-between">
              <span>
                Document Breakdown for{' '}
                {agingMode === 'ar'
                  ? arData?.customers.find((c) => c.customer_id === selectedContactId)?.customer_name
                  : apData?.suppliers.find((s) => s.supplier_id === selectedContactId)?.supplier_name}
              </span>
              <Button variant="ghost" size="sm" onClick={() => setSelectedContactId('')} className="h-7 text-xs">
                Close Details
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 border-b border-border text-muted-foreground uppercase">
                  <tr>
                    <th className="px-4 py-2 text-left">Doc #</th>
                    <th className="px-4 py-2 text-left">Issue Date</th>
                    <th className="px-4 py-2 text-left">Due Date</th>
                    <th className="px-4 py-2 text-right">Total</th>
                    <th className="px-4 py-2 text-right">Amount Paid</th>
                    <th className="px-4 py-2 text-right">Amount Due</th>
                    <th className="px-4 py-2 text-center">Overdue Days</th>
                    <th className="px-4 py-2 text-center">Bracket</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {agingMode === 'ar' && arData?.invoices ? (
                    arData.invoices.map((inv) => (
                      <tr key={inv.id} className="hover:bg-muted/20">
                        <td className="px-4 py-2 font-mono font-medium text-foreground">{inv.invoice_number}</td>
                        <td className="px-4 py-2 text-muted-foreground">{inv.issue_date}</td>
                        <td className="px-4 py-2 text-foreground">{inv.due_date}</td>
                        <td className="px-4 py-2 text-right font-mono tabular-nums">{formatMoneyCents(inv.total_cents)}</td>
                        <td className="px-4 py-2 text-right font-mono tabular-nums text-muted-foreground">
                          {formatMoneyCents(inv.amount_paid_cents)}
                        </td>
                        <td className="px-4 py-2 text-right font-mono tabular-nums font-bold text-foreground">
                          {formatMoneyCents(inv.amount_due_cents)}
                        </td>
                        <td className="px-4 py-2 text-center font-mono">{inv.days_overdue} d</td>
                        <td className="px-4 py-2 text-center">
                          <Badge variant="outline" className="text-[10px] uppercase font-mono">
                            {inv.bucket}
                          </Badge>
                        </td>
                      </tr>
                    ))
                  ) : agingMode === 'ap' && apData?.bills ? (
                    apData.bills.map((bill) => (
                      <tr key={bill.id} className="hover:bg-muted/20">
                        <td className="px-4 py-2 font-mono font-medium text-foreground">{bill.bill_number}</td>
                        <td className="px-4 py-2 text-muted-foreground">{bill.bill_date}</td>
                        <td className="px-4 py-2 text-foreground">{bill.due_date}</td>
                        <td className="px-4 py-2 text-right font-mono tabular-nums">{formatMoneyCents(bill.total_cents)}</td>
                        <td className="px-4 py-2 text-right font-mono tabular-nums text-muted-foreground">
                          {formatMoneyCents(bill.amount_paid_cents)}
                        </td>
                        <td className="px-4 py-2 text-right font-mono tabular-nums font-bold text-foreground">
                          {formatMoneyCents(bill.amount_due_cents)}
                        </td>
                        <td className="px-4 py-2 text-center font-mono">{bill.days_overdue} d</td>
                        <td className="px-4 py-2 text-center">
                          <Badge variant="outline" className="text-[10px] uppercase font-mono">
                            {bill.bucket}
                          </Badge>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={8} className="px-4 py-6 text-center text-muted-foreground">
                        No document items found.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
