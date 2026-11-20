import { Package, BookOpen, ShieldCheck, FileSpreadsheet, Check } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

export function OperationalPillars() {
  return (
    <section id="architecture" className="py-16 sm:py-24 border-b border-border bg-card">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {/* Section Header: Left-Aligned with Tight Typography */}
        <div className="max-w-2xl text-left space-y-3 mb-16">
          <Badge variant="outline" className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
            Architectural Guarantees
          </Badge>
          <h2 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground leading-[1.08]">
            Four core subsystems built for operational auditability.
          </h2>
          <p className="text-base text-muted-foreground">
            Unlike generic CRUD dashboards, NexusERP enforces strict accounting invariants and warehouse stock controls at the database and transactional layer.
          </p>
        </div>

        {/* Asymmetric 2-Column Splits (No generic 3-column bento grid) */}
        <div className="space-y-12">
          {/* Pair 1: Inventory AVCO + General Ledger */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
            {/* Column A: Multi-Warehouse & AVCO (7 cols) */}
            <div className="lg:col-span-7 rounded-lg border border-border bg-background p-6 sm:p-8 flex flex-col justify-between">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex h-10 w-10 items-center justify-center rounded bg-secondary text-secondary-foreground">
                    <Package className="h-5 w-5" strokeWidth={1.5} />
                  </div>
                  <span className="font-mono text-xs text-muted-foreground uppercase">Subsystem 01</span>
                </div>
                <h3 className="font-display text-2xl font-bold tracking-tight text-foreground">
                  Multi-Warehouse Inventory & Continuous AVCO Costing
                </h3>
                <p className="text-sm text-muted-foreground leading-relaxed">
                  Every Goods Receipt recalculates unit cost dynamically using moving weighted averages. Stock is tracked across independent physical warehouses, bins, and quarantine zones without inventory drift.
                </p>

                {/* Subsystem Details List */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-3">
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Real-time SSE stock alert telemetry</span>
                  </div>
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>FIFO batch tracking with unit of measure</span>
                  </div>
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Warehouse-to-warehouse stock transfers</span>
                  </div>
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Immediate COGS posting on invoice dispatch</span>
                  </div>
                </div>
              </div>

              {/* Data Snapshot Card */}
              <div className="mt-6 rounded border border-border bg-card p-3.5 font-mono text-xs">
                <div className="flex justify-between text-muted-foreground border-b border-border/20 pb-1.5 mb-1.5">
                  <span>SKU: IND-VALVE-500</span>
                  <span className="text-success font-semibold">ON-HAND: 1,420 PCS</span>
                </div>
                <div className="flex justify-between text-foreground">
                  <span>Weighted Average Cost:</span>
                  <span className="font-bold tabular-nums">$42.85 / unit</span>
                </div>
              </div>
            </div>

            {/* Column B: General Ledger & Double Entry (5 cols) */}
            <div className="lg:col-span-5 rounded-lg border border-border bg-background p-6 sm:p-8 flex flex-col justify-between">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex h-10 w-10 items-center justify-center rounded bg-secondary text-secondary-foreground">
                    <BookOpen className="h-5 w-5" strokeWidth={1.5} />
                  </div>
                  <span className="font-mono text-xs text-muted-foreground uppercase">Subsystem 02</span>
                </div>
                <h3 className="font-display text-2xl font-bold tracking-tight text-foreground">
                  Zero-Imbalance General Ledger
                </h3>
                <p className="text-sm text-muted-foreground leading-relaxed">
                  All transactions compile into balanced double-entry journal entries. Imbalanced vouchers are rejected by database triggers before committing.
                </p>

                <div className="space-y-2 pt-2 text-xs text-muted-foreground">
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                    <span>Immutable posted ledger vouchers</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                    <span>Credit & Debit note reversal trails</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                    <span>Real-time Trial Balance equilibrium</span>
                  </div>
                </div>
              </div>

              <div className="mt-6 rounded border border-border bg-card p-3 font-mono text-xs flex items-center justify-between">
                <span className="text-muted-foreground">Journal Integrity Check:</span>
                <Badge variant="success" className="text-[10px]">
                  DEBITS = CREDITS (STRICT)
                </Badge>
              </div>
            </div>
          </div>

          {/* Pair 2: Tenancy Isolation + VAT Reporting */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
            {/* Column C: Database-Per-Tenant Isolation (5 cols) */}
            <div className="lg:col-span-5 rounded-lg border border-border bg-background p-6 sm:p-8 flex flex-col justify-between">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex h-10 w-10 items-center justify-center rounded bg-secondary text-secondary-foreground">
                    <ShieldCheck className="h-5 w-5" strokeWidth={1.5} />
                  </div>
                  <span className="font-mono text-xs text-muted-foreground uppercase">Subsystem 03</span>
                </div>
                <h3 className="font-display text-2xl font-bold tracking-tight text-foreground">
                  Database-Per-Tenant Isolation
                </h3>
                <p className="text-sm text-muted-foreground leading-relaxed">
                  Every tenant operates on dedicated PostgreSQL database connections. Shared table `tenant_id` leakage risks are eliminated by design.
                </p>

                <div className="space-y-2 pt-2 text-xs text-muted-foreground">
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                    <span>Non-superuser least-privilege provisioner</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                    <span>SHA-256 automated backup verification</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                    <span>Independent schema migrations per tenant</span>
                  </div>
                </div>
              </div>

              <div className="mt-6 rounded border border-border bg-card p-3 font-mono text-xs flex items-center justify-between">
                <span className="text-muted-foreground">Tenant Connection:</span>
                <span className="font-bold text-foreground">pg_tenant_{'{id}'} (ISOLATED)</span>
              </div>
            </div>

            {/* Column D: VAT Returns & Fiscal Periods (7 cols) */}
            <div className="lg:col-span-7 rounded-lg border border-border bg-background p-6 sm:p-8 flex flex-col justify-between">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex h-10 w-10 items-center justify-center rounded bg-secondary text-secondary-foreground">
                    <FileSpreadsheet className="h-5 w-5" strokeWidth={1.5} />
                  </div>
                  <span className="font-mono text-xs text-muted-foreground uppercase">Subsystem 04</span>
                </div>
                <h3 className="font-display text-2xl font-bold tracking-tight text-foreground">
                  Audit-Proof VAT Returns & Fiscal Period Control
                </h3>
                <p className="text-sm text-muted-foreground leading-relaxed">
                  Generate statutory VAT returns with complete line-by-line drilldown into tax invoices, credit notes, and supplier bills. Close fiscal periods with irrevocable lock stamps.
                </p>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-3">
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Box 1–9 automated VAT schedules</span>
                  </div>
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Lockable monthly/quarterly periods</span>
                  </div>
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Background PDF & Excel exports</span>
                  </div>
                  <div className="flex items-start gap-2 text-xs">
                    <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                    <span>Immutable transaction audit trails</span>
                  </div>
                </div>
              </div>

              <div className="mt-6 rounded border border-border bg-card p-3.5 font-mono text-xs">
                <div className="flex justify-between text-muted-foreground border-b border-border/20 pb-1.5 mb-1.5">
                  <span>FISCAL PERIOD: Q4-2026</span>
                  <Badge variant="outline" className="text-[10px] text-foreground">
                    LOCKED (IMMUTABLE)
                  </Badge>
                </div>
                <div className="flex justify-between text-foreground">
                  <span>Net VAT Payable:</span>
                  <span className="font-bold tabular-nums">$14,280.00</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
