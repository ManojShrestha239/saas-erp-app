import * as React from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import type { LowStockItem } from '@/features/inventory/types/inventory.types';
import { LowStockAlertCard } from '../components/LowStockAlertCard';
import { LowStockTable } from '../components/LowStockTable';

export interface PlaceholderDashboardProps {
  tenantName?: string;
  lowStockItems?: LowStockItem[];
}

export function PlaceholderDashboard({
  tenantName = 'NexusERP Tenant',
  lowStockItems,
}: PlaceholderDashboardProps) {
  const [lowStockCount, setLowStockCount] = React.useState<number>(lowStockItems?.length ?? 0);
  const [outOfStockCount, setOutOfStockCount] = React.useState<number>(
    lowStockItems?.filter((i) => i.is_out_of_stock).length ?? 0
  );

  const handleAlertCountChange = React.useCallback(
    (count: number, outOfStock: number) => {
      setLowStockCount(count);
      setOutOfStockCount(outOfStock);
    },
    []
  );

  return (
    <AppLayout title="Dashboard" activeNav="dashboard" tenantName={tenantName}>
      <div className="space-y-6">
        {/* Status bar */}
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Welcome back to <span className="font-semibold text-foreground">{tenantName}</span>
          </p>
          <Badge variant="success">Workspace Active</Badge>
        </div>

        {/* Overview cards / KPIs */}
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
          <LowStockAlertCard
            count={lowStockCount}
            outOfStockCount={outOfStockCount}
          />

          <Card>
            <CardHeader>
              <CardTitle>System Overview</CardTitle>
              <CardDescription>Multi-tenant infrastructure initialized</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground mb-4">
                The frontend shell and design tokens are successfully configured according to NexusERP specifications.
              </p>
              <Button variant="primary" size="default">
                Explore Modules
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Tenant Database</CardTitle>
              <CardDescription>Database-per-tenant isolation</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground mb-4">
                Active tenant context bound to current subdomain with runtime connection switching.
              </p>
              <Button variant="secondary" size="default">
                View Configuration
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Next Steps</CardTitle>
              <CardDescription>Roadmap progression</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground mb-4">
                Inventory engine and real-time SSE stream monitoring active.
              </p>
              <Badge variant="default">Phase 3 Active</Badge>
            </CardContent>
          </Card>
        </div>

        {/* Real-Time Low Stock & Shortage Alerts Section */}
        <div className="pt-2">
          <LowStockTable
            initialItems={lowStockItems}
            autoFetch={lowStockItems === undefined}
            onAlertCountChange={handleAlertCountChange}
          />
        </div>
      </div>
    </AppLayout>
  );
}

export default PlaceholderDashboard;
