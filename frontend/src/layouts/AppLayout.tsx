import React from 'react';
import {
  LayoutDashboard,
  Package,
  ShoppingCart,
  FileText,
  Users,
  BookOpen,
  BarChart3,
  Settings,
  Bell,
  Search,
  User,
  Menu,
} from 'lucide-react';
import { cn } from '@/shared/utils/cn';

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

export interface AppLayoutProps {
  children: React.ReactNode;
  title?: string;
  breadcrumbs?: BreadcrumbItem[];
  activeNav?: string;
  tenantName?: string;
}

interface NavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string; strokeWidth?: number }>;
  id: string;
}

const NAV_ITEMS: NavItem[] = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, id: 'dashboard' },
  { name: 'Inventory', href: '/inventory', icon: Package, id: 'inventory' },
  { name: 'Sales', href: '/sales', icon: ShoppingCart, id: 'sales' },
  { name: 'Purchasing', href: '/purchasing', icon: FileText, id: 'purchasing' },
  { name: 'Contacts', href: '/contacts', icon: Users, id: 'contacts' },
  { name: 'Accounting', href: '/accounting', icon: BookOpen, id: 'accounting' },
  { name: 'Reports', href: '/reports', icon: BarChart3, id: 'reports' },
  { name: 'Settings', href: '/settings', icon: Settings, id: 'settings' },
];

export function AppLayout({
  children,
  title,
  breadcrumbs = [{ label: 'Dashboard' }],
  activeNav = 'dashboard',
  tenantName = 'Acme Corp',
}: AppLayoutProps) {
  const [mobileMenuOpen, setMobileMenuOpen] = React.useState(false);

  return (
    <div className="flex h-screen w-full overflow-hidden bg-background text-foreground">
      {/* Mobile sidebar overlay */}
      {mobileMenuOpen && (
        <div
          role="presentation"
          className="fixed inset-0 z-40 bg-foreground/50 md:hidden"
          onClick={() => setMobileMenuOpen(false)}
        />
      )}

      {/* Sidebar: 256px, royal blue palette */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-sidebar text-sidebar-foreground transition-transform duration-200 md:static md:translate-x-0',
          mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        {/* Brand / Logo */}
        <div className="flex h-16 items-center px-6 border-b border-sidebar-foreground/10">
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded bg-primary text-primary-foreground font-bold text-base">
              N
            </div>
            <span className="text-xl font-bold tracking-tight text-sidebar-foreground">
              Nexus<span className="text-primary">ERP</span>
            </span>
          </div>
        </div>

        {/* Navigation list */}
        <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
          {NAV_ITEMS.map((item) => {
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

        {/* User / Tenant badge in sidebar footer */}
        <div className="p-4 border-t border-sidebar-foreground/10">
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-sidebar-foreground/15 text-sidebar-foreground">
              <User className="h-5 w-5" strokeWidth={1.5} />
            </div>
            <div className="flex flex-col overflow-hidden">
              <span className="text-sm font-semibold truncate text-sidebar-foreground">
                {tenantName}
              </span>
              <span className="text-xs text-sidebar-foreground/60 truncate">
                tenant_owner
              </span>
            </div>
          </div>
        </div>
      </aside>

      {/* Main layout container */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Top bar */}
        <header className="flex h-16 items-center justify-between border-b border-border bg-card px-4 md:px-8">
          <div className="flex items-center gap-4">
            <button
              type="button"
              className="md:hidden p-2 rounded-md hover:bg-muted"
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              aria-label="Toggle navigation"
            >
              <Menu className="h-5 w-5" strokeWidth={1.5} />
            </button>

            {/* Breadcrumbs */}
            <nav aria-label="Breadcrumb" className="flex items-center space-x-2 text-sm">
              {breadcrumbs.map((crumb, idx) => (
                <React.Fragment key={crumb.label}>
                  {idx > 0 && <span className="text-muted-foreground">/</span>}
                  {crumb.href ? (
                    <a
                      href={crumb.href}
                      className="text-muted-foreground hover:text-foreground transition-colors"
                    >
                      {crumb.label}
                    </a>
                  ) : (
                    <span className="font-semibold text-foreground">
                      {crumb.label}
                    </span>
                  )}
                </React.Fragment>
              ))}
            </nav>
          </div>

          {/* Top bar actions */}
          <div className="flex items-center gap-3">
            {/* Search shortcut button */}
            <button
              type="button"
              className="hidden sm:flex items-center gap-2 rounded-md border border-border bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted transition-colors"
            >
              <Search className="h-3.5 w-3.5" strokeWidth={1.5} />
              <span>Search...</span>
              <kbd className="rounded border border-border bg-card px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                ⌘K
              </kbd>
            </button>

            {/* Notifications */}
            <button
              type="button"
              className="relative p-2 rounded-md hover:bg-muted text-foreground transition-colors"
              aria-label="Notifications"
            >
              <Bell className="h-5 w-5" strokeWidth={1.5} />
            </button>
          </div>
        </header>

        {/* Page Content area */}
        <main className="flex-1 overflow-y-auto bg-background">
          <div className="mx-auto max-w-[1440px] px-4 py-4 md:px-8 md:py-8">
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
