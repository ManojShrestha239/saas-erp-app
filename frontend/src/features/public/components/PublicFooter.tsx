export function PublicFooter() {
  return (
    <footer className="border-t border-border bg-background text-foreground py-12 lg:py-16">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-8 pb-12 border-b border-border/40">
          {/* Brand Info */}
          <div className="md:col-span-2 space-y-4">
            <div className="flex items-center gap-2">
              <div className="flex h-7 w-7 items-center justify-center rounded bg-primary text-primary-foreground font-display font-bold text-sm">
                N
              </div>
              <span className="font-display text-lg font-bold tracking-tight text-foreground">
                Nexus<span className="text-secondary font-black">ERP</span>
              </span>
            </div>
            <p className="text-xs text-muted-foreground leading-relaxed max-w-sm">
              Multi-tenant enterprise resource planning platform engineered for distributors and manufacturers. Built on database-per-tenant isolation, continuous AVCO inventory reconciliation, and zero-imbalance General Ledger.
            </p>

            <div className="inline-flex items-center gap-2 rounded border border-border bg-card px-3 py-1.5 text-xs font-mono">
              <span className="h-2 w-2 rounded-full bg-success" />
              <span className="text-foreground">System Status:</span>
              <span className="text-muted-foreground">All Subsystems Operational</span>
              <span className="text-muted-foreground">· RTO 326ms</span>
            </div>
          </div>

          {/* Subsystems Navigation */}
          <div className="space-y-3">
            <span className="font-mono text-xs uppercase tracking-wider text-foreground font-semibold">
              ERP Subsystems
            </span>
            <ul className="space-y-2 text-xs text-muted-foreground">
              <li>
                <a href="#architecture" className="hover:text-foreground transition-colors">
                  Multi-Warehouse Inventory & AVCO
                </a>
              </li>
              <li>
                <a href="#workflows" className="hover:text-foreground transition-colors">
                  Atomic General Ledger Commits
                </a>
              </li>
              <li>
                <a href="#architecture" className="hover:text-foreground transition-colors">
                  Purchasing & 3-Way Bill Matching
                </a>
              </li>
              <li>
                <a href="#workflows" className="hover:text-foreground transition-colors">
                  Commercial Invoicing & Cash Receipts
                </a>
              </li>
              <li>
                <a href="#comparison" className="hover:text-foreground transition-colors">
                  Statutory VAT Returns & Fiscal Periods
                </a>
              </li>
            </ul>
          </div>

          {/* Security & Access */}
          <div className="space-y-3">
            <span className="font-mono text-xs uppercase tracking-wider text-foreground font-semibold">
              Access & Security
            </span>
            <ul className="space-y-2 text-xs text-muted-foreground">
              <li>
                <a href="/login" className="hover:text-foreground transition-colors font-medium text-foreground">
                  Tenant Workspace Sign In
                </a>
              </li>
              <li>
                <a href="/admin/login" className="hover:text-foreground transition-colors">
                  Central SuperAdmin Console
                </a>
              </li>
              <li>
                <a href="#comparison" className="hover:text-foreground transition-colors">
                  PostgreSQL Least-Privilege Specs
                </a>
              </li>
              <li>
                <a href="#faq" className="hover:text-foreground transition-colors">
                  Disaster Recovery & Restore Drills
                </a>
              </li>
            </ul>
          </div>
        </div>

        {/* Bottom Credits & Tech Stack Info */}
        <div className="pt-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs font-mono text-muted-foreground">
          <div>
            &copy; {new Date().getFullYear()} NexusERP SaaS Platform. All rights reserved.
          </div>
          <div className="flex items-center gap-4">
            <span>PHP 8.4 Strict</span>
            <span>·</span>
            <span>PostgreSQL 16 Multi-DB</span>
            <span>·</span>
            <span>React 19 + Inertia v2</span>
          </div>
        </div>
      </div>
    </footer>
  );
}
