import * as React from 'react';
import type { Table } from '@tanstack/react-table';
import { AlignJustify, Rows3, Search, SlidersHorizontal, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DataTableDensity } from './types';

export interface DataTableToolbarProps<TData> {
  table: Table<TData>;
  searchPlaceholder?: string;
  searchColumnId?: string;
  searchValue?: string;
  onSearchChange?: (value: string) => void;
  showColumnVisibility?: boolean;
  showDensityToggle?: boolean;
  density?: DataTableDensity;
  onDensityChange?: (density: DataTableDensity) => void;
  toolbarActions?: React.ReactNode;
  children?: React.ReactNode;
}

export function DataTableToolbar<TData>({
  table,
  searchPlaceholder = 'Search...',
  searchColumnId,
  searchValue: controlledSearchValue,
  onSearchChange,
  showColumnVisibility = true,
  showDensityToggle = true,
  density = 'compact',
  onDensityChange,
  toolbarActions,
  children,
}: DataTableToolbarProps<TData>) {
  const isControlledSearch = controlledSearchValue !== undefined;
  const columnFilterValue = searchColumnId
    ? (table.getColumn(searchColumnId)?.getFilterValue() as string) ?? ''
    : '';
  const currentSearch = isControlledSearch ? controlledSearchValue : columnFilterValue;

  const handleSearchChange = (value: string) => {
    if (onSearchChange) {
      onSearchChange(value);
    } else if (searchColumnId) {
      table.getColumn(searchColumnId)?.setFilterValue(value);
    }
  };

  const hideableColumns = table
    .getAllColumns()
    .filter((column) => typeof column.accessorFn !== 'undefined' && column.getCanHide());

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 pb-3">
      {/* Left side: Search & Custom Filter Slots */}
      <div className="flex flex-1 flex-wrap items-center gap-2 min-w-[240px]">
        {(onSearchChange || searchColumnId) && (
          <div className="relative w-full max-w-xs sm:w-64">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={searchPlaceholder}
              value={currentSearch}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="h-9 pl-8 pr-8 text-xs bg-card"
            />
            {currentSearch ? (
              <button
                type="button"
                onClick={() => handleSearchChange('')}
                aria-label="Clear search"
                className="absolute right-2.5 top-2.5 text-muted-foreground hover:text-foreground"
              >
                <X className="h-4 w-4" />
              </button>
            ) : null}
          </div>
        )}
        {children}
      </div>

      {/* Right side: Density Toggle, Column Visibility, Custom Actions */}
      <div className="flex items-center space-x-2">
        {showDensityToggle && onDensityChange && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 px-2.5 text-xs text-foreground"
                aria-label="Toggle row density"
              >
                {density === 'compact' ? (
                  <Rows3 className="h-4 w-4 mr-1.5" />
                ) : (
                  <AlignJustify className="h-4 w-4 mr-1.5" />
                )}
                <span>Density</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>Row Density</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => onDensityChange('compact')}>
                <Rows3 className="mr-2 h-3.5 w-3.5" />
                <span>Compact (40px)</span>
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => onDensityChange('comfortable')}>
                <AlignJustify className="mr-2 h-3.5 w-3.5" />
                <span>Comfortable (52px)</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}

        {showColumnVisibility && hideableColumns.length > 0 && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="secondary"
                size="sm"
                className="h-9 px-2.5 text-xs text-foreground"
                aria-label="Toggle column visibility"
              >
                <SlidersHorizontal className="h-4 w-4 mr-1.5" />
                <span>Columns</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[180px]">
              <DropdownMenuLabel>Toggle Columns</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {hideableColumns.map((column) => {
                const header = column.columnDef.header;
                const columnTitle =
                  typeof header === 'string'
                    ? header
                    : column.id.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
                return (
                  <DropdownMenuCheckboxItem
                    key={column.id}
                    checked={column.getIsVisible()}
                    onCheckedChange={(value) => column.toggleVisibility(value)}
                  >
                    {columnTitle}
                  </DropdownMenuCheckboxItem>
                );
              })}
            </DropdownMenuContent>
          </DropdownMenu>
        )}

        {toolbarActions}
      </div>
    </div>
  );
}
