import { ArrowRight, Shield, Database, Terminal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { LedgerPreviewCard } from './LedgerPreviewCard';

export interface HeroSectionProps {
  onOpenDemo?: () => void;
}

export function HeroSection({ onOpenDemo }: HeroSectionProps) {
  return (
    <section className="relative overflow-hidden pt-12 pb-16 lg:pt-20 lg:pb-24 border-b border-border bg-background">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
          {/* Left Column: Left-Aligned Operational Value Proposition */}
          <div className="lg:col-span-6 space-y-6 text-left animate-fade-up">
            {/* Architectural Status Tag */}
            <div className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1 shadow-xs">
              <span className="h-2 w-2 rounded-full bg-success animate-pulse" />
              <span className="font-mono text-xs font-medium text-foreground tracking-tight">
                NexusERP Architecture v1.0.0 · Production Ready
              </span>
            </div>

            {/* Display Headline */}
            <h1 className="font-display text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-foreground leading-[1.08]">
              Multi-tenant ERP with atomic inventory & zero-imbalance ledger.
            </h1>

            {/* Value Summary (Domain-Authentic Copywriting, Banned Words Excluded) */}
            <p className="text-base sm:text-lg text-muted-foreground leading-relaxed max-w-xl font-normal">
              Engineered for mid-market distributors and manufacturers requiring absolute database-per-tenant isolation, continuous AVCO inventory reconciliation across multiple warehouses, and strict double-entry General Ledger equilibrium.
            </p>

            {/* Operational Metrics Strip */}
            <div className="grid grid-cols-3 gap-4 border-y border-border py-4 font-mono">
              <div>
                <div className="text-xl sm:text-2xl font-bold text-foreground tabular-nums">0.00</div>
                <div className="text-[11px] text-muted-foreground uppercase tracking-wider font-sans">
                  GL Imbalance Tolerance
                </div>
              </div>
              <div>
                <div className="text-xl sm:text-2xl font-bold text-foreground tabular-nums">100%</div>
                <div className="text-[11px] text-muted-foreground uppercase tracking-wider font-sans">
                  Database Isolation
                </div>
              </div>
              <div>
                <div className="text-xl sm:text-2xl font-bold text-foreground tabular-nums">&lt;350ms</div>
                <div className="text-[11px] text-muted-foreground uppercase tracking-wider font-sans">
                  Disaster Recovery RTO
                </div>
              </div>
            </div>

            {/* CTAs */}
            <div className="flex flex-wrap items-center gap-4 pt-2">
              <Button
                variant="primary"
                size="lg"
                onClick={onOpenDemo}
                className="group font-semibold tracking-wide text-sm h-11 px-6 shadow-sm"
              >
                <span>Provision Test Workspace</span>
                <ArrowRight className="ml-2 h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" />
              </Button>

              <a
                href="/login"
                className="inline-flex items-center justify-center h-11 px-6 rounded-md border border-border bg-card text-foreground text-sm font-semibold hover:bg-muted transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                Sign In to Tenant
              </a>
            </div>

            {/* Compliance / Specification Badges */}
            <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground pt-2">
              <span className="flex items-center gap-1.5">
                <Database className="h-3.5 w-3.5 text-secondary" />
                PostgreSQL Physical Isolation
              </span>
              <span className="flex items-center gap-1.5">
                <Shield className="h-3.5 w-3.5 text-success" />
                Spatie RBAC Guarded
              </span>
              <span className="flex items-center gap-1.5">
                <Terminal className="h-3.5 w-3.5 text-foreground" />
                Integer Cents Precision
              </span>
            </div>
          </div>

          {/* Right Column: Live Architectural Ledger & Inventory Preview */}
          <div className="lg:col-span-6 animate-fade-up">
            <div className="relative">
              {/* Architectural Label */}
              <div className="mb-2 flex items-center justify-between text-xs text-muted-foreground font-mono">
                <span>INTERACTIVE SUBSYSTEM MONITOR</span>
                <span>DB: tenant_prod_01</span>
              </div>
              <LedgerPreviewCard />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
