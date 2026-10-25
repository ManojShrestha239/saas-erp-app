import React from 'react';
import {
  ShieldAlert,
  Building2,
  Package,
  CreditCard,
  Flag,
  FileSpreadsheet,
} from 'lucide-react';
import { cn } from '@/shared/utils/cn';
import { Badge } from '@/components/ui/badge';

export interface AdminLayoutProps {
  children: React.ReactNode;
  title?: string;
  activeNav?: string;
}

interface AdminNavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string; strokeWidth?: number }>;
  id: string;
}

const ADMIN_NAV_ITEMS: AdminNavItem[] = [
  { name: 'Tenants', href: '/admin/tenants', icon: Building2, id: 'tenants' },
  { name: 'Packages', href: '/admin/packages', icon: Package, id: 'packages' },
  { name: 'Subscriptions', href: '/admin/subscriptions', icon: CreditCard, id: 'subscriptions' },
  { name: 'Feature Flags', href: '/admin/feature-flags', icon: Flag, id: 'feature-flags' },
  { name: 'Global Audit', href: '/admin/audit', icon: FileSpreadsheet, id: 'audit' },
];

export function AdminLayout({
  children,
  title,
  activeNav = 'tenants',
}: AdminLayoutProps) {
  return (
    <div className="flex h-screen w-full overflow-hidden bg-background text-foreground">
      {/* Central Super Admin Sidebar */}
      <aside className="flex w-64 flex-col bg-sidebar text-sidebar-foreground">
        <div className="flex h-16 items-center justify-between px-6 border-b border-sidebar-foreground/10">
          <div className="flex items-center gap-2">
            <ShieldAlert className="h-6 w-6 text-primary" strokeWidth={1.5} />
            <span className="text-lg font-bold tracking-tight text-sidebar-foreground">
              Super<span className="text-primary">Admin</span>
            </span>
          </div>
          <Badge variant="default" className="text-[10px] px-1.5 py-0.5 uppercase tracking-wide">
            Central
          </Badge>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
          {ADMIN_NAV_ITEMS.map((item) => {
            const isActive = item.id === activeNav;
            const Icon = item.icon;
            return (
              <a
                key={item.id}
                href={item.href}
                className={cn(
                  'flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-md transition-colors',
                  isActive
                    ? 'border-l-4 border-primary bg-primary/15 text-sidebar-foreground font-semibold pl-2'
                    : 'text-sidebar-foreground/80 hover:bg-sidebar-foreground/10 hover:text-sidebar-foreground'
                )}
              >
                <Icon className="h-5 w-5 shrink-0" strokeWidth={1.5} />
                <span>{item.name}</span>
              </a>
            );
          })}
        </nav>
      </aside>

      <div className="flex flex-1 flex-col overflow-hidden">
        <header className="flex h-16 items-center justify-between border-b border-border bg-card px-8">
          <span className="text-sm font-medium text-muted-foreground">
            Central Administration Domain
          </span>
        </header>

        <main className="flex-1 overflow-y-auto bg-background p-8">
          <div className="mx-auto max-w-[1440px]">
            {title && (
              <div className="mb-6">
                <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                  {title}
                </h1>
              </div>
            )}
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
