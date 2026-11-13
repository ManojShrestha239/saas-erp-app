import { useState, useEffect, useCallback } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { JournalEntriesTable } from '../components/JournalEntriesTable';
import { FiscalPeriodsTable } from '../components/FiscalPeriodsTable';
import { AccountsTable } from '../components/AccountsTable';
import { ManualJournalModal } from '../components/ManualJournalModal';
import { VatReturnTab } from '../components/VatReturnTab';
import { accountingApi } from '../api/accountingApi';
import type {
  AccountItem,
  FiscalPeriodItem,
  JournalEntryItem,
} from '../types/accounting.types';
import {
  BookOpen,
  Calendar,
  ListTree,
  Calculator,
  CheckCircle2,
  AlertCircle,
} from 'lucide-react';

type TabKey = 'journals' | 'periods' | 'accounts' | 'vat_return';

export default function AccountingIndex() {
  const [activeTab, setActiveTab] = useState<TabKey>('journals');

  // Shared Data States
  const [entries, setEntries] = useState<JournalEntryItem[]>([]);
  const [periods, setPeriods] = useState<FiscalPeriodItem[]>([]);
  const [accounts, setAccounts] = useState<AccountItem[]>([]);

  // Loading States
  const [loadingEntries, setLoadingEntries] = useState(false);
  const [loadingPeriods, setLoadingPeriods] = useState(false);
  const [loadingAccounts, setLoadingAccounts] = useState(false);

  // Filters & Sub-states
  const [selectedYear, setSelectedYear] = useState<number>(new Date().getFullYear());

  // Modal State
  const [modalState, setModalState] = useState<{
    open: boolean;
    mode: 'create' | 'edit' | 'view';
    entry: JournalEntryItem | null;
  }>({
    open: false,
    mode: 'create',
    entry: null,
  });

  // Global Feedback
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Fetch Accounts
  const loadAccounts = useCallback(async () => {
    setLoadingAccounts(true);
    try {
      const res = await accountingApi.getAccounts({ all: true });
      setAccounts(res.data);
    } catch {
      // Best effort load
    } finally {
      setLoadingAccounts(false);
    }
  }, []);

  // Fetch Journal Entries
  const loadEntries = useCallback(async () => {
    setLoadingEntries(true);
    try {
      const res = await accountingApi.getJournalEntries({ per_page: 100 });
      const rawData = res.data as unknown;
      const items: JournalEntryItem[] = Array.isArray(rawData)
        ? (rawData as JournalEntryItem[])
        : Array.isArray((rawData as { data?: JournalEntryItem[] })?.data)
        ? ((rawData as { data: JournalEntryItem[] }).data)
        : [];
      setEntries(items);
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to load journal entries.',
      });
    } finally {
      setLoadingEntries(false);
    }
  }, []);

  // Fetch Fiscal Periods
  const loadPeriods = useCallback(async () => {
    setLoadingPeriods(true);
    try {
      const res = await accountingApi.getFiscalPeriods({ fiscal_year: selectedYear, all: true });
      setPeriods(res.data);
    } catch (err) {
      setFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to load fiscal periods.',
      });
    } finally {
      setLoadingPeriods(false);
    }
  }, [selectedYear]);

  // Initial Load
  useEffect(() => {
    loadAccounts();
    loadEntries();
  }, [loadAccounts, loadEntries]);

  useEffect(() => {
    if (activeTab === 'periods' || activeTab === 'vat_return') {
      loadPeriods();
    }
  }, [activeTab, loadPeriods]);

  const handleOpenCreateModal = () => {
    setModalState({ open: true, mode: 'create', entry: null });
  };

  const handleOpenViewModal = (entry: JournalEntryItem) => {
    setModalState({ open: true, mode: 'view', entry });
  };

  const handleOpenEditModal = (entry: JournalEntryItem) => {
    setModalState({ open: true, mode: 'edit', entry });
  };

  return (
    <AppLayout
      title="Accounting & General Ledger"
      activeNav="accounting"
      breadcrumbs={[
        { label: 'Dashboard', href: '/dashboard' },
        { label: 'Accounting' },
      ]}
    >
      <div className="flex-1 space-y-6 p-6">
        {/* Header */}
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">
            Accounting & Financial Ledger
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            General Ledger double-entry postings, fiscal period lock/close governance, and Chart of Accounts.
          </p>
        </div>

        {/* Feedback Alert */}
        {feedback && (
          <Alert
            variant={feedback.type === 'error' ? 'destructive' : 'default'}
            className={feedback.type === 'success' ? 'border-emerald-500/30 text-emerald-600 bg-emerald-500/10' : ''}
          >
            {feedback.type === 'success' ? (
              <CheckCircle2 className="h-4 w-4" />
            ) : (
              <AlertCircle className="h-4 w-4" />
            )}
            <AlertDescription className="flex items-center justify-between">
              <span>{feedback.message}</span>
              <button
                type="button"
                onClick={() => setFeedback(null)}
                className="text-xs underline ml-4 hover:opacity-80"
              >
                Dismiss
              </button>
            </AlertDescription>
          </Alert>
        )}

        {/* Tab Navigation */}
        <div className="flex border-b border-border">
          <button
            type="button"
            onClick={() => setActiveTab('journals')}
            className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              activeTab === 'journals'
                ? 'border-primary text-primary font-semibold'
                : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
            }`}
          >
            <BookOpen className="h-4 w-4" />
            Journal Entries
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('periods')}
            className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              activeTab === 'periods'
                ? 'border-primary text-primary font-semibold'
                : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
            }`}
          >
            <Calendar className="h-4 w-4" />
            Fiscal Periods & Governance
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('accounts')}
            className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              activeTab === 'accounts'
                ? 'border-primary text-primary font-semibold'
                : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
            }`}
          >
            <ListTree className="h-4 w-4" />
            Chart of Accounts
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('vat_return')}
            className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              activeTab === 'vat_return'
                ? 'border-primary text-primary font-semibold'
                : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
            }`}
          >
            <Calculator className="h-4 w-4" />
            VAT Return & Tax
          </button>
        </div>

        {/* Active Tab Content */}
        {activeTab === 'journals' && (
          <JournalEntriesTable
            entries={entries}
            loading={loadingEntries}
            onRefresh={loadEntries}
            onNewJournal={handleOpenCreateModal}
            onViewJournal={handleOpenViewModal}
            onEditJournal={handleOpenEditModal}
            onFeedback={setFeedback}
          />
        )}

        {activeTab === 'periods' && (
          <FiscalPeriodsTable
            periods={periods}
            loading={loadingPeriods}
            onRefresh={loadPeriods}
            selectedYear={selectedYear}
            onYearChange={setSelectedYear}
            onFeedback={setFeedback}
          />
        )}

        {activeTab === 'accounts' && (
          <AccountsTable
            accounts={accounts}
            loading={loadingAccounts}
            onRefresh={loadAccounts}
          />
        )}

        {activeTab === 'vat_return' && (
          <VatReturnTab
            fiscalPeriods={periods}
            onFeedback={setFeedback}
          />
        )}

        {/* Manual Journal Modal */}
        <ManualJournalModal
          open={modalState.open}
          onOpenChange={(open) => setModalState((prev) => ({ ...prev, open }))}
          entry={modalState.entry}
          mode={modalState.mode}
          accounts={accounts}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            loadEntries();
          }}
        />
      </div>
    </AppLayout>
  );
}
