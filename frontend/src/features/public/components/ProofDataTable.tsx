import { Check, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

interface ComparisonRow {
  dimension: string;
  category: string;
  nexusErp: {
    status: 'pass' | 'optimal';
    text: string;
    detail: string;
  };
  legacyStack: {
    status: 'fail' | 'partial';
    text: string;
    detail: string;
  };
}

const COMPARISON_DATA: ComparisonRow[] = [
  {
    dimension: 'Tenancy Isolation Architecture',
    category: 'Security & Compliance',
    nexusErp: {
      status: 'optimal',
      text: 'Physical Database-Per-Tenant',
      detail: 'Runtime connection switching ensures zero cross-tenant query contamination or shared table leaks.',
    },
    legacyStack: {
      status: 'fail',
      text: 'Shared Database with tenant_id Column',
      detail: 'A single misplaced WHERE clause can expose competitor inventory, customer lists, and pricing.',
    },
  },
  {
    dimension: 'Inventory Costing & AVCO',
    category: 'Supply Chain',
    nexusErp: {
      status: 'optimal',
      text: 'Continuous Moving Weighted Average',
      detail: 'Every Goods Receipt recalculates unit valuation in real-time, matching COGS to invoice dispatch.',
    },
    legacyStack: {
      status: 'fail',
      text: 'Periodic Batch / Manual Estimates',
      detail: 'Cost discrepancies accumulate until quarterly stock takes, distorting gross margin reports.',
    },
  },
  {
    dimension: 'General Ledger Invariant',
    category: 'Accounting',
    nexusErp: {
      status: 'optimal',
      text: 'Strict Zero-Imbalance Atomic Commits',
      detail: 'Every voucher requires debits = credits before commit. Imbalanced postings are rejected.',
    },
    legacyStack: {
      status: 'fail',
      text: 'Loose External Webhook Sync',
      detail: 'Failed webhook calls leave invoices disconnected from GL, causing balance sheet drift.',
    },
  },
  {
    dimension: 'Currency & Fractional Precision',
    category: 'Data Integrity',
    nexusErp: {
      status: 'optimal',
      text: 'Integer Minor Units (cents BIGINT)',
      detail: 'Zero IEEE-754 float rounding errors. Strict half-up rounding on line taxes.',
    },
    legacyStack: {
      status: 'fail',
      text: 'Floating-Point Numbers (FLOAT / DOUBLE)',
      detail: 'Accumulated fractions of cents create phantom variances in VAT returns and ledger totals.',
    },
  },
  {
    dimension: 'Disaster Recovery Validation',
    category: 'Reliability',
    nexusErp: {
      status: 'optimal',
      text: 'Automated Restore Drills (RTO < 350ms)',
      detail: 'Automated test suite validates SHA-256 checksums and table counts against live restore.',
    },
    legacyStack: {
      status: 'fail',
      text: 'Untested Monolithic Snapshots',
      detail: 'Restores take hours and restore the entire cluster, affecting all organizations simultaneously.',
    },
  },
  {
    dimension: 'Operational Stock Telemetry',
    category: 'Real-Time',
    nexusErp: {
      status: 'optimal',
      text: 'Server-Sent Events (SSE) Stream',
      detail: 'Low-stock warnings push to warehouse terminal screens within 100ms of threshold breach.',
    },
    legacyStack: {
      status: 'partial',
      text: 'Delayed Cron / Scheduled Email Alerts',
      detail: 'Stockouts are noticed hours after sales reps confirm orders that cannot be fulfilled.',
    },
  },
  {
    dimension: 'Audit Trail Permanence',
    category: 'Governance',
    nexusErp: {
      status: 'optimal',
      text: 'Immutable Append-Only Audit Log',
      detail: 'Every document post, void, and role mutation is logged with SHA-256 user stamp.',
    },
    legacyStack: {
      status: 'fail',
      text: 'Overwritable Database Updates',
      detail: 'Direct edits overwrite previous record states with no historical trace of alterations.',
    },
  },
];

export function ProofDataTable() {
  return (
    <section id="comparison" className="py-16 sm:py-24 border-b border-border bg-card">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="max-w-3xl text-left space-y-3 mb-12">
          <Badge variant="outline" className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
            Architectural Differentiation
          </Badge>
          <h2 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground leading-[1.08]">
            Built as a unified operational core, not a patch of SaaS plugins.
          </h2>
          <p className="text-base text-muted-foreground">
            A line-by-line engineering audit comparing NexusERP's architecture with fragmented SMB spreadsheets and single-database multi-tenant setups.
          </p>
        </div>

        {/* Structured Data Table */}
        <div className="overflow-hidden rounded-lg border border-border bg-background shadow-xs">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/60 text-xs font-mono uppercase tracking-wider text-muted-foreground">
                  <th scope="col" className="px-6 py-4 font-semibold">Operational Dimension</th>
                  <th scope="col" className="px-6 py-4 font-semibold text-foreground bg-primary/10">
                    NexusERP Multi-Tenant Core
                  </th>
                  <th scope="col" className="px-6 py-4 font-semibold">
                    Fragmented Tools / Shared-DB SaaS
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/30">
                {COMPARISON_DATA.map((row, idx) => (
                  <tr key={idx} className="hover:bg-card transition-colors">
                    <td className="px-6 py-4.5 align-top">
                      <div className="font-semibold text-foreground">{row.dimension}</div>
                      <span className="font-mono text-[11px] text-muted-foreground uppercase">
                        {row.category}
                      </span>
                    </td>
                    <td className="px-6 py-4.5 align-top bg-primary/5">
                      <div className="flex items-center gap-2 font-semibold text-foreground mb-1">
                        <Check className="h-4 w-4 text-success shrink-0" />
                        <span>{row.nexusErp.text}</span>
                      </div>
                      <p className="text-xs text-muted-foreground leading-relaxed pl-6">
                        {row.nexusErp.detail}
                      </p>
                    </td>
                    <td className="px-6 py-4.5 align-top">
                      <div className="flex items-center gap-2 font-medium text-destructive mb-1">
                        <X className="h-4 w-4 shrink-0" />
                        <span>{row.legacyStack.text}</span>
                      </div>
                      <p className="text-xs text-muted-foreground leading-relaxed pl-6">
                        {row.legacyStack.detail}
                      </p>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  );
}
