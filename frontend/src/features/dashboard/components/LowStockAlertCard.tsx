import { AlertTriangle } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/shared/utils/cn';

export interface LowStockAlertCardProps {
  count: number;
  outOfStockCount?: number;
  className?: string;
  onClick?: () => void;
}

export function LowStockAlertCard({
  count,
  outOfStockCount = 0,
  className,
  onClick,
}: LowStockAlertCardProps) {
  const isCritical = outOfStockCount > 0;

  return (
    <Card
      onClick={onClick}
      className={cn(
        'transition-all duration-200 border-border',
        onClick && 'cursor-pointer hover:shadow-md hover:border-warning/60',
        count > 0 && 'border-warning/30 bg-warning-bg/10',
        className
      )}
    >
      <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
        <CardTitle className="text-sm font-medium text-foreground">
          Low Stock Alerts
        </CardTitle>
        <div
          className={cn(
            'flex h-9 w-9 items-center justify-center rounded-md border',
            count > 0
              ? 'border-warning/40 bg-warning-bg text-warning'
              : 'border-border bg-muted text-muted-foreground'
          )}
        >
          <AlertTriangle className="h-4 w-4" />
        </div>
      </CardHeader>
      <CardContent className="space-y-2">
        <div className="flex items-baseline justify-between">
          <div className="text-3xl font-bold tabular-nums text-foreground tracking-tight">
            {count}
          </div>
          {count > 0 && (
            <Badge variant={isCritical ? 'destructive' : 'warning'}>
              {isCritical ? `${outOfStockCount} Out of Stock` : 'Needs Reorder'}
            </Badge>
          )}
        </div>
        <p className="text-xs text-muted-foreground">
          {count === 0
            ? 'All products are currently above minimum threshold.'
            : `${count} item(s) currently at or below minimum threshold.`}
        </p>
      </CardContent>
    </Card>
  );
}
