import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { accountingApi } from '../api/accountingApi';
import type {
  FiscalPeriodItem,
  VatCalculationResult,
} from '../types/accounting.types';
import { VatRateBreakdownTable } from './VatRateBreakdownTable';
import { VatDocumentDrilldownTable } from './VatDocumentDrilldownTable';
import { FileVatReturnModal } from './FileVatReturnModal';
import { FiledReturnsModal } from './FiledReturnsModal';
import {
  Calculator,
  Download,
  FileCheck,
  History,
  AlertCircle,
  CheckCircle,
  Loader2,
  Layers,
  FileSpreadsheet,
} from 'lucide-react';

interface VatReturnTabProps {
  fiscalPeriods: FiscalPeriodItem[];
  currency?: string;
  onFeedback: (feedback: { type: 'success' | 'error'; message: string }) => void;
}

export function VatReturnTab({
  fiscalPeriods,
  currency = 'USD',
  onFeedback,
}: VatReturnTabProps) {
  const [periodMode, setPeriodMode] = useState<'fiscal_period' | 'custom_dates'>('fiscal_period');
  const [selectedPeriodId, setSelectedPeriodId] = useState<string>('');
  const [fromDate, setFromDate] = useState<string>(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0] ?? '';
  });
  const [toDate, setToDate] = useState<string>(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0] ?? '';
  });

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [calcResult, setCalcResult] = useState<VatCalculationResult | null>(null);

  const [fileModalOpen, setFileModalOpen] = useState(false);
  const [historyModalOpen, setHistoryModalOpen] = useState(false);
  const [activeSubTab, setActiveSubTab] = useState<'breakdown' | 'documents'>('breakdown');

  // Set default selected period if available
  useEffect(() => {
    if (fiscalPeriods.length > 0 && !selectedPeriodId) {
      setSelectedPeriodId(fiscalPeriods[0]?.id ?? '');
    }
  }, [fiscalPeriods, selectedPeriodId]);

  const handleCalculate = useCallback(async () => {
    setError(null);
    setLoading(true);

    try {
      const params =
        periodMode === 'fiscal_period'
          ? { fiscal_period_id: selectedPeriodId || undefined }
          : { from_date: fromDate, to_date: toDate };

      const res = await accountingApi.calculateVatReturn(params);
      setCalcResult(res.data);
      onFeedback({ type: 'success', message: 'VAT return calculated successfully.' });
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Failed to calculate VAT return.';
      setError(msg);
      onFeedback({ type: 'error', message: msg });
    } finally {
      setLoading(false);
    }
  }, [periodMode, selectedPeriodId, fromDate, toDate, onFeedback]);

  // Run calculation initially when periods are available
  useEffect(() => {
    if (selectedPeriodId && !calcResult && !loading) {
      handleCalculate();
    }
  }, [selectedPeriodId, calcResult, loading, handleCalculate]);

  const formatMoney = (cents: number) => {
    return (cents / 100).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  };

  const handleExportPdf = () => {
    if (!calcResult) return;
    const url = accountingApi.getPdfUrl(
      periodMode === 'fiscal_period'
        ? { fiscal_period_id: selectedPeriodId }
        : { from_date: fromDate, to_date: toDate }
    );
    window.open(url, '_blank');
  };

  const isNetPayable = (calcResult?.summary.net_vat_payable_cents ?? 0) >= 0;

  return (
    <div className="space-y-6">
      {/* Top Filter & Action Bar */}
      <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-wrap items-end gap-3">
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-muted-foreground uppercase">Selection Mode</Label>
            <div className="flex rounded-lg border border-border bg-muted/40 p-0.5">
              <button
                type="button"
                className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                  periodMode === 'fiscal_period'
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
                onClick={() => setPeriodMode('fiscal_period')}
              >
                Fiscal Period
              </button>
              <button
                type="button"
                className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                  periodMode === 'custom_dates'
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
                onClick={() => setPeriodMode('custom_dates')}
              >
                Custom Dates
              </button>
            </div>
          </div>

          {periodMode === 'fiscal_period' ? (
            <div className="space-y-1 min-w-[200px]">
              <Label htmlFor="fiscal-period-select" className="text-xs font-semibold text-muted-foreground uppercase">
                Fiscal Period
              </Label>
              <select
                id="fiscal-period-select"
                value={selectedPeriodId}
                onChange={(e) => setSelectedPeriodId(e.target.value)}
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
              >
                {fiscalPeriods.length === 0 ? (
                  <option value="">No fiscal periods defined</option>
                ) : (
                  fiscalPeriods.map((fp) => (
                    <option key={fp.id} value={fp.id}>
                      {fp.name} ({fp.start_date} to {fp.end_date}) [{fp.status.toUpperCase()}]
                    </option>
                  ))
                )}
              </select>
            </div>
          ) : (
            <div className="flex items-center gap-2">
              <div className="space-y-1">
                <Label htmlFor="from-date" className="text-xs font-semibold text-muted-foreground uppercase">From</Label>
                <Input
                  id="from-date"
                  type="date"
                  value={fromDate}
                  onChange={(e) => setFromDate(e.target.value)}
                  className="w-36 h-9"
                />
              </div>
              <div className="space-y-1">
                <Label htmlFor="to-date" className="text-xs font-semibold text-muted-foreground uppercase">To</Label>
                <Input
                  id="to-date"
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className="w-36 h-9"
                />
              </div>
            </div>
          )}

          <Button
            type="button"
            onClick={handleCalculate}
            disabled={loading || (periodMode === 'fiscal_period' && !selectedPeriodId)}
            className="gap-2"
          >
            {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Calculator className="h-4 w-4" />}
            Calculate Return
          </Button>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={handleExportPdf}
            disabled={!calcResult || loading}
            className="gap-1.5"
          >
            <Download className="h-4 w-4" /> Export PDF
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setFileModalOpen(true)}
            disabled={!calcResult || loading}
            className="gap-1.5 border-primary/30 text-primary hover:bg-primary/10"
          >
            <FileCheck className="h-4 w-4" /> File Return
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setHistoryModalOpen(true)}
            className="gap-1.5 text-muted-foreground hover:text-foreground"
          >
            <History className="h-4 w-4" /> History
          </Button>
        </div>
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {/* KPI Summary Cards */}
      {calcResult && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {/* Output VAT */}
          <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
              <span>Total Output VAT</span>
              <Badge variant="outline" className="text-xs">Sales</Badge>
            </div>
            <div className="mt-2 text-2xl font-bold tabular-nums text-foreground">
              {currency} {formatMoney(calcResult.summary.total_output_vat_cents)}
            </div>
            <div className="mt-1 text-xs text-muted-foreground">
              Taxable Base: {currency} {formatMoney(calcResult.summary.total_sales_taxable_cents)}
            </div>
          </div>

          {/* Input VAT */}
          <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
              <span>Total Input VAT</span>
              <Badge variant="outline" className="text-xs">Purchases</Badge>
            </div>
            <div className="mt-2 text-2xl font-bold tabular-nums text-foreground">
              {currency} {formatMoney(calcResult.summary.total_input_vat_cents)}
            </div>
            <div className="mt-1 text-xs text-muted-foreground">
              Taxable Base: {currency} {formatMoney(calcResult.summary.total_purchases_taxable_cents)}
            </div>
          </div>

          {/* Net VAT Position */}
          <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
              <span>Net VAT Position</span>
              <Badge
                variant="outline"
                className={
                  isNetPayable
                    ? 'border-amber-500/30 bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
                    : 'border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                }
              >
                {isNetPayable ? 'Payable' : 'Refund'}
              </Badge>
            </div>
            <div
              className={`mt-2 text-2xl font-bold tabular-nums ${
                isNetPayable ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'
              }`}
            >
              {currency} {formatMoney(Math.abs(calcResult.summary.net_vat_payable_cents))}
            </div>
            <div className="mt-1 text-xs text-muted-foreground">
              {isNetPayable ? 'Due to statutory tax authority' : 'Claimable tax credit / carry forward'}
            </div>
          </div>

          {/* General Ledger Reconciliation */}
          <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center justify-between text-xs font-semibold uppercase text-muted-foreground">
              <span>GL Reconciliation</span>
              {calcResult.ledger_reconciliation.is_reconciled ? (
                <CheckCircle className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
              ) : (
                <AlertCircle className="h-4 w-4 text-rose-600 dark:text-rose-400" />
              )}
            </div>
            <div className="mt-2 flex items-center gap-2">
              <span
                className={`text-lg font-bold ${
                  calcResult.ledger_reconciliation.is_reconciled
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400'
                }`}
              >
                {calcResult.ledger_reconciliation.is_reconciled ? '100% Reconciled' : 'Discrepancy'}
              </span>
            </div>
            <div className="mt-1 text-xs text-muted-foreground">
              GL Net: {currency} {formatMoney(calcResult.ledger_reconciliation.gl_net_vat_cents)}
              {!calcResult.ledger_reconciliation.is_reconciled && (
                <span className="text-rose-500 ml-1">
                  (Diff: {formatMoney(calcResult.ledger_reconciliation.net_vat_discrepancy_cents)})
                </span>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Breakdown vs Document Tabs */}
      {calcResult && (
        <div className="space-y-4">
          <div className="flex items-center justify-between border-b border-border pb-2">
            <div className="flex items-center gap-2">
              <button
                type="button"
                className={`flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border-b-2 -mb-2 transition-colors ${
                  activeSubTab === 'breakdown'
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
                }`}
                onClick={() => setActiveSubTab('breakdown')}
              >
                <Layers className="h-4 w-4" /> Rate Breakdown ({calcResult.rate_breakdown.length})
              </button>
              <button
                type="button"
                className={`flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border-b-2 -mb-2 transition-colors ${
                  activeSubTab === 'documents'
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
                }`}
                onClick={() => setActiveSubTab('documents')}
              >
                <FileSpreadsheet className="h-4 w-4" /> Participating Documents ({calcResult.documents.length})
              </button>
            </div>

            <div className="text-xs text-muted-foreground">
              Period: <span className="font-medium text-foreground">{calcResult.from_date}</span> to{' '}
              <span className="font-medium text-foreground">{calcResult.to_date}</span>
            </div>
          </div>

          {activeSubTab === 'breakdown' ? (
            <VatRateBreakdownTable rates={calcResult.rate_breakdown} currency={currency} />
          ) : (
            <VatDocumentDrilldownTable documents={calcResult.documents} currency={currency} />
          )}
        </div>
      )}

      {/* Modals */}
      <FileVatReturnModal
        open={fileModalOpen}
        onOpenChange={setFileModalOpen}
        calcResult={calcResult}
        currency={currency}
        onSuccess={() => {
          onFeedback({ type: 'success', message: 'VAT return officially filed and preserved.' });
        }}
      />

      <FiledReturnsModal
        open={historyModalOpen}
        onOpenChange={setHistoryModalOpen}
        currency={currency}
      />
    </div>
  );
}
