import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { accountingApi } from '../api/accountingApi';
import type {
  AccountItem,
  JournalEntryItem,
  JournalLinePayload,
} from '../types/accounting.types';
import {
  Plus,
  Trash2,
  AlertCircle,
  CheckCircle2,
  BookOpen,
} from 'lucide-react';

interface ManualJournalModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  entry: JournalEntryItem | null;
  mode: 'create' | 'edit' | 'view';
  accounts: AccountItem[];
  onSuccess: (message: string) => void;
}

interface FormLine {
  account_id: string;
  debit: string;
  credit: string;
  description: string;
}

function formatCents(cents: number): string {
  return (cents / 100).toFixed(2);
}

export function ManualJournalModal({
  open,
  onOpenChange,
  entry,
  mode,
  accounts,
  onSuccess,
}: ManualJournalModalProps) {
  const [entryDate, setEntryDate] = useState<string>(() => new Date().toISOString().split('T')[0] ?? '');
  const [description, setDescription] = useState<string>('');
  const [lines, setLines] = useState<FormLine[]>([
    { account_id: '', debit: '', credit: '', description: '' },
    { account_id: '', debit: '', credit: '', description: '' },
  ]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
      if (entry && (mode === 'edit' || mode === 'view')) {
        setEntryDate(entry.entry_date.split('T')[0] ?? '');
        setDescription(entry.description || '');
        if (entry.lines && entry.lines.length > 0) {
          setLines(
            entry.lines.map((l) => ({
              account_id: l.account_id,
              debit: l.debit_cents > 0 ? formatCents(l.debit_cents) : '',
              credit: l.credit_cents > 0 ? formatCents(l.credit_cents) : '',
              description: l.description || '',
            }))
          );
        }
      } else {
        setEntryDate(new Date().toISOString().split('T')[0] ?? '');
        setDescription('');
        setLines([
          { account_id: '', debit: '', credit: '', description: '' },
          { account_id: '', debit: '', credit: '', description: '' },
        ]);
      }
    }
  }, [open, entry, mode]);

  // Live total calculations in integer cents
  const totalDebitCents = lines.reduce((acc, l) => {
    const val = parseFloat(l.debit);
    return acc + (isNaN(val) || val <= 0 ? 0 : Math.round(val * 100));
  }, 0);

  const totalCreditCents = lines.reduce((acc, l) => {
    const val = parseFloat(l.credit);
    return acc + (isNaN(val) || val <= 0 ? 0 : Math.round(val * 100));
  }, 0);

  const differenceCents = Math.abs(totalDebitCents - totalCreditCents);
  const isBalanced = totalDebitCents > 0 && totalDebitCents === totalCreditCents;

  const handleLineChange = (index: number, field: keyof FormLine, value: string) => {
    setLines((prev) => {
      const next = [...prev];
      const current = next[index];
      if (!current) return prev;
      const updated: FormLine = { ...current, [field]: value };
      if (field === 'debit' && value !== '') {
        updated.credit = '';
      } else if (field === 'credit' && value !== '') {
        updated.debit = '';
      }
      next[index] = updated;
      return next;
    });
  };

  const addLine = () => {
    setLines((prev) => [...prev, { account_id: '', debit: '', credit: '', description: '' }]);
  };

  const removeLine = (index: number) => {
    if (lines.length <= 2) return;
    setLines((prev) => prev.filter((_, i) => i !== index));
  };

  const buildPayloadLines = (): JournalLinePayload[] => {
    return lines
      .filter((l) => l.account_id !== '')
      .map((l) => {
        const dVal = parseFloat(l.debit);
        const cVal = parseFloat(l.credit);
        return {
          account_id: l.account_id,
          debit_cents: !isNaN(dVal) && dVal > 0 ? Math.round(dVal * 100) : 0,
          credit_cents: !isNaN(cVal) && cVal > 0 ? Math.round(cVal * 100) : 0,
          description: l.description || null,
        };
      });
  };

  const handleSave = async (targetStatus?: 'draft' | 'posted') => {
    setError(null);
    const payloadLines = buildPayloadLines();

    if (payloadLines.length < 2) {
      setError('A journal entry must contain at least two account lines.');
      return;
    }

    if (targetStatus === 'posted' && !isBalanced) {
      setError(`Cannot post an imbalanced journal entry. Difference: $${formatCents(differenceCents)}`);
      return;
    }

    setSubmitting(true);
    try {
      if (mode === 'create') {
        const res = await accountingApi.createJournalEntry({
          entry_date: entryDate,
          status: targetStatus || 'draft',
          description: description || null,
          currency: 'USD',
          lines: payloadLines,
        });
        const msg = targetStatus === 'posted'
          ? `Journal entry ${res.data.entry_number || ''} posted successfully.`
          : 'Draft journal entry saved successfully.';
        onSuccess(msg);
      } else if (mode === 'edit' && entry) {
        await accountingApi.updateJournalEntry(entry.id, {
          entry_date: entryDate,
          description: description || null,
          currency: 'USD',
          lines: payloadLines,
        });
        onSuccess('Journal entry updated successfully.');
      }
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred while saving the journal entry.');
    } finally {
      setSubmitting(false);
    }
  };

  const isView = mode === 'view';

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <div className="flex items-center justify-between pr-4">
          <DialogTitle className="flex items-center gap-2 text-lg">
            <BookOpen className="h-5 w-5 text-primary" />
            {isView
              ? `Journal Entry: ${entry?.entry_number || 'Draft'}`
              : mode === 'edit'
              ? 'Edit Journal Entry'
              : 'New Manual Journal Entry'}
          </DialogTitle>
          {entry && (
            <Badge variant="outline" className="capitalize">
              {entry.status_label || entry.status}
            </Badge>
          )}
        </div>
        <DialogDescription>
          {isView
            ? 'View General Ledger journal transaction details and double-entry lines.'
            : 'Record manual financial adjustments, depreciation, or accruals directly to the General Ledger.'}
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-4 py-2">
        {error && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {entry?.rejection_reason && (
          <Alert variant="destructive" className="bg-destructive/10 border-destructive/20 text-destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>
              <strong>Rejection Reason:</strong> {entry.rejection_reason}
            </AlertDescription>
          </Alert>
        )}

        {/* Header Row */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="entryDate">Entry Date</Label>
            <Input
              id="entryDate"
              type="date"
              value={entryDate}
              onChange={(e) => setEntryDate(e.target.value)}
              disabled={isView || submitting}
            />
          </div>

          <div className="md:col-span-2 space-y-1.5">
            <Label htmlFor="description">Narration / Description</Label>
            <Input
              id="description"
              placeholder="e.g. Monthly asset depreciation"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              disabled={isView || submitting}
            />
          </div>
        </div>

        {/* Lines Table */}
        <div className="border border-border rounded-md overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-muted text-muted-foreground border-b border-border">
              <tr>
                <th className="px-3 py-2 text-left font-medium w-64">Account</th>
                <th className="px-3 py-2 text-left font-medium">Description</th>
                <th className="px-3 py-2 text-right font-medium w-32">Debit ($)</th>
                <th className="px-3 py-2 text-right font-medium w-32">Credit ($)</th>
                {!isView && <th className="px-2 py-2 text-center w-10"></th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {lines.map((line, idx) => (
                <tr key={idx} className="hover:bg-muted/40 transition-colors">
                  <td className="p-2">
                    {isView ? (
                      <div className="font-medium text-foreground">
                        {accounts.find((a) => a.id === line.account_id)?.name || line.account_id}
                      </div>
                    ) : (
                      <select
                        value={line.account_id}
                        onChange={(e) => handleLineChange(idx, 'account_id', e.target.value)}
                        className="w-full rounded-md border border-input bg-card px-2 py-1.5 text-xs shadow-xs focus:ring-1 focus:ring-ring"
                        disabled={submitting}
                      >
                        <option value="">Select Account...</option>
                        {accounts.map((acc) => (
                          <option key={acc.id} value={acc.id}>
                            {acc.code} - {acc.name} ({acc.type})
                          </option>
                        ))}
                      </select>
                    )}
                  </td>

                  <td className="p-2">
                    {isView ? (
                      <span className="text-muted-foreground text-xs">{line.description || '—'}</span>
                    ) : (
                      <Input
                        value={line.description}
                        onChange={(e) => handleLineChange(idx, 'description', e.target.value)}
                        placeholder="Line memo"
                        className="h-8 text-xs"
                        disabled={submitting}
                      />
                    )}
                  </td>

                  <td className="p-2 text-right">
                    {isView ? (
                      <span className="font-mono text-xs tabular-nums font-semibold">
                        {line.debit ? `$${line.debit}` : '—'}
                      </span>
                    ) : (
                      <Input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.debit}
                        onChange={(e) => handleLineChange(idx, 'debit', e.target.value)}
                        placeholder="0.00"
                        className="h-8 text-right font-mono text-xs tabular-nums"
                        disabled={submitting}
                      />
                    )}
                  </td>

                  <td className="p-2 text-right">
                    {isView ? (
                      <span className="font-mono text-xs tabular-nums font-semibold">
                        {line.credit ? `$${line.credit}` : '—'}
                      </span>
                    ) : (
                      <Input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.credit}
                        onChange={(e) => handleLineChange(idx, 'credit', e.target.value)}
                        placeholder="0.00"
                        className="h-8 text-right font-mono text-xs tabular-nums"
                        disabled={submitting}
                      />
                    )}
                  </td>

                  {!isView && (
                    <td className="p-2 text-center">
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7 text-muted-foreground hover:text-destructive"
                        onClick={() => removeLine(idx)}
                        disabled={lines.length <= 2 || submitting}
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {!isView && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={addLine}
            disabled={submitting}
            className="text-xs"
          >
            <Plus className="h-3.5 w-3.5 mr-1" />
            Add Line
          </Button>
        )}

        {/* Balance Summary Box */}
        <div className="bg-muted/50 border border-border rounded-lg p-3 flex flex-col sm:flex-row items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            {isBalanced ? (
              <div className="flex items-center gap-1.5 text-emerald-600 font-semibold text-xs">
                <CheckCircle2 className="h-4 w-4" />
                Balanced Entry
              </div>
            ) : (
              <div className="flex items-center gap-1.5 text-amber-600 font-semibold text-xs">
                <AlertCircle className="h-4 w-4" />
                Out of balance by ${formatCents(differenceCents)}
              </div>
            )}
          </div>

          <div className="flex items-center gap-6 text-xs tabular-nums font-mono">
            <div>
              <span className="text-muted-foreground mr-1.5">Total Debits:</span>
              <span className="font-bold text-foreground">${formatCents(totalDebitCents)}</span>
            </div>
            <div>
              <span className="text-muted-foreground mr-1.5">Total Credits:</span>
              <span className="font-bold text-foreground">${formatCents(totalCreditCents)}</span>
            </div>
          </div>
        </div>
      </div>

      <DialogFooter className="pt-3 gap-2">
        {isView ? (
          <Button type="button" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        ) : (
          <>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => handleSave('draft')}
              disabled={submitting}
            >
              Save as Draft
            </Button>
            <Button
              type="button"
              onClick={() => handleSave('posted')}
              disabled={submitting || !isBalanced}
            >
              {submitting ? 'Posting...' : 'Post to Ledger'}
            </Button>
          </>
        )}
      </DialogFooter>
    </Dialog>
  );
}
