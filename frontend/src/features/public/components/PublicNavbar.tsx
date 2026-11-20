import * as React from 'react';
import { Menu, ArrowRight, ShieldCheck, Database } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetHeader, SheetTitle, SheetBody } from '@/components/ui/sheet';

export interface PublicNavbarProps {
  onOpenDemo?: () => void;
}

export function PublicNavbar({ onOpenDemo }: PublicNavbarProps) {
  const [mobileOpen, setMobileOpen] = React.useState(false);

  const navLinks = [
    { label: 'Architecture', href: '#architecture' },
    { label: 'Dual-Engine Workflows', href: '#workflows' },
    { label: 'Technical Comparison', href: '#comparison' },
    { label: 'Tenant Tiers', href: '#pricing' },
    { label: 'System FAQ', href: '#faq' },
  ];

  return (
    <header className="sticky top-0 z-40 w-full border-b border-border bg-card/90 backdrop-blur-xs transition-colors">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        {/* Brand Lockup */}
        <a href="/" className="flex items-center gap-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md p-1">
          <div className="flex h-8 w-8 items-center justify-center rounded bg-primary text-primary-foreground font-display font-bold text-base shadow-xs">
            N
          </div>
          <div className="flex flex-col">
            <span className="font-display text-lg font-bold tracking-tight text-foreground">
              Nexus<span className="text-secondary font-black">ERP</span>
            </span>
            <span className="text-[10px] uppercase font-mono tracking-wider text-muted-foreground -mt-1">
              Multi-Tenant Cloud
            </span>
          </div>
        </a>

        {/* Desktop Navigation */}
        <nav aria-label="Main Navigation" className="hidden md:flex items-center gap-7">
          {navLinks.map((link) => (
            <a
              key={link.href}
              href={link.href}
              className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground relative py-1 after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 after:bg-primary after:transition-all hover:after:w-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-xs"
            >
              {link.label}
            </a>
          ))}
        </nav>

        {/* Action CTAs */}
        <div className="hidden sm:flex items-center gap-3">
          <a
            href="/login"
            className="text-sm font-semibold text-foreground px-3.5 py-2 rounded-md hover:bg-muted transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Sign In
          </a>
          <Button
            variant="primary"
            size="default"
            onClick={onOpenDemo}
            className="group font-semibold text-xs tracking-wide uppercase px-4 h-9"
          >
            <span>Deploy Workspace</span>
            <ArrowRight className="ml-1.5 h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" />
          </Button>
        </div>

        {/* Mobile Menu Trigger */}
        <button
          type="button"
          onClick={() => setMobileOpen(true)}
          className="md:hidden p-2 rounded-md text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label="Open mobile navigation menu"
        >
          <Menu className="h-5 w-5" />
        </button>
      </div>

      {/* Mobile Drawer */}
      <Sheet open={mobileOpen} onOpenChange={setMobileOpen} side="left">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <span className="flex h-7 w-7 items-center justify-center rounded bg-primary text-primary-foreground font-bold text-xs">
              N
            </span>
            <span>NexusERP Platform</span>
          </SheetTitle>
        </SheetHeader>
        <SheetBody className="space-y-4 pt-4">
          <div className="space-y-1">
            {navLinks.map((link) => (
              <a
                key={link.href}
                href={link.href}
                onClick={() => setMobileOpen(false)}
                className="block px-3 py-2.5 rounded-md text-sm font-medium text-foreground hover:bg-muted transition-colors"
              >
                {link.label}
              </a>
            ))}
          </div>

          <div className="border-t border-border pt-4 space-y-2">
            <div className="flex items-center gap-2 text-xs text-muted-foreground px-3 py-1">
              <ShieldCheck className="h-4 w-4 text-success" />
              <span>PostgreSQL Connection Isolation</span>
            </div>
            <div className="flex items-center gap-2 text-xs text-muted-foreground px-3 py-1">
              <Database className="h-4 w-4 text-secondary" />
              <span>Zero-Imbalance General Ledger</span>
            </div>
          </div>

          <div className="pt-4 flex flex-col gap-2">
            <a
              href="/login"
              className="w-full text-center py-2.5 text-sm font-semibold rounded-md border border-border bg-card text-foreground hover:bg-muted transition-colors"
            >
              Sign In to Workspace
            </a>
            <Button
              variant="primary"
              className="w-full justify-center"
              onClick={() => {
                setMobileOpen(false);
                onOpenDemo?.();
              }}
            >
              Request Operational Sandbox
            </Button>
          </div>
        </SheetBody>
      </Sheet>
    </header>
  );
}
