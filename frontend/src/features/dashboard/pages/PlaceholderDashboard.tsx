import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

export interface PlaceholderDashboardProps {
  tenantName?: string;
}

export function PlaceholderDashboard({
  tenantName = 'NexusERP Tenant',
}: PlaceholderDashboardProps) {
  return (
    <AppLayout title="Dashboard" activeNav="dashboard" tenantName={tenantName}>
      <div className="space-y-6">
        {/* Status card */}
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Welcome back to <span className="font-semibold text-foreground">{tenantName}</span>
          </p>
          <Badge variant="success">Workspace Active</Badge>
        </div>

        {/* Overview cards */}
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
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
                Authentication, user provisioning, and role-based access control scheduled for Phase 2.
              </p>
              <Badge variant="default">Phase 1 Foundation</Badge>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}

export default PlaceholderDashboard;
