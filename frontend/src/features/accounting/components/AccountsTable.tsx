import { useState, useMemo } from 'react';
import type { ColumnDef } from '@tanstack/react-table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import type { AccountItem, AccountType } from '../types/accounting.types';
import { RefreshCw, Search, ShieldCheck } from 'lucide-react';

interface AccountsTableProps {
  accounts: AccountItem[];
  loading: boolean;
  onRefresh: () => void;
}

export function AccountsTable({ accounts, loading, onRefresh }: AccountsTableProps) {
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState<string>('all');

  const renderTypeBadge = (type: AccountType) => {
    switch (type) {
      case 'asset':
        return <Badge variant="outline" className="border-blue-500/30 text-blue-600 bg-blue-500/10">Asset</Badge>;
      case 'liability':
        return <Badge variant="outline" className="border-amber-500/30 text-amber-600 bg-amber-500/10">Liability</Badge>;
      case 'equity':
        return <Badge variant="outline" className="border-purple-500/30 text-purple-600 bg-purple-500/10">Equity</Badge>;
      case 'income':
        return <Badge variant="outline" className="border-emerald-500/30 text-emerald-600 bg-emerald-500/10">Income</Badge>;
      case 'expense':
        return <Badge variant="outline" className="border-rose-500/30 text-rose-600 bg-rose-500/10">Expense</Badge>;
      default:
        return <Badge variant="outline">{type}</Badge>;
    }
  };

  const filteredAccounts = useMemo(() => {
    return accounts.filter((a) => {
      if (typeFilter !== 'all' && a.type !== typeFilter) return false;
      if (searchTerm.trim() !== '') {
        const term = searchTerm.toLowerCase();
        return a.code.toLowerCase().includes(term) || a.name.toLowerCase().includes(term);
      }
      return true;
    });
  }, [accounts, typeFilter, searchTerm]);

  const columns = useMemo<ColumnDef<AccountItem>[]>(
    () => [
      {
        accessorKey: 'code',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Account Code" />,
        cell: ({ row }) => (
          <div className="font-mono text-xs font-bold text-foreground">
            {row.original.code}
          </div>
        ),
      },
      {
        accessorKey: 'name',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Account Name" />,
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <span className="font-medium text-foreground text-xs">{row.original.name}</span>
            {row.original.is_system && (
              <span title="System control account" className="inline-flex text-primary">
                <ShieldCheck className="h-3.5 w-3.5" />
              </span>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'type',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Category" />,
        cell: ({ row }) => renderTypeBadge(row.original.type),
      },
      {
        accessorKey: 'normal_balance',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Normal Balance" />,
        cell: ({ row }) => (
          <span className="text-xs uppercase text-muted-foreground font-mono">
            {row.original.normal_balance}
          </span>
        ),
      },
      {
        accessorKey: 'is_active',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Status" />,
        cell: ({ row }) => (
          <Badge
            variant={row.original.is_active ? 'outline' : 'secondary'}
            className={row.original.is_active ? 'text-emerald-600 border-emerald-500/30 bg-emerald-500/10' : ''}
          >
            {row.original.is_active ? 'Active' : 'Inactive'}
          </Badge>
        ),
      },
    ],
    []
  );

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2 flex-1 max-w-lg">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="h-4 w-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search accounts by code or name..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-8 h-9 text-xs"
            />
          </div>

          <select
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            className="rounded-md border border-input bg-card px-2.5 py-1.5 text-xs shadow-xs focus:ring-1 focus:ring-ring"
          >
            <option value="all">All Types</option>
            <option value="asset">Assets</option>
            <option value="liability">Liabilities</option>
            <option value="equity">Equity</option>
            <option value="income">Income</option>
            <option value="expense">Expenses</option>
          </select>
        </div>

        <Button variant="outline" size="sm" onClick={onRefresh} disabled={loading}>
          <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </Button>
      </div>

      <DataTable
        columns={columns}
        data={filteredAccounts}
        isLoading={loading}
        density="compact"
        emptyTitle="No accounts found"
        emptyDescription="Chart of Accounts seeded automatically upon tenant provisioning."
      />
    </div>
  );
}
