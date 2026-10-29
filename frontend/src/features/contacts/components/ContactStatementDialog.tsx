import * as React from 'react';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  FileText,
  Printer,
  Calendar,
  AlertTriangle,
  Loader2,
  RefreshCw,
} from 'lucide-react';
import { contactsApi } from '../api/contactsApi';
import type { ContactItem, ContactStatement } from '../types/contacts.types';

export interface ContactStatementDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  contact: ContactItem | null;
}

function formatYmd(d: Date): string {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatCents(cents: number, currency: string = 'USD'): string {
  return `${currency} ${(cents / 100).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export function ContactStatementDialog({
  open,
  onOpenChange,
  contact,
}: ContactStatementDialogProps) {
  const [statementType, setStatementType] = React.useState<'customer' | 'supplier'>('customer');
  const [startDate, setStartDate] = React.useState<string>('');
  const [endDate, setEndDate] = React.useState<string>('');
  const [loading, setLoading] = React.useState<boolean>(false);
  const [error, setError] = React.useState<string | null>(null);
  const [statement, setStatement] = React.useState<ContactStatement | null>(null);

  // Initialize dates and default statement type when contact opens
  React.useEffect(() => {
    if (contact && open) {
      const today = new Date();
      const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
      
      setStartDate(formatYmd(firstDay));
      setEndDate(formatYmd(today));

      if (contact.type === 'supplier') {
        setStatementType('supplier');
      } else {
        setStatementType('customer');
      }
    }
  }, [contact, open]);

  const fetchStatement = React.useCallback(async () => {
    if (!contact) return;
    setLoading(true);
    setError(null);

    try {
      const res = await contactsApi.getStatement(contact.id, {
        start_date: startDate || undefined,
        end_date: endDate || undefined,
        statement_type: statementType,
      });
      setStatement(res.data);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch statement';
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, [contact, startDate, endDate, statementType]);

  React.useEffect(() => {
    if (open && contact && startDate && endDate) {
      fetchStatement();
    }
  }, [open, contact, startDate, endDate, statementType, fetchStatement]);

  const handlePreset = (type: 'this_month' | 'last_month' | 'last_30' | 'ytd') => {
    const today = new Date();

    if (type === 'this_month') {
      const first = new Date(today.getFullYear(), today.getMonth(), 1);
      setStartDate(formatYmd(first));
      setEndDate(formatYmd(today));
    } else if (type === 'last_month') {
      const first = new Date(today.getFullYear(), today.getMonth() - 1, 1);
      const last = new Date(today.getFullYear(), today.getMonth(), 0);
      setStartDate(formatYmd(first));
      setEndDate(formatYmd(last));
    } else if (type === 'last_30') {
      const past = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
      setStartDate(formatYmd(past));
      setEndDate(formatYmd(today));
    } else if (type === 'ytd') {
      const first = new Date(today.getFullYear(), 0, 1);
      setStartDate(formatYmd(first));
      setEndDate(formatYmd(today));
    }
  };

  const handlePrintPdf = () => {
    if (!contact) return;
    const url = contactsApi.getStatementPdfUrl(contact.id, {
      start_date: startDate,
      end_date: endDate,
      statement_type: statementType,
    });
    window.open(url, '_blank');
  };

  if (!contact) return null;

  const currency = statement?.currency || 'USD';

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-4xl max-h-[90vh] flex flex-col p-6">
      <DialogHeader className="border-b border-border pb-4">
        <div className="flex flex-wrap items-center justify-between gap-2 pr-6">
          <div className="flex items-center gap-2">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <FileText className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle className="text-xl font-bold flex items-center gap-2">
                <span>{contact.name}</span>
                <span className="font-mono text-xs px-2 py-0.5 rounded bg-muted text-muted-foreground font-semibold">
                  {contact.code}
                </span>
                <Badge variant={contact.type === 'both' ? 'secondary' : 'default'} className="text-xs">
                  {contact.type_label || contact.type}
                </Badge>
              </DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground flex items-center gap-2 mt-0.5">
                <span>Statement of Account & AR/AP Aging Analysis</span>
                {contact.tax_id && (
                  <>
                    <span>&bull;</span>
                    <span>VAT/Tax ID: {contact.tax_id}</span>
                  </>
                )}
              </DialogDescription>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {contact.type === 'both' && (
              <div className="flex items-center rounded-md border border-border p-0.5 bg-muted/40">
                <button
                  type="button"
                  onClick={() => setStatementType('customer')}
                  className={`px-3 py-1 text-xs font-semibold rounded ${
                    statementType === 'customer'
                      ? 'bg-background shadow-xs text-foreground'
                      : 'text-muted-foreground hover:text-foreground'
                  }`}
                >
                  Customer (AR)
                </button>
                <button
                  type="button"
                  onClick={() => setStatementType('supplier')}
                  className={`px-3 py-1 text-xs font-semibold rounded ${
                    statementType === 'supplier'
                      ? 'bg-background shadow-xs text-foreground'
                      : 'text-muted-foreground hover:text-foreground'
                  }`}
                >
                  Supplier (AP)
                </button>
              </div>
            )}

            <Button
              variant="outline"
              size="sm"
              onClick={handlePrintPdf}
              className="h-8 gap-1.5"
            >
              <Printer className="h-3.5 w-3.5" />
              <span>Print / PDF</span>
            </Button>
          </div>
        </div>
      </DialogHeader>

      <div className="flex-1 overflow-y-auto py-4 space-y-5">
        {/* Date Range Filters and Presets */}
        <div className="rounded-lg border border-border bg-muted/20 p-3 space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-3">
              <div className="flex items-center gap-2">
                <Calendar className="h-4 w-4 text-muted-foreground" />
                <Label htmlFor="stmt-start" className="text-xs font-medium text-muted-foreground">From:</Label>
                <Input
                  id="stmt-start"
                  type="date"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="h-8 w-36 text-xs bg-background"
                />
              </div>

              <div className="flex items-center gap-2">
                <Label htmlFor="stmt-end" className="text-xs font-medium text-muted-foreground">To:</Label>
                <Input
                  id="stmt-end"
                  type="date"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                  className="h-8 w-36 text-xs bg-background"
                />
              </div>

              <Button
                variant="ghost"
                size="sm"
                onClick={fetchStatement}
                disabled={loading}
                className="h-8 px-2"
                title="Refresh statement"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
              </Button>
            </div>

            <div className="flex flex-wrap items-center gap-1.5 text-xs">
              <span className="text-muted-foreground mr-1">Presets:</span>
              <button
                type="button"
                onClick={() => handlePreset('this_month')}
                className="px-2 py-0.5 rounded border border-border bg-background hover:bg-muted font-medium transition"
              >
                This Month
              </button>
              <button
                type="button"
                onClick={() => handlePreset('last_month')}
                className="px-2 py-0.5 rounded border border-border bg-background hover:bg-muted font-medium transition"
              >
                Last Month
              </button>
              <button
                type="button"
                onClick={() => handlePreset('last_30')}
                className="px-2 py-0.5 rounded border border-border bg-background hover:bg-muted font-medium transition"
              >
                Last 30 Days
              </button>
              <button
                type="button"
                onClick={() => handlePreset('ytd')}
                className="px-2 py-0.5 rounded border border-border bg-background hover:bg-muted font-medium transition"
              >
                YTD
              </button>
            </div>
          </div>
        </div>

        {error && (
          <Alert variant="destructive">
            <AlertTriangle className="h-4 w-4" />
            <AlertDescription className="text-xs">{error}</AlertDescription>
          </Alert>
        )}

        {loading && !statement && (
          <div className="flex flex-col items-center justify-center py-12 text-muted-foreground space-y-2">
            <Loader2 className="h-6 w-6 animate-spin text-primary" />
            <span className="text-xs">Calculating aging buckets and compiling statement...</span>
          </div>
        )}

        {statement && (
          <>
            {/* Aging Summary Metric Cards */}
            <div>
              <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2 flex items-center justify-between">
                <span>Aging Analysis (as of {statement.end_date})</span>
                <span className="text-[11px] font-normal normal-case text-muted-foreground">
                  Unallocated Credit: <strong className="font-mono">{formatCents(statement.summary.unallocated_amount_cents, currency)}</strong>
                </span>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                <div className="rounded-lg border border-border bg-card p-2.5">
                  <div className="text-[11px] font-medium text-emerald-600 dark:text-emerald-400">Current (Not Due)</div>
                  <div className="text-sm font-bold font-mono text-foreground mt-0.5">
                    {formatCents(statement.aging.current_cents, currency)}
                  </div>
                </div>

                <div className="rounded-lg border border-border bg-card p-2.5">
                  <div className="text-[11px] font-medium text-sky-600 dark:text-sky-400">1–30 Days Overdue</div>
                  <div className="text-sm font-bold font-mono text-foreground mt-0.5">
                    {formatCents(statement.aging.days_1_30_cents, currency)}
                  </div>
                </div>

                <div className="rounded-lg border border-border bg-card p-2.5">
                  <div className="text-[11px] font-medium text-amber-600 dark:text-amber-400">31–60 Days Overdue</div>
                  <div className="text-sm font-bold font-mono text-foreground mt-0.5">
                    {formatCents(statement.aging.days_31_60_cents, currency)}
                  </div>
                </div>

                <div className="rounded-lg border border-border bg-card p-2.5">
                  <div className="text-[11px] font-medium text-orange-600 dark:text-orange-400">61–90 Days Overdue</div>
                  <div className="text-sm font-bold font-mono text-foreground mt-0.5">
                    {formatCents(statement.aging.days_61_90_cents, currency)}
                  </div>
                </div>

                <div className="rounded-lg border border-border bg-card p-2.5">
                  <div className="text-[11px] font-medium text-rose-600 dark:text-rose-400">90+ Days Overdue</div>
                  <div className="text-sm font-bold font-mono text-foreground mt-0.5">
                    {formatCents(statement.aging.days_over_90_cents, currency)}
                  </div>
                </div>

                <div className="rounded-lg border border-border bg-primary/5 p-2.5">
                  <div className="text-[11px] font-semibold text-primary">Total Overdue / Due</div>
                  <div className="text-sm font-bold font-mono text-foreground mt-0.5">
                    {formatCents(statement.aging.total_due_cents, currency)}
                  </div>
                </div>
              </div>
            </div>

            {/* Statement Summary KPI strip */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
              <div className="rounded-md border border-border bg-muted/30 p-2.5">
                <span className="text-[11px] text-muted-foreground block">Opening Balance</span>
                <span className="text-xs font-bold font-mono text-foreground">
                  {formatCents(statement.summary.opening_balance_cents, currency)}
                </span>
              </div>

              <div className="rounded-md border border-border bg-muted/30 p-2.5">
                <span className="text-[11px] text-muted-foreground block">
                  {statement.statement_type === 'customer' ? 'Total Invoiced' : 'Total Billed'}
                </span>
                <span className="text-xs font-bold font-mono text-foreground">
                  {formatCents(statement.summary.total_debits_cents, currency)}
                </span>
              </div>

              <div className="rounded-md border border-border bg-muted/30 p-2.5">
                <span className="text-[11px] text-muted-foreground block">Total Payments</span>
                <span className="text-xs font-bold font-mono text-foreground">
                  {formatCents(statement.summary.total_credits_cents, currency)}
                </span>
              </div>

              <div className="rounded-md border border-primary/20 bg-primary/10 p-2.5">
                <span className="text-[11px] text-primary font-semibold block">Net Closing Balance Due</span>
                <span className="text-sm font-bold font-mono text-foreground">
                  {formatCents(statement.summary.closing_balance_cents, currency)}
                </span>
              </div>
            </div>

            {/* Activity Ledger Table */}
            <div className="space-y-1.5">
              <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                Account Activity Ledger ({statement.start_date} to {statement.end_date})
              </div>
              <div className="rounded-md border border-border overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                      <TableHead className="w-24 text-xs">Date</TableHead>
                      <TableHead className="w-36 text-xs">Doc #</TableHead>
                      <TableHead className="text-xs">Description</TableHead>
                      <TableHead className="w-28 text-right text-xs">
                        {statement.statement_type === 'customer' ? 'Debit (Charges)' : 'Debit (Payments)'}
                      </TableHead>
                      <TableHead className="w-28 text-right text-xs">
                        {statement.statement_type === 'customer' ? 'Credit (Payments)' : 'Credit (Bills)'}
                      </TableHead>
                      <TableHead className="w-28 text-right text-xs font-semibold">Balance</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {/* Opening Balance Row */}
                    <TableRow className="bg-muted/20 font-medium text-xs">
                      <TableCell className="text-muted-foreground">{statement.start_date}</TableCell>
                      <TableCell>
                        <span className="font-mono text-[11px] text-muted-foreground px-1.5 py-0.5 rounded bg-muted">
                          OPENING
                        </span>
                      </TableCell>
                      <TableCell className="text-muted-foreground">Opening Balance Forward</TableCell>
                      <TableCell className="text-right text-muted-foreground">-</TableCell>
                      <TableCell className="text-right text-muted-foreground">-</TableCell>
                      <TableCell className="text-right font-mono font-semibold">
                        {formatCents(statement.summary.opening_balance_cents, currency)}
                      </TableCell>
                    </TableRow>

                    {statement.transactions.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={6} className="text-center py-6 text-xs text-muted-foreground">
                          No transactions found for this date range.
                        </TableCell>
                      </TableRow>
                    ) : (
                      statement.transactions.map((tx) => (
                        <TableRow key={tx.id} className="text-xs">
                          <TableCell className="text-muted-foreground">{tx.date}</TableCell>
                          <TableCell>
                            <span className="font-mono font-semibold text-primary">{tx.document_number}</span>
                          </TableCell>
                          <TableCell>
                            <span>{tx.description}</span>
                            {tx.due_date && (
                              <span className="text-[11px] text-muted-foreground ml-2">
                                (Due: {tx.due_date})
                              </span>
                            )}
                          </TableCell>
                          <TableCell className="text-right font-mono">
                            {tx.debit_cents > 0 ? formatCents(tx.debit_cents, currency) : '-'}
                          </TableCell>
                          <TableCell className="text-right font-mono">
                            {tx.credit_cents > 0 ? formatCents(tx.credit_cents, currency) : '-'}
                          </TableCell>
                          <TableCell className="text-right font-mono font-semibold">
                            {formatCents(tx.running_balance_cents, currency)}
                          </TableCell>
                        </TableRow>
                      ))
                    )}

                    {/* Closing Balance Row */}
                    <TableRow className="bg-muted/40 font-bold text-xs border-t-2 border-border">
                      <TableCell colSpan={3} className="text-right text-foreground">
                        Totals & Closing Balance:
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCents(statement.summary.total_debits_cents, currency)}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCents(statement.summary.total_credits_cents, currency)}
                      </TableCell>
                      <TableCell className="text-right font-mono text-primary text-sm">
                        {formatCents(statement.summary.closing_balance_cents, currency)}
                      </TableCell>
                    </TableRow>
                  </TableBody>
                </Table>
              </div>
            </div>
          </>
        )}
      </div>

      <DialogFooter className="border-t border-border pt-4">
        <Button variant="outline" onClick={() => onOpenChange(false)}>
          Close
        </Button>
      </DialogFooter>
    </Dialog>
  );
}
