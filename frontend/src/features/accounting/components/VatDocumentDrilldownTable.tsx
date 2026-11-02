import React, { useState, useMemo } from 'react';
import type { ColumnDef } from '@tanstack/react-table';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import type { VatDocument } from '../types/accounting.types';
import { Search } from 'lucide-react';

interface VatDocumentDrilldownTableProps {
  documents: VatDocument[];
  currency?: string;
}

function formatMoney(cents: number): string {
  const isNegative = cents < 0;
  const abs = Math.abs(cents) / 100;
  const formatted = abs.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return isNegative ? `-${formatted}` : formatted;
}

export const VatDocumentDrilldownTable: React.FC<VatDocumentDrilldownTableProps> = ({
  documents,
  currency = 'USD',
}) => {
  const [filterType, setFilterType] = useState<string>('all');
  const [search, setSearch] = useState<string>('');

  const filteredData = useMemo(() => {
    return documents.filter((doc) => {
      if (filterType !== 'all' && doc.document_type !== filterType) {
        return false;
      }
      if (search.trim()) {
        const query = search.toLowerCase();
        const matchesDoc = doc.document_number.toLowerCase().includes(query);
        const matchesContact = doc.contact_name.toLowerCase().includes(query);
        const matchesTaxId = doc.contact_tax_id?.toLowerCase().includes(query);
        return matchesDoc || matchesContact || !!matchesTaxId;
      }
      return true;
    });
  }, [documents, filterType, search]);

  const columns = useMemo<ColumnDef<VatDocument>[]>(
    () => [
      {
        accessorKey: 'date',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Date" />,
        cell: ({ row }) => <span className="tabular-nums text-foreground">{row.original.date}</span>,
      },
      {
        accessorKey: 'document_type',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Type" />,
        cell: ({ row }) => {
          const type = row.original.document_type;
          switch (type) {
            case 'sales_invoice':
              return <Badge variant="outline" className="border-emerald-500/30 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">Sales Invoice</Badge>;
            case 'credit_note':
              return <Badge variant="outline" className="border-purple-500/30 bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-400">Credit Note</Badge>;
            case 'supplier_bill':
              return <Badge variant="outline" className="border-blue-500/30 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">Supplier Bill</Badge>;
            case 'debit_note':
              return <Badge variant="outline" className="border-amber-500/30 bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">Debit Note</Badge>;
            default:
              return <Badge variant="outline">{type}</Badge>;
          }
        },
      },
      {
        accessorKey: 'document_number',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Document #" />,
        cell: ({ row }) => <span className="font-mono text-xs font-semibold text-foreground">{row.original.document_number}</span>,
      },
      {
        accessorKey: 'contact_name',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Contact & Tax ID" />,
        cell: ({ row }) => (
          <div>
            <div className="font-medium text-foreground">{row.original.contact_name}</div>
            {row.original.contact_tax_id && (
              <div className="text-xs text-muted-foreground">PAN/VAT: {row.original.contact_tax_id}</div>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'direction',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Direction" />,
        cell: ({ row }) => (
          <span className={`text-xs font-semibold uppercase ${row.original.direction === 'output' ? 'text-indigo-600 dark:text-indigo-400' : 'text-teal-600 dark:text-teal-400'}`}>
            {row.original.direction}
          </span>
        ),
      },
      {
        accessorKey: 'subtotal_cents',
        header: ({ column }) => <DataTableColumnHeader column={column} title={`Taxable Base (${currency})`} className="justify-end" />,
        cell: ({ row }) => (
          <div className="text-right tabular-nums text-foreground">
            {formatMoney(row.original.subtotal_cents)}
          </div>
        ),
      },
      {
        accessorKey: 'tax_cents',
        header: ({ column }) => <DataTableColumnHeader column={column} title={`VAT (${currency})`} className="justify-end" />,
        cell: ({ row }) => (
          <div className={`text-right font-bold tabular-nums ${row.original.tax_cents < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-foreground'}`}>
            {formatMoney(row.original.tax_cents)}
          </div>
        ),
      },
      {
        accessorKey: 'total_cents',
        header: ({ column }) => <DataTableColumnHeader column={column} title={`Total (${currency})`} className="justify-end" />,
        cell: ({ row }) => (
          <div className="text-right tabular-nums text-foreground">
            {formatMoney(row.original.total_cents)}
          </div>
        ),
      },
    ],
    [currency]
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-1">
          <Button
            size="sm"
            variant={filterType === 'all' ? 'primary' : 'outline'}
            onClick={() => setFilterType('all')}
          >
            All ({documents.length})
          </Button>
          <Button
            size="sm"
            variant={filterType === 'sales_invoice' ? 'primary' : 'outline'}
            onClick={() => setFilterType('sales_invoice')}
          >
            Invoices ({documents.filter((d) => d.document_type === 'sales_invoice').length})
          </Button>
          <Button
            size="sm"
            variant={filterType === 'credit_note' ? 'primary' : 'outline'}
            onClick={() => setFilterType('credit_note')}
          >
            Credit Notes ({documents.filter((d) => d.document_type === 'credit_note').length})
          </Button>
          <Button
            size="sm"
            variant={filterType === 'supplier_bill' ? 'primary' : 'outline'}
            onClick={() => setFilterType('supplier_bill')}
          >
            Bills ({documents.filter((d) => d.document_type === 'supplier_bill').length})
          </Button>
          <Button
            size="sm"
            variant={filterType === 'debit_note' ? 'primary' : 'outline'}
            onClick={() => setFilterType('debit_note')}
          >
            Debit Notes ({documents.filter((d) => d.document_type === 'debit_note').length})
          </Button>
        </div>

        <div className="relative w-full sm:w-64">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search document or contact..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pl-8"
          />
        </div>
      </div>

      <DataTable
        columns={columns}
        data={filteredData}
        isLoading={false}
        emptyTitle="No participating tax documents"
        emptyDescription="No invoices, credit notes, bills, or debit notes found for the selected period."
      />
    </div>
  );
};
