import React from 'react';
import {
  ShieldAlert,
  LayoutDashboard,
  Building2,
  Package,
  CreditCard,
  Flag,
  LogOut,
} from 'lucide-react';
import { cn } from '@/shared/utils/cn';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { superAdminApi } from '@/features/super-admin/api/superAdminApi';

export interface AdminLayoutProps {
  children: React.ReactNode;
  title?: string;
  activeNav?: string;
  onNavChange?: (tab: string) => void;
}

interface AdminNavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string; strokeWidth?: number }>;
  id: string;
}

const ADMIN_NAV_ITEMS: AdminNavItem[] = [
  { name: 'Overview', href: '/admin/dashboard', icon: LayoutDashboard, id: 'overview' },
  { name: 'Tenants', href: '/admin/tenants', icon: Building2, id: 'tenants' },
  { name: 'Packages', href: '/admin/packages', icon: Package, id: 'packages' },
  { name: 'Subscriptions', href: '/admin/subscriptions', icon: CreditCard, id: 'subscriptions' },
  { name: 'Feature Flags', href: '/admin/feature-flags', icon: Flag, id: 'feature-flags' },
];

export function AdminLayout({
  children,
  title,
  activeNav = 'overview',
  onNavChange,
}: AdminLayoutProps) {
  const handleLogout = async () => {
    try {
      await superAdminApi.logout();
    } finally {
      window.location.href = '/admin/login';
    }
  };

  return (
    <div className="flex h-screen w-full overflow-hidden bg-background text-foreground">
      {/* Central Super Admin Sidebar */}
      <aside className="flex w-64 flex-col bg-sidebar text-sidebar-foreground border-r border-sidebar-foreground/10">
        <div className="flex h-16 items-center justify-between px-6 border-b border-sidebar-foreground/10">
          <div className="flex items-center gap-2">
            <ShieldAlert className="h-6 w-6 text-primary" strokeWidth={1.5} />
            <span className="text-lg font-bold tracking-tight text-sidebar-foreground">
              Super<span className="text-primary">Admin</span>
            </span>
          </div>
          <Badge variant="outline" className="text-[10px] px-1.5 py-0.5 uppercase tracking-wide border-primary/30 text-primary">
            Central
          </Badge>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
          {ADMIN_NAV_ITEMS.map((item) => {
            const isActive = item.id === activeNav;
            const Icon = item.icon;
            return (
              <button
                key={item.id}
                type="button"
                onClick={() => {
                  if (onNavChange) {
                    onNavChange(item.id);
                  } else {
                    window.location.href = item.href;
                  }
                }}
                className={cn(
                  'w-full text-left flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-md transition-colors',
                  isActive
                    ? 'border-l-4 border-primary bg-primary/15 text-sidebar-foreground font-semibold pl-2'
                    : 'text-sidebar-foreground/80 hover:bg-sidebar-foreground/10 hover:text-sidebar-foreground'
                )}
              >
                <Icon className="h-5 w-5 shrink-0" strokeWidth={1.5} />
                <span>{item.name}</span>
              </button>
            );
          })}
        </nav>

        <div className="p-4 border-t border-sidebar-foreground/10">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={handleLogout}
            className="w-full justify-start text-sidebar-foreground/80 hover:text-sidebar-foreground hover:bg-sidebar-foreground/10 gap-2"
          >
            <LogOut className="h-4 w-4" />
            <span>Sign Out</span>
          </Button>
        </div>
      </aside>

      <div className="flex flex-1 flex-col overflow-hidden">
        <header className="flex h-16 items-center justify-between border-b border-border bg-card px-8">
          <div className="flex items-center gap-2">
            <span className="text-sm font-semibold text-foreground">
              Nexus Platform Controller
            </span>
            <span className="text-xs text-muted-foreground">
              (Multi-Tenant Central Console)
            </span>
          </div>
          <div className="flex items-center gap-3">
            <Badge variant="outline" className="text-xs">
              Platform Admin
            </Badge>
          </div>
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
