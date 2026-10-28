import * as React from 'react';
import {
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
  type PaginationState,
  type RowSelectionState,
  type SortingState,
  type VisibilityState,
} from '@tanstack/react-table';
import { Inbox } from 'lucide-react';
import { cn } from '@/shared/utils/cn';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { DataTablePagination } from './DataTablePagination';
import { DataTableToolbar } from './DataTableToolbar';
import type { DataTableDensity, DataTableProps } from './types';

export function DataTable<TData, TValue>({
  columns,
  data,
  isLoading = false,
  skeletonRowCount = 5,
  serverSide,
  searchPlaceholder = 'Search...',
  searchColumnId,
  searchValue,
  onSearchChange,
  toolbarActions,
  showToolbar = true,
  showColumnVisibility = true,
  showDensityToggle = true,
  density: controlledDensity,
  defaultDensity = 'compact',
  onDensityChange,
  enableRowSelection = false,
  rowSelection: controlledRowSelection,
  onRowSelectionChange,
  emptyTitle = 'No records found',
  emptyDescription = 'There are no records matching your criteria.',
  emptyAction,
  stickyHeader = true,
  maxHeight = 'max-h-[640px]',
  className,
}: DataTableProps<TData, TValue>) {
  // Client-side fallback states
  const [localSorting, setLocalSorting] = React.useState<SortingState>([]);
  const [localPagination, setLocalPagination] = React.useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [localRowSelection, setLocalRowSelection] = React.useState<RowSelectionState>({});
  const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>({});

  // Density state
  const [uncontrolledDensity, setUncontrolledDensity] =
    React.useState<DataTableDensity>(defaultDensity);
  const currentDensity = controlledDensity ?? uncontrolledDensity;

  const handleDensityChange = (nextDensity: DataTableDensity) => {
    if (onDensityChange) {
      onDensityChange(nextDensity);
    } else {
      setUncontrolledDensity(nextDensity);
    }
  };

  const isServerSide = !!serverSide;

  const sorting = isServerSide ? (serverSide.sorting ?? []) : localSorting;
  const pagination = isServerSide ? serverSide.pagination : localPagination;
  const rowSelection = controlledRowSelection ?? localRowSelection;

  const table = useReactTable({
    data,
    columns,
    state: {
      sorting,
      pagination,
      columnVisibility,
      rowSelection,
    },
    // Row selection configuration
    enableRowSelection,
    onRowSelectionChange: onRowSelectionChange ?? setLocalRowSelection,
    onColumnVisibilityChange: setColumnVisibility,
    // Server-side vs client-side controls
    manualPagination: isServerSide,
    pageCount: isServerSide
      ? (serverSide.pageCount ??
        Math.ceil((serverSide.totalRows ?? 0) / (serverSide.pagination.pageSize || 25)))
      : undefined,
    manualSorting: isServerSide,
    manualFiltering: isServerSide,
    onPaginationChange: isServerSide ? serverSide.onPaginationChange : setLocalPagination,
    onSortingChange: isServerSide ? serverSide.onSortingChange : setLocalSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: !isServerSide ? getSortedRowModel() : undefined,
    getPaginationRowModel: !isServerSide ? getPaginationRowModel() : undefined,
    getFilteredRowModel: !isServerSide ? getFilteredRowModel() : undefined,
  });

  const rowHeightClass = currentDensity === 'compact' ? 'h-10 py-1.5' : 'h-[52px] py-3';

  return (
    <div className={cn('w-full space-y-2', className)}>
      {showToolbar && (
        <DataTableToolbar
          table={table}
          searchPlaceholder={searchPlaceholder}
          searchColumnId={searchColumnId}
          searchValue={searchValue ?? serverSide?.searchValue}
          onSearchChange={onSearchChange ?? serverSide?.onSearchChange}
          showColumnVisibility={showColumnVisibility}
          showDensityToggle={showDensityToggle}
          density={currentDensity}
          onDensityChange={handleDensityChange}
          toolbarActions={toolbarActions}
        />
      )}

      {/* Main Table Container with Sapphire Outer Border and Radius */}
      <div className="rounded-lg border border-border bg-card shadow-xs overflow-hidden">
        <div
          className={cn(
            'overflow-auto',
            stickyHeader && maxHeight ? maxHeight : undefined
          )}
        >
          <Table>
            <TableHeader className={cn(stickyHeader && 'sticky top-0 z-10 bg-muted shadow-xs')}>
              {table.getHeaderGroups().map((headerGroup) => (
                <TableRow key={headerGroup.id} className="hover:bg-muted border-border/20">
                  {headerGroup.headers.map((header) => {
                    const isNumeric = Boolean(
                      (header.column.columnDef.meta as { isNumeric?: boolean } | undefined)
                        ?.isNumeric
                    );
                    return (
                      <TableHead
                        key={header.id}
                        className={cn(
                          'text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap',
                          isNumeric && 'text-right'
                        )}
                      >
                        {header.isPlaceholder
                          ? null
                          : flexRender(header.column.columnDef.header, header.getContext())}
                      </TableHead>
                    );
                  })}
                </TableRow>
              ))}
            </TableHeader>
            <TableBody>
              {isLoading ? (
                // Skeleton loading state
                Array.from({ length: skeletonRowCount }).map((_, rowIndex) => (
                  <TableRow key={`skeleton-row-${rowIndex}`} className="border-border/20">
                    {table.getVisibleLeafColumns().map((column) => (
                      <TableCell
                        key={`skeleton-cell-${rowIndex}-${column.id}`}
                        className={cn('px-4', rowHeightClass)}
                      >
                        <div className="h-4 w-3/4 rounded bg-muted/80 animate-pulse" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              ) : table.getRowModel().rows?.length ? (
                table.getRowModel().rows.map((row) => (
                  <TableRow
                    key={row.id}
                    data-state={row.getIsSelected() && 'selected'}
                    className={cn(
                      'border-border/20 transition-colors hover:bg-muted',
                      row.getIsSelected() && 'bg-primary/12 hover:bg-primary/15'
                    )}
                  >
                    {row.getVisibleCells().map((cell) => {
                      const isNumeric = Boolean(
                        (cell.column.columnDef.meta as { isNumeric?: boolean } | undefined)
                          ?.isNumeric
                      );
                      return (
                        <TableCell
                          key={cell.id}
                          className={cn(
                            'px-4 text-xs text-foreground',
                            rowHeightClass,
                            isNumeric && 'text-right tabular-nums'
                          )}
                        >
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </TableCell>
                      );
                    })}
                  </TableRow>
                ))
              ) : (
                // Designed empty state
                <TableRow>
                  <TableCell
                    colSpan={columns.length}
                    className="h-64 text-center align-middle"
                  >
                    <div className="flex flex-col items-center justify-center p-8 space-y-3">
                      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <Inbox className="h-6 w-6 text-muted-foreground" />
                      </div>
                      <div className="space-y-1 text-center">
                        <h3 className="text-sm font-semibold text-foreground">
                          {emptyTitle}
                        </h3>
                        <p className="text-xs text-muted-foreground max-w-sm">
                          {emptyDescription}
                        </p>
                      </div>
                      {emptyAction && <div className="pt-2">{emptyAction}</div>}
                    </div>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </div>

        {/* Footer with Pagination */}
        <DataTablePagination
          table={table}
          totalRows={isServerSide ? serverSide.totalRows : undefined}
        />
      </div>
    </div>
  );
}
