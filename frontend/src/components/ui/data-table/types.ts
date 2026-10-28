import type { ReactNode } from 'react';
import type {
  ColumnDef,
  PaginationState,
  SortingState,
  RowSelectionState,
  OnChangeFn,
} from '@tanstack/react-table';

export type DataTableDensity = 'compact' | 'comfortable';

export interface DataTableServerSideOptions {
  pageCount?: number;
  totalRows?: number;
  pagination: PaginationState;
  onPaginationChange: OnChangeFn<PaginationState>;
  sorting?: SortingState;
  onSortingChange?: OnChangeFn<SortingState>;
  searchValue?: string;
  onSearchChange?: (value: string) => void;
}

export interface DataTableProps<TData, TValue = unknown> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  isLoading?: boolean;
  skeletonRowCount?: number;
  serverSide?: DataTableServerSideOptions;
  // Toolbar features
  searchPlaceholder?: string;
  searchColumnId?: string;
  onSearchChange?: (value: string) => void;
  searchValue?: string;
  toolbarActions?: ReactNode;
  showToolbar?: boolean;
  showColumnVisibility?: boolean;
  showDensityToggle?: boolean;
  // Density state
  density?: DataTableDensity;
  defaultDensity?: DataTableDensity;
  onDensityChange?: (density: DataTableDensity) => void;
  // Selection
  enableRowSelection?: boolean;
  rowSelection?: RowSelectionState;
  onRowSelectionChange?: OnChangeFn<RowSelectionState>;
  // Empty state customization
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  // Sticky header and scrolling container
  stickyHeader?: boolean;
  maxHeight?: string;
  className?: string;
}
