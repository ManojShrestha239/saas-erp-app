import * as React from 'react';
import { Shield } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/shared/utils/cn';

interface WorkflowStep {
  id: string;
  stageNumber: string;
  name: string;
  subsystem: 'Purchasing' | 'Inventory' | 'Sales' | 'Accounting' | 'Banking';
  documentNumber: string;
  summary: string;
  dbMutation: string;
  glPosting: string;
  auditEvidence: string;
}

const WORKFLOW_STEPS: WorkflowStep[] = [
  {
    id: 'po',
    stageNumber: '01',
    name: 'Purchase Order Approval',
    subsystem: 'Purchasing',
    documentNumber: 'PO-2026-00412',
    summary: 'Supplier quote authorized with payment terms Net 30 and designated warehouse delivery.',
    dbMutation: 'Status: APPROVED. Zero inventory changes, commitment registered.',
    glPosting: 'No GL impact until Goods Receipt or Supplier Bill verification.',
    auditEvidence: 'User: Purchasing Officer · Role: purchases.create · Hash verified',
  },
  {
    id: 'grn',
    stageNumber: '02',
    name: 'Goods Receipt & AVCO Costing',
    subsystem: 'Inventory',
    documentNumber: 'GRN-2026-00319',
    summary: 'Physical warehouse gate inspection verifies 50 units. On-hand quantity incremented immediately.',
    dbMutation: 'Stock: +50 units at Warehouse WH-01. Unit AVCO recalculated from $162.00 to $170.00.',
    glPosting: 'Debit: 1300 Inventory Asset ($8,500.00) · Credit: 2050 GRNI Accrual ($8,500.00)',
    auditEvidence: 'Warehouse Manager · Quality inspection signed · Serial batches logged',
  },
  {
    id: 'bill',
    stageNumber: '03',
    name: 'Supplier Bill Matching',
    subsystem: 'Purchasing',
    documentNumber: 'BILL-2026-00192',
    summary: '3-way match reconciles PO price, GRN received quantities, and vendor invoice figures.',
    dbMutation: 'Status: POSTED. Accounts Payable open ledger created.',
    glPosting: 'Debit: 2050 GRNI Accrual ($8,500.00) · Credit: 2000 Accounts Payable ($8,500.00)',
    auditEvidence: 'Accountant · 3-way match verified · Due date locked for automated payment',
  },
  {
    id: 'invoice',
    stageNumber: '04',
    name: 'Customer Invoice Posting',
    subsystem: 'Sales',
    documentNumber: 'INV-2026-00104',
    summary: 'Commercial invoice generated with automated VAT calculation and immediate stock reservation decrement.',
    dbMutation: 'Stock: -10 units at WH-01. Customer balance updated atomically.',
    glPosting: 'Debit: 1100 AR ($5,750.00) · Credit: 4000 Sales ($5,000.00) · Credit: 2100 VAT ($750.00)',
    auditEvidence: 'Sales Rep · PDF rendered via Browsershot · Signed ledger stamp',
  },
  {
    id: 'cogs',
    stageNumber: '05',
    name: 'Atomic COGS Recognition',
    subsystem: 'Accounting',
    documentNumber: 'JRN-2026-00781',
    summary: 'Matching principle recognizes Cost of Goods Sold synchronously with revenue invoice creation.',
    dbMutation: 'Valuation reduced by 10 units @ $170.00 AVCO ($1,700.00).',
    glPosting: 'Debit: 5000 Cost of Goods Sold ($1,700.00) · Credit: 1300 Inventory Asset ($1,700.00)',
    auditEvidence: 'Automated Post-Commit Event · Zero-margin gap · Balanced',
  },
  {
    id: 'payment',
    stageNumber: '06',
    name: 'Customer Receipt & Settlement',
    subsystem: 'Banking',
    documentNumber: 'RCP-2026-00088',
    summary: 'Bank payment clears. Receipt allocated against open invoice lines with zero remaining balance.',
    dbMutation: 'Invoice status changed to PAID. Customer statement refreshed.',
    glPosting: 'Debit: 1010 Operating Bank ($5,750.00) · Credit: 1100 Accounts Receivable ($5,750.00)',
    auditEvidence: 'Treasury Officer · BACS reference logged · Ledger reconciled',
  },
];

const DEFAULT_STEP: WorkflowStep = WORKFLOW_STEPS[3]!;

export function WorkflowSimulator() {
  const [activeStepId, setActiveStepId] = React.useState<string>('invoice');
  const activeStep = WORKFLOW_STEPS.find((s) => s.id === activeStepId) ?? DEFAULT_STEP;

  return (
    <section id="workflows" className="py-16 sm:py-24 border-b border-border bg-background">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="max-w-2xl text-left space-y-3 mb-12">
          <Badge variant="outline" className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
            End-to-End Operational Lifecycle
          </Badge>
          <h2 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground leading-[1.08]">
            From procurement to bank reconciliation in six synchronized steps.
          </h2>
          <p className="text-base text-muted-foreground">
            Explore how each physical document triggers simultaneous inventory mutations, atomic double-entry ledger journals, and immutable audit logs.
          </p>
        </div>

        {/* Stepper Navigation Strip */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 mb-8">
          {WORKFLOW_STEPS.map((step) => {
            const isSelected = step.id === activeStepId;
            return (
              <button
                key={step.id}
                type="button"
                onClick={() => setActiveStepId(step.id)}
                className={cn(
                  'flex flex-col text-left p-3.5 rounded-lg border transition-all duration-200 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  isSelected
                    ? 'border-border bg-card shadow-sm border-l-4 border-l-primary font-semibold'
                    : 'border-border/60 bg-muted/40 hover:bg-card hover:border-border text-muted-foreground'
                )}
              >
                <div className="flex items-center justify-between w-full mb-1">
                  <span className="font-mono text-[10px] text-muted-foreground font-bold">
                    STEP {step.stageNumber}
                  </span>
                  <span className="font-mono text-[10px] text-foreground font-semibold">
                    {step.subsystem}
                  </span>
                </div>
                <span className="font-sans font-medium text-foreground truncate">
                  {step.name}
                </span>
                <span className="font-mono text-[11px] text-secondary mt-1">
                  {step.documentNumber}
                </span>
              </button>
            );
          })}
        </div>

        {/* Selected Step Deep Dive Panel */}
        <div className="rounded-lg border border-border bg-card p-6 sm:p-8 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4 mb-6">
            <div>
              <div className="flex items-center gap-2 mb-1">
                <Badge variant="default" className="text-xs uppercase font-mono">
                  Stage {activeStep.stageNumber} · {activeStep.subsystem}
                </Badge>
                <span className="font-mono text-xs font-bold text-secondary">
                  Ref: {activeStep.documentNumber}
                </span>
              </div>
              <h3 className="font-display text-2xl font-bold text-foreground">
                {activeStep.name}
              </h3>
            </div>
            <div className="text-right">
              <Badge variant="success" className="font-mono text-xs">
                VERIFIED TRANSACTION
              </Badge>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {/* Operational Summary */}
            <div className="space-y-2 border border-border/60 rounded p-4 bg-background">
              <span className="font-mono text-[11px] uppercase tracking-wider text-muted-foreground font-semibold block">
                Physical / Commercial Event
              </span>
              <p className="text-sm text-foreground leading-relaxed">
                {activeStep.summary}
              </p>
            </div>

            {/* Database & Stock Mutation */}
            <div className="space-y-2 border border-border/60 rounded p-4 bg-background">
              <span className="font-mono text-[11px] uppercase tracking-wider text-muted-foreground font-semibold block">
                Database & Inventory Mutation
              </span>
              <p className="font-mono text-xs text-foreground leading-relaxed">
                {activeStep.dbMutation}
              </p>
            </div>

            {/* General Ledger Balancing */}
            <div className="space-y-2 border border-border/60 rounded p-4 bg-background">
              <span className="font-mono text-[11px] uppercase tracking-wider text-muted-foreground font-semibold block">
                General Ledger Posting
              </span>
              <p className="font-mono text-xs text-secondary font-medium leading-relaxed">
                {activeStep.glPosting}
              </p>
            </div>
          </div>

          {/* Audit Verification Strip */}
          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-border/40 text-xs font-mono text-muted-foreground">
            <div className="flex items-center gap-2">
              <Shield className="h-4 w-4 text-success" />
              <span>Audit Stamp: {activeStep.auditEvidence}</span>
            </div>
            <div className="text-foreground">
              DB Connection: <span className="font-bold">tenant_db</span> · Isolation: <span className="font-bold">Serializable Safe</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
