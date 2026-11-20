import * as React from 'react';
import { cn } from '@/shared/utils/cn';

export interface SkeletonProps extends React.HTMLAttributes<HTMLDivElement> {
  shimmer?: boolean;
}

export function Skeleton({
  className,
  shimmer = true,
  ...props
}: SkeletonProps) {
  return (
    <div
      role="status"
      aria-label="Loading content"
      aria-busy="true"
      className={cn(
        'rounded-md bg-muted',
        shimmer ? 'animate-shimmer' : 'animate-pulse',
        className
      )}
      {...props}
    />
  );
}

export interface TableSkeletonProps {
  rows?: number;
  columns?: number;
  className?: string;
}

export function TableSkeleton({
  rows = 5,
  columns = 4,
  className,
}: TableSkeletonProps) {
  return (
    <div
      className={cn('w-full border border-border rounded-lg overflow-hidden bg-card', className)}
      role="status"
      aria-label="Loading table data"
      aria-busy="true"
    >
      {/* Header skeleton */}
      <div className="flex items-center gap-4 border-b border-border bg-muted/60 px-4 py-3">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton
            key={`th-${i}`}
            className={cn('h-4', i === 0 ? 'w-32' : 'flex-1')}
          />
        ))}
      </div>
      {/* Rows skeleton */}
      <div className="divide-y divide-border/20">
        {Array.from({ length: rows }).map((_, rIdx) => (
          <div key={`tr-${rIdx}`} className="flex items-center gap-4 px-4 py-3.5">
            {Array.from({ length: columns }).map((_, cIdx) => (
              <Skeleton
                key={`td-${rIdx}-${cIdx}`}
                className={cn('h-4', cIdx === 0 ? 'w-28' : 'flex-1')}
              />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

export function CardSkeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn('rounded-lg border border-border bg-card p-6 space-y-4 shadow-sm', className)}
      role="status"
      aria-label="Loading card content"
      aria-busy="true"
    >
      <div className="flex items-center justify-between">
        <Skeleton className="h-4 w-28" />
        <Skeleton className="h-6 w-16 rounded-full" />
      </div>
      <Skeleton className="h-8 w-40" />
      <Skeleton className="h-3 w-full" />
    </div>
  );
}
