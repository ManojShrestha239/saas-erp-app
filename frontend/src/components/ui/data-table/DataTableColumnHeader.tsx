import * as React from 'react';
import type { Column } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ChevronsUpDown, EyeOff } from 'lucide-react';
import { cn } from '@/shared/utils/cn';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export interface DataTableColumnHeaderProps<TData, TValue>
  extends React.HTMLAttributes<HTMLDivElement> {
  column: Column<TData, TValue>;
  title: string;
  isNumeric?: boolean;
}

export function DataTableColumnHeader<TData, TValue>({
  column,
  title,
  isNumeric = false,
  className,
}: DataTableColumnHeaderProps<TData, TValue>) {
  if (!column.getCanSort()) {
    return (
      <div
        className={cn(
          'flex items-center text-xs font-semibold uppercase tracking-wider text-muted-foreground',
          isNumeric && 'justify-end text-right',
          className
        )}
      >
        {title}
      </div>
    );
  }

  const isSorted = column.getIsSorted();

  return (
    <div
      className={cn(
        'flex items-center space-x-2',
        isNumeric && 'justify-end text-right',
        className
      )}
    >
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="sm"
            className={cn(
              '-ml-3 h-8 data-[state=open]:bg-muted hover:bg-muted/80 text-xs font-semibold uppercase tracking-wider text-muted-foreground hover:text-foreground',
              isSorted && 'text-foreground font-bold'
            )}
          >
            <span>{title}</span>
            {isSorted === 'desc' ? (
              <ArrowDown className="ml-1.5 h-3.5 w-3.5 text-foreground" />
            ) : isSorted === 'asc' ? (
              <ArrowUp className="ml-1.5 h-3.5 w-3.5 text-foreground" />
            ) : (
              <ChevronsUpDown className="ml-1.5 h-3.5 w-3.5 opacity-50" />
            )}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align={isNumeric ? 'end' : 'start'}>
          <DropdownMenuItem onClick={() => column.toggleSorting(false)}>
            <ArrowUp className="mr-2 h-3.5 w-3.5 text-muted-foreground/70" />
            Asc
          </DropdownMenuItem>
          <DropdownMenuItem onClick={() => column.toggleSorting(true)}>
            <ArrowDown className="mr-2 h-3.5 w-3.5 text-muted-foreground/70" />
            Desc
          </DropdownMenuItem>
          {isSorted && (
            <DropdownMenuItem onClick={() => column.clearSorting()}>
              <ChevronsUpDown className="mr-2 h-3.5 w-3.5 text-muted-foreground/70" />
              Reset sort
            </DropdownMenuItem>
          )}
          {column.getCanHide() && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => column.toggleVisibility(false)}>
                <EyeOff className="mr-2 h-3.5 w-3.5 text-muted-foreground/70" />
                Hide column
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}
