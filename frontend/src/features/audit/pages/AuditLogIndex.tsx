import { useState, useEffect, useCallback, useMemo } from 'react';
import type { ColumnDef, PaginationState, SortingState } from '@tanstack/react-table';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import { AuditDiffModal } from '../components/AuditDiffModal';
import { auditApi } from '../api/auditApi';
import type { AuditLog } from '../types/audit.types';
import {
  Search,
  RefreshCw,
  Eye,
  AlertCircle,
  ShieldCheck,
  Calendar,
} from 'lucide-react';

function getEventVariant(event: string | null): 'default' | 'success' | 'destructive' | 'warning' {
  switch (event?.toLowerCase()) {
    case 'created':
      return 'success';
    case 'updated':
      return 'warning';
    case 'deleted':
      return 'destructive';
    default:
      return 'default';
  }
}

export default function AuditLogIndex() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [totalCount, setTotalCount] = useState(0);
  const [error, setError] = useState<string | null>(null);

  // Filters & State
  const [search, setSearch] = useState('');
  const [eventFilter, setEventFilter] = useState<string>('all');
  const [fromDate, setFromDate] = useState<string>('');
  const [toDate, setToDate] = useState<string>('');

  // Pagination & Sorting
  const [pagination, setPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [sorting, setSorting] = useState<SortingState>([
    { id: 'id', desc: true },
  ]);

  // Diff Modal State
  const [selectedLog, setSelectedLog] = useState<AuditLog | null>(null);
  const [diffModalOpen, setDiffModalOpen] = useState(false);

  const fetchLogs = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const sortCol = sorting[0]?.id || 'id';
      const sortDir = sorting[0]?.desc ? 'desc' : 'asc';

      const response = await auditApi.getAuditLogs({
        search: search.trim() || undefined,
        event: eventFilter !== 'all' ? eventFilter : undefined,
        from: fromDate || undefined,
        to: toDate || undefined,
        page: pagination.pageIndex + 1,
        per_page: pagination.pageSize,
        sort_by: sortCol,
        sort_direction: sortDir,
      });

      setLogs(response.data);
      const total = typeof response.meta?.total === 'number' ? response.meta.total : response.data.length;
      setTotalCount(total);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to load audit logs';
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, [search, eventFilter, fromDate, toDate, pagination.pageIndex, pagination.pageSize, sorting]);

  useEffect(() => {
    fetchLogs();
  }, [fetchLogs]);

  const columns = useMemo<ColumnDef<AuditLog>[]>(
    () => [
      {
        accessorKey: 'id',
        header: ({ column }) => <DataTableColumnHeader column={column} title="#" />,
        cell: ({ row }) => <span className="font-mono text-xs text-muted-foreground">{row.getValue('id')}</span>,
        enableSorting: true,
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Timestamp" />,
        cell: ({ row }) => {
          const val = row.getValue('created_at') as string | null;
          return (
            <span className="text-xs text-muted-foreground whitespace-nowrap">
              {val ? new Date(val).toLocaleString() : '—'}
            </span>
          );
        },
        enableSorting: true,
      },
      {
        accessorKey: 'event',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Event" />,
        cell: ({ row }) => {
          const event = row.getValue('event') as string | null;
          return (
            <Badge variant={getEventVariant(event)} className="capitalize text-xs">
              {event ?? 'log'}
            </Badge>
          );
        },
        enableSorting: true,
      },
      {
        accessorKey: 'description',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Description" />,
        cell: ({ row }) => (
          <span className="text-sm font-medium text-foreground max-w-xs truncate block" title={row.getValue('description')}>
            {row.getValue('description')}
          </span>
        ),
        enableSorting: true,
      },
      {
        accessorKey: 'subject_type',
        header: 'Subject',
        cell: ({ row }) => {
          const type = row.original.subject_type;
          const id = row.original.subject_id;
          if (!type) return <span className="text-xs text-muted-foreground">—</span>;
          const simpleType = type.split('\\').pop();
          return (
            <div className="text-xs">
              <span className="font-semibold text-foreground">{simpleType}</span>
              {id && <span className="font-mono text-muted-foreground ml-1.5 truncate max-w-[100px] inline-block align-bottom" title={id}>#{id.slice(0, 8)}</span>}
            </div>
          );
        },
      },
      {
        accessorKey: 'causer',
        header: 'Causer',
        cell: ({ row }) => {
          const causer = row.original.causer;
          return (
            <div className="text-xs">
              <div className="font-medium text-foreground">{causer?.name ?? 'System'}</div>
              {causer?.email && <div className="text-muted-foreground text-[11px] truncate max-w-[140px]">{causer.email}</div>}
            </div>
          );
        },
      },
      {
        accessorKey: 'ip_address',
        header: 'IP Address',
        cell: ({ row }) => (
          <span className="font-mono text-xs text-muted-foreground">
            {row.original.ip_address ?? '—'}
          </span>
        ),
      },
      {
        id: 'actions',
        header: '',
        cell: ({ row }) => (
          <Button
            variant="ghost"
            size="sm"
            className="h-8 px-2 text-xs"
            onClick={() => {
              setSelectedLog(row.original);
              setDiffModalOpen(true);
            }}
          >
            <Eye className="h-3.5 w-3.5 mr-1" />
            Diff
          </Button>
        ),
      },
    ],
    []
  );

  return (
    <AppLayout activeNav="audit-logs" breadcrumbs={[{ label: 'Audit Logs' }]}>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <ShieldCheck className="h-6 w-6 text-primary" />
              <h1 className="text-2xl font-bold tracking-tight text-foreground">Immutable Audit Logs</h1>
            </div>
            <p className="text-sm text-muted-foreground mt-1">
              Tamper-proof compliance history of all domain actions, mutations, and actor identities.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => fetchLogs()}
              disabled={loading}
              className="gap-1.5"
            >
              <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
              <span>Refresh</span>
            </Button>
          </div>
        </div>

        {/* Error notification */}
        {error && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="flex items-center justify-between">
              <span>{error}</span>
              <button
                type="button"
                onClick={() => setError(null)}
                className="text-xs text-muted-foreground hover:text-foreground font-semibold ml-4"
              >
                Dismiss
              </button>
            </AlertDescription>
          </Alert>
        )}

        {/* Filter Toolbar */}
        <div className="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between bg-card p-3 rounded-lg border border-border">
          {/* Event Filter Tabs */}
          <div className="flex items-center gap-1 overflow-x-auto pb-1 md:pb-0">
            {[
              { label: 'All Events', value: 'all' },
              { label: 'Created', value: 'created' },
              { label: 'Updated', value: 'updated' },
              { label: 'Deleted', value: 'deleted' },
            ].map((tab) => (
              <Button
                key={tab.value}
                variant={eventFilter === tab.value ? 'primary' : 'ghost'}
                size="sm"
                className="text-xs h-8"
                onClick={() => {
                  setEventFilter(tab.value);
                  setPagination((p) => ({ ...p, pageIndex: 0 }));
                }}
              >
                {tab.label}
              </Button>
            ))}
          </div>

          {/* Search & Dates */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
            <div className="relative w-full sm:w-56">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input
                placeholder="Search logs..."
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value);
                  setPagination((p) => ({ ...p, pageIndex: 0 }));
                }}
                className="pl-8 h-8 text-xs"
              />
            </div>
            <div className="flex items-center gap-1.5">
              <Calendar className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
              <Input
                type="date"
                value={fromDate}
                onChange={(e) => {
                  setFromDate(e.target.value);
                  setPagination((p) => ({ ...p, pageIndex: 0 }));
                }}
                className="h-8 text-xs w-32"
                title="From date"
              />
              <span className="text-xs text-muted-foreground">to</span>
              <Input
                type="date"
                value={toDate}
                onChange={(e) => {
                  setToDate(e.target.value);
                  setPagination((p) => ({ ...p, pageIndex: 0 }));
                }}
                className="h-8 text-xs w-32"
                title="To date"
              />
            </div>
          </div>
        </div>

        {/* Data Table */}
        <DataTable
          columns={columns}
          data={logs}
          isLoading={loading}
          serverSide={{
            totalRows: totalCount,
            pagination,
            onPaginationChange: setPagination,
            sorting,
            onSortingChange: setSorting,
          }}
          emptyTitle="No audit logs found"
          emptyDescription={
            search || eventFilter !== 'all' || fromDate || toDate
              ? 'Try modifying your search or date filter parameters.'
              : 'Audit events will appear here as domain models and records are mutated.'
          }
        />

        {/* Diff Modal */}
        <AuditDiffModal
          open={diffModalOpen}
          onOpenChange={setDiffModalOpen}
          log={selectedLog}
        />
      </div>
    </AppLayout>
  );
}
