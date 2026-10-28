import * as React from 'react';
import type { ColumnDef } from '@tanstack/react-table';
import { Radio, RefreshCw } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type {
  LowStockAlertStreamEvent,
  LowStockItem,
} from '@/features/inventory/types/inventory.types';
import { useEventStream } from '@/shared/hooks/useEventStream';
import { cn } from '@/shared/utils/cn';

export interface LowStockTableProps {
  initialItems?: LowStockItem[];
  autoFetch?: boolean;
  enableLiveStream?: boolean;
  onAlertCountChange?: (count: number, outOfStockCount: number) => void;
  className?: string;
}

export function LowStockTable({
  initialItems,
  autoFetch = true,
  enableLiveStream = true,
  onAlertCountChange,
  className,
}: LowStockTableProps) {
  const [items, setItems] = React.useState<LowStockItem[]>(initialItems ?? []);
  const [isLoading, setIsLoading] = React.useState<boolean>(autoFetch && !initialItems);

  const fetchLowStock = React.useCallback(async () => {
    setIsLoading(true);
    try {
      const response = await inventoryApi.getLowStock({ per_page: 50 });
      setItems(response.data);
      const outOfStock = response.data.filter((i) => i.is_out_of_stock).length;
      onAlertCountChange?.(response.data.length, outOfStock);
    } catch {
      // Graceful fallback to existing items if offline or mocked
    } finally {
      setIsLoading(false);
    }
  }, [onAlertCountChange]);

  React.useEffect(() => {
    if (autoFetch && !initialItems) {
      fetchLowStock();
    }
  }, [autoFetch, initialItems, fetchLowStock]);

  // Handle SSE real-time low-stock push events
  const handleLowStockEvent = React.useCallback(
    (eventData: unknown) => {
      const event = eventData as Partial<LowStockAlertStreamEvent>;
      if (!event.product_id || !event.warehouse_id) return;

      const productId = event.product_id;
      const warehouseId = event.warehouse_id;

      setItems((prevItems) => {
        const existingIndex = prevItems.findIndex(
          (item) => item.product_id === productId && item.warehouse_id === warehouseId
        );

        const onHand = Number(event.quantity_on_hand ?? 0);
        const threshold = Number(event.low_stock_threshold ?? 0);
        const deficit = Math.max(0, threshold - onHand);
        const isOutOfStock = onHand <= 0;

        let nextItems: LowStockItem[];

        if (existingIndex >= 0) {
          nextItems = [...prevItems];
          const current = nextItems[existingIndex];
          if (current) {
            nextItems[existingIndex] = {
              ...current,
              quantity_on_hand: onHand,
              low_stock_threshold: threshold,
              deficit,
              is_out_of_stock: isOutOfStock,
              updated_at: event.timestamp ?? new Date().toISOString(),
            };
          }
        } else {
          const newItem: LowStockItem = {
            id: `${warehouseId}-${productId}`,
            product_id: productId,
            product_name: event.product_name ?? 'Unknown Product',
            sku: event.sku ?? 'SKU-UNKNOWN',
            barcode: null,
            category_name: null,
            unit_of_measure: null,
            warehouse_id: warehouseId,
            warehouse_name: event.warehouse_name ?? 'Warehouse',
            quantity_on_hand: onHand,
            quantity_reserved: 0,
            low_stock_threshold: threshold,
            deficit,
            is_out_of_stock: isOutOfStock,
            created_at: event.timestamp ?? new Date().toISOString(),
            updated_at: event.timestamp ?? new Date().toISOString(),
          };
          nextItems = [newItem, ...prevItems];
        }

        const outOfStock = nextItems.filter((i) => i.is_out_of_stock).length;
        onAlertCountChange?.(nextItems.length, outOfStock);

        return nextItems;
      });
    },
    [onAlertCountChange]
  );

  const { connectionState } = useEventStream({
    enabled: enableLiveStream,
    listeners: {
      low_stock_alert: handleLowStockEvent,
    },
  });

  const columns = React.useMemo<ColumnDef<LowStockItem>[]>(
    () => [
      {
        accessorKey: 'sku',
        header: ({ column }) => <DataTableColumnHeader column={column} title="SKU" />,
        cell: (info) => (
          <span className="font-mono font-medium text-xs text-foreground">
            {String(info.getValue())}
          </span>
        ),
      },
      {
        accessorKey: 'product_name',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Product" />,
        cell: ({ row }) => (
          <div>
            <div className="font-medium text-foreground">{row.original.product_name}</div>
            {row.original.category_name && (
              <div className="text-[11px] text-muted-foreground">
                {row.original.category_name}
              </div>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'warehouse_name',
        header: ({ column }) => <DataTableColumnHeader column={column} title="Warehouse" />,
        cell: (info) => (
          <span className="text-muted-foreground">{String(info.getValue())}</span>
        ),
      },
      {
        accessorKey: 'quantity_on_hand',
        header: ({ column }) => (
          <DataTableColumnHeader column={column} title="On Hand / Min" isNumeric />
        ),
        cell: ({ row }) => (
          <div className="text-right tabular-nums">
            <span
              className={cn(
                'font-bold',
                row.original.is_out_of_stock
                  ? 'text-destructive'
                  : 'text-warning font-semibold'
              )}
            >
              {row.original.quantity_on_hand}
            </span>
            <span className="text-muted-foreground"> / {row.original.low_stock_threshold}</span>
            {row.original.unit_of_measure && (
              <span className="ml-1 text-[11px] text-muted-foreground">
                {row.original.unit_of_measure}
              </span>
            )}
          </div>
        ),
        meta: { isNumeric: true },
      },
      {
        id: 'status',
        header: 'Status',
        cell: ({ row }) =>
          row.original.is_out_of_stock ? (
            <Badge variant="destructive">Out of Stock</Badge>
          ) : (
            <Badge variant="warning">Low Stock</Badge>
          ),
      },
      {
        accessorKey: 'deficit',
        header: ({ column }) => (
          <DataTableColumnHeader column={column} title="Deficit" isNumeric />
        ),
        cell: (info) => (
          <span className="text-right font-medium text-destructive tabular-nums block">
            -{Number(info.getValue())}
          </span>
        ),
        meta: { isNumeric: true },
      },
    ],
    []
  );

  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center space-x-3">
          <h3 className="text-base font-semibold text-foreground tracking-tight">
            Inventory Shortage & Low Stock Alerts
          </h3>
          <div className="flex items-center space-x-1.5 rounded-full border border-border px-2.5 py-0.5 text-[11px]">
            <Radio
              className={cn(
                'h-3 w-3',
                connectionState === 'connected'
                  ? 'text-success animate-pulse'
                  : connectionState === 'connecting'
                    ? 'text-warning'
                    : 'text-muted-foreground'
              )}
            />
            <span className="text-muted-foreground capitalize">
              {connectionState === 'connected' ? 'Live Stream' : connectionState}
            </span>
          </div>
        </div>

        <Button
          variant="secondary"
          size="sm"
          onClick={fetchLowStock}
          disabled={isLoading}
          className="h-8 text-xs"
        >
          <RefreshCw className={cn('h-3.5 w-3.5 mr-1.5', isLoading && 'animate-spin')} />
          Refresh
        </Button>
      </div>

      <DataTable
        columns={columns}
        data={items}
        isLoading={isLoading}
        searchColumnId="product_name"
        searchPlaceholder="Filter shortage by product name..."
        emptyTitle="All stock levels healthy"
        emptyDescription="No inventory items are currently at or below their low-stock thresholds."
      />
    </div>
  );
}
