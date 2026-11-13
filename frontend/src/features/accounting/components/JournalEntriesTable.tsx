import { useState, useMemo, useCallback } from 'react';
import type { ColumnDef } from '@tanstack/react-table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import { useCan } from '@/features/auth/hooks/useCan';
import { accountingApi } from '../api/accountingApi';
import type { JournalEntryItem, JournalEntryStatus } from '../types/accounting.types';
import {
  Plus,
  RefreshCw,
  Search,
  MoreHorizontal,
  Eye,
  Edit2,
  Send,
  CheckCircle,
  XCircle,
  RotateCcw,
  Trash2,
} from 'lucide-react';

interface JournalEntriesTableProps {
  entries: JournalEntryItem[];
  loading: boolean;
  onRefresh: () => void;
  onNewJournal: () => void;
  onViewJournal: (entry: JournalEntryItem) => void;
  onEditJournal: (entry: JournalEntryItem) => void;
  onFeedback: (feedback: { type: 'success' | 'error'; message: string }) => void;
}

function formatMoneyCents(cents: number): string {
  const amount = cents / 100;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

function formatDate(dateStr?: string | null): string {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

export function JournalEntriesTable({
  entries,
  loading,
  onRefresh,
  onNewJournal,
  onViewJournal,
  onEditJournal,
  onFeedback,
}: JournalEntriesTableProps) {
  const canManageAccounting = useCan('accounting.manage');
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [actionInProgress, setActionInProgress] = useState<string | null>(null);

  const handleSubmitForApproval = useCallback(async (id: string) => {
    setActionInProgress(id);
    try {
      await accountingApi.submitJournalEntry(id);
      onFeedback({ type: 'success', message: 'Journal entry submitted for approval.' });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to submit journal entry.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const handleApprove = useCallback(async (id: string) => {
    setActionInProgress(id);
    try {
      const res = await accountingApi.approveJournalEntry(id);
      onFeedback({
        type: 'success',
        message: `Journal entry ${res.data.entry_number || ''} approved and posted to General Ledger.`,
      });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to approve journal entry.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const handleReject = useCallback(async (id: string) => {
    const reason = window.prompt('Please provide a mandatory reason for rejecting this journal entry:');
    if (!reason || reason.trim() === '') return;

    setActionInProgress(id);
    try {
      await accountingApi.rejectJournalEntry(id, { rejection_reason: reason.trim() });
      onFeedback({ type: 'success', message: 'Journal entry rejected.' });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to reject journal entry.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const handleReverse = useCallback(async (id: string, entryNumber?: string | null) => {
    const reason = window.prompt(`Please provide a reason to reverse journal entry ${entryNumber || id}:`);
    if (!reason || reason.trim() === '') return;

    setActionInProgress(id);
    try {
      const res = await accountingApi.reverseJournalEntry(id, { reason: reason.trim() });
      onFeedback({
        type: 'success',
        message: `Reversal entry ${res.data.entry_number || ''} posted successfully.`,
      });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to reverse journal entry.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const handleDelete = useCallback(async (id: string) => {
    const confirmed = window.confirm('Are you sure you want to delete this draft journal entry?');
    if (!confirmed) return;

    setActionInProgress(id);
    try {
      await accountingApi.deleteJournalEntry(id);
      onFeedback({ type: 'success', message: 'Draft journal entry deleted.' });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to delete journal entry.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const renderStatusBadge = (status: JournalEntryStatus) => {
    switch (status) {
      case 'draft':
        return (
          <Badge variant="outline" className="border-border text-muted-foreground bg-muted/40">
            Draft
          </Badge>
        );
      case 'pending_approval':
        return (
          <Badge variant="outline" className="border-amber-500/30 text-amber-600 bg-amber-500/10">
            Pending Approval
          </Badge>
        );
      case 'posted':
        return (
          <Badge variant="outline" className="border-emerald-500/30 text-emerald-600 bg-emerald-500/10">
            Posted
          </Badge>
        );
      case 'rejected':
        return (
          <Badge variant="outline" className="border-destructive/30 text-destructive bg-destructive/10">
            Rejected
          </Badge>
        );
      case 'reversed':
        return (
          <Badge variant="secondary" className="border-purple-500/30 text-purple-600 bg-purple-500/10">
            Reversed
          </Badge>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  const filteredEntries = useMemo(() => {
    const list = Array.isArray(entries) ? entries : [];
    return list.filter((e) => {
      if (statusFilter !== 'all' && e.status !== statusFilter) {
        return false;
      }
      if (searchTerm.trim() !== '') {
        const term = searchTerm.toLowerCase();
        const number = (e.entry_number || '').toLowerCase();
        const desc = (e.description || '').toLowerCase();
        const src = (e.source_type || '').toLowerCase();
        return number.includes(term) || desc.includes(term) || src.includes(term);
      }
      return true;
    });
  }, [entries, statusFilter, searchTerm]);

  const columns = useMemo<ColumnDef<JournalEntryItem>[]>(
    () => [
      {
        accessorKey: 'entry_number',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Entry #" />,
        cell: ({ row }) => (
          <div className="font-mono text-xs font-semibold text-foreground">
            {row.original.entry_number || <span className="text-muted-foreground italic">Draft</span>}
          </div>
        ),
      },
      {
        accessorKey: 'entry_date',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Date" />,
        cell: ({ row }) => (
          <span className="text-muted-foreground tabular-nums text-xs">
            {formatDate(row.original.entry_date)}
          </span>
        ),
      },
      {
        accessorKey: 'status',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Status" />,
        cell: ({ row }) => renderStatusBadge(row.original.status),
      },
      {
        accessorKey: 'description',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Narration" />,
        cell: ({ row }) => (
          <div className="max-w-xs truncate text-xs font-medium text-foreground">
            {row.original.description || <span className="text-muted-foreground">—</span>}
          </div>
        ),
      },
      {
        accessorKey: 'total_debit_cents',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Total Amount" />,
        cell: ({ row }) => (
          <div className="font-mono text-xs tabular-nums font-semibold text-foreground">
            {formatMoneyCents(row.original.total_debit_cents)}
          </div>
        ),
      },
      {
        accessorKey: 'source_type',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Source" />,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground capitalize">
            {row.original.source_type ? row.original.source_type.replace('_', ' ') : 'Manual'}
          </span>
        ),
      },
      {
        id: 'actions',
        header: () => <div className="text-right">Actions</div>,
        cell: ({ row }) => {
          const entry = row.original;
          const isBusy = actionInProgress === entry.id;

          return (
            <div className="text-right">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="h-8 w-8" disabled={isBusy}>
                    <MoreHorizontal className="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                  <DropdownMenuItem onClick={() => onViewJournal(entry)}>
                    <Eye className="h-4 w-4 mr-2 text-muted-foreground" />
                    View Details
                  </DropdownMenuItem>

                  {canManageAccounting && (entry.status === 'draft' || entry.status === 'rejected') && (
                    <>
                      <DropdownMenuItem onClick={() => onEditJournal(entry)}>
                        <Edit2 className="h-4 w-4 mr-2 text-blue-600" />
                        Edit Draft
                      </DropdownMenuItem>
                      <DropdownMenuItem onClick={() => handleSubmitForApproval(entry.id)}>
                        <Send className="h-4 w-4 mr-2 text-primary" />
                        Submit for Approval
                      </DropdownMenuItem>
                    </>
                  )}

                  {canManageAccounting && (entry.status === 'pending_approval' || entry.status === 'draft') && (
                    <DropdownMenuItem onClick={() => handleApprove(entry.id)}>
                      <CheckCircle className="h-4 w-4 mr-2 text-emerald-600" />
                      Approve & Post
                    </DropdownMenuItem>
                  )}

                  {canManageAccounting && entry.status === 'pending_approval' && (
                    <DropdownMenuItem onClick={() => handleReject(entry.id)} className="text-destructive">
                      <XCircle className="h-4 w-4 mr-2" />
                      Reject Entry
                    </DropdownMenuItem>
                  )}

                  {canManageAccounting && entry.status === 'posted' && (
                    <>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem onClick={() => handleReverse(entry.id, entry.entry_number)}>
                        <RotateCcw className="h-4 w-4 mr-2 text-amber-600" />
                        Reverse Entry
                      </DropdownMenuItem>
                    </>
                  )}

                  {canManageAccounting && (entry.status === 'draft' || entry.status === 'rejected') && (
                    <>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        onClick={() => handleDelete(entry.id)}
                        className="text-destructive focus:text-destructive"
                      >
                        <Trash2 className="h-4 w-4 mr-2" />
                        Delete Draft
                      </DropdownMenuItem>
                    </>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          );
        },
      },
    ],
    [
      canManageAccounting,
      actionInProgress,
      onViewJournal,
      onEditJournal,
      handleSubmitForApproval,
      handleApprove,
      handleReject,
      handleReverse,
      handleDelete,
    ]
  );

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2 flex-1 max-w-lg">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="h-4 w-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search by entry #, narration, or source..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-8 h-9 text-xs"
            />
          </div>

          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-md border border-input bg-card px-2.5 py-1.5 text-xs shadow-xs focus:ring-1 focus:ring-ring"
          >
            <option value="all">All Statuses</option>
            <option value="draft">Drafts</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="posted">Posted</option>
            <option value="rejected">Rejected</option>
            <option value="reversed">Reversed</option>
          </select>
        </div>

        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={onRefresh} disabled={loading}>
            <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>

          {canManageAccounting && (
            <Button size="sm" onClick={onNewJournal}>
              <Plus className="h-3.5 w-3.5 mr-1.5" />
              New Journal Entry
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <DataTable
        columns={columns}
        data={filteredEntries}
        isLoading={loading}
        density="compact"
        emptyTitle="No journal entries found"
        emptyDescription="Post or draft manual journal entries, or review automated transactions from billing."
      />
    </div>
  );
}
