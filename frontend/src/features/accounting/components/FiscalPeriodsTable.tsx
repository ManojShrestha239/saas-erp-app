import { useState, useMemo, useCallback } from 'react';
import type { ColumnDef } from '@tanstack/react-table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import { GeneratePeriodsModal } from './GeneratePeriodsModal';
import { useCan } from '@/features/auth/hooks/useCan';
import { accountingApi } from '../api/accountingApi';
import type { FiscalPeriodItem, FiscalPeriodStatus } from '../types/accounting.types';
import {
  Calendar,
  Lock,
  Unlock,
  Archive,
  Plus,
  RefreshCw,
  MoreHorizontal,
} from 'lucide-react';

interface FiscalPeriodsTableProps {
  periods: FiscalPeriodItem[];
  loading: boolean;
  onRefresh: () => void;
  selectedYear: number;
  onYearChange: (year: number) => void;
  onFeedback: (feedback: { type: 'success' | 'error'; message: string }) => void;
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

export function FiscalPeriodsTable({
  periods,
  loading,
  onRefresh,
  selectedYear,
  onYearChange,
  onFeedback,
}: FiscalPeriodsTableProps) {
  const canManageAccounting = useCan('accounting.manage');
  const [generateModalOpen, setGenerateModalOpen] = useState(false);
  const [actionInProgress, setActionInProgress] = useState<string | null>(null);

  const handleLock = useCallback(async (id: string, name: string) => {
    setActionInProgress(id);
    try {
      await accountingApi.lockFiscalPeriod(id);
      onFeedback({ type: 'success', message: `Fiscal period "${name}" has been locked.` });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to lock fiscal period.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const handleUnlock = useCallback(async (id: string, name: string) => {
    setActionInProgress(id);
    try {
      await accountingApi.unlockFiscalPeriod(id);
      onFeedback({ type: 'success', message: `Fiscal period "${name}" has been unlocked.` });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to unlock fiscal period.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const handleClose = useCallback(async (id: string, name: string) => {
    const confirmed = window.confirm(
      `Are you sure you want to permanently close fiscal period "${name}"? This action cannot be undone.`
    );
    if (!confirmed) return;

    setActionInProgress(id);
    try {
      await accountingApi.closeFiscalPeriod(id);
      onFeedback({ type: 'success', message: `Fiscal period "${name}" is now closed.` });
      onRefresh();
    } catch (err) {
      onFeedback({
        type: 'error',
        message: err instanceof Error ? err.message : 'Failed to close fiscal period.',
      });
    } finally {
      setActionInProgress(null);
    }
  }, [onFeedback, onRefresh]);

  const renderStatusBadge = (status: FiscalPeriodStatus) => {
    switch (status) {
      case 'open':
        return (
          <Badge variant="outline" className="border-emerald-500/30 text-emerald-600 bg-emerald-500/10">
            Open
          </Badge>
        );
      case 'locked':
        return (
          <Badge variant="outline" className="border-amber-500/30 text-amber-600 bg-amber-500/10">
            Locked
          </Badge>
        );
      case 'closed':
        return (
          <Badge variant="secondary" className="bg-muted text-muted-foreground">
            Closed
          </Badge>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  const columns = useMemo<ColumnDef<FiscalPeriodItem>[]>(
    () => [
      {
        accessorKey: 'period_number',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Period #" />,
        cell: ({ row }) => (
          <div className="font-mono text-xs font-semibold text-muted-foreground">
            P{String(row.original.period_number).padStart(2, '0')}
          </div>
        ),
      },
      {
        accessorKey: 'name',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Period Name" />,
        cell: ({ row }) => (
          <div className="font-medium text-foreground">
            {row.original.name}
          </div>
        ),
      },
      {
        accessorKey: 'fiscal_year',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Fiscal Year" />,
        cell: ({ row }) => <span className="tabular-nums">{row.original.fiscal_year}</span>,
      },
      {
        accessorKey: 'start_date',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Start Date" />,
        cell: ({ row }) => (
          <span className="text-muted-foreground tabular-nums text-xs">
            {formatDate(row.original.start_date)}
          </span>
        ),
      },
      {
        accessorKey: 'end_date',
        header: ({ column }) => <DataTableColumnHeader column={column} title="End Date" />,
        cell: ({ row }) => (
          <span className="text-muted-foreground tabular-nums text-xs">
            {formatDate(row.original.end_date)}
          </span>
        ),
      },
      {
        accessorKey: 'status',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Status" />,
        cell: ({ row }) => renderStatusBadge(row.original.status),
      },
      {
        id: 'actions',
        header: () => <div className="text-right">Actions</div>,
        cell: ({ row }) => {
          const period = row.original;
          const isBusy = actionInProgress === period.id;

          if (period.status === 'closed' || !canManageAccounting) {
            return (
              <div className="text-right text-xs text-muted-foreground italic">
                {period.status === 'closed' ? 'Archived' : 'View only'}
              </div>
            );
          }

          return (
            <div className="text-right">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="h-8 w-8" disabled={isBusy}>
                    <MoreHorizontal className="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-40">
                  {period.status === 'open' && (
                    <DropdownMenuItem onClick={() => handleLock(period.id, period.name)}>
                      <Lock className="h-4 w-4 mr-2 text-amber-600" />
                      Lock Period
                    </DropdownMenuItem>
                  )}
                  {period.status === 'locked' && (
                    <DropdownMenuItem onClick={() => handleUnlock(period.id, period.name)}>
                      <Unlock className="h-4 w-4 mr-2 text-emerald-600" />
                      Unlock Period
                    </DropdownMenuItem>
                  )}
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => handleClose(period.id, period.name)}
                    className="text-destructive focus:text-destructive"
                  >
                    <Archive className="h-4 w-4 mr-2" />
                    Close Period
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          );
        },
      },
    ],
    [canManageAccounting, actionInProgress, handleLock, handleUnlock, handleClose]
  );

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Calendar className="h-4 w-4 text-muted-foreground" />
          <span className="text-sm font-medium text-muted-foreground">Fiscal Year:</span>
          <select
            value={selectedYear}
            onChange={(e) => onYearChange(parseInt(e.target.value, 10))}
            className="rounded-md border border-input bg-card px-2.5 py-1 text-sm font-semibold shadow-xs focus:outline-hidden focus:ring-1 focus:ring-ring"
          >
            {[2024, 2025, 2026, 2027, 2028].map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
        </div>

        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={onRefresh} disabled={loading}>
            <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>

          {canManageAccounting && (
            <Button size="sm" onClick={() => setGenerateModalOpen(true)}>
              <Plus className="h-3.5 w-3.5 mr-1.5" />
              Generate Year Periods
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <DataTable
        columns={columns}
        data={periods}
        isLoading={loading}
        density="compact"
        emptyTitle={`No fiscal periods found for ${selectedYear}`}
        emptyDescription="Generate 12 monthly periods to enable GL posting and closing controls."
      />

      <GeneratePeriodsModal
        open={generateModalOpen}
        onOpenChange={setGenerateModalOpen}
        onSuccess={() => {
          onFeedback({
            type: 'success',
            message: `12 fiscal periods generated successfully.`,
          });
          onRefresh();
        }}
      />
    </div>
  );
}
