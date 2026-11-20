import * as React from 'react';
import { CheckCircle2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/shared/utils/cn';

interface JournalLine {
  accountCode: string;
  accountName: string;
  type: 'debit' | 'credit';
  amountCents: number;
  note: string;
}

interface TransactionScenario {
  id: string;
  label: string;
  documentRef: string;
  timestamp: string;
  lines: JournalLine[];
  inventoryEffect?: {
    sku: string;
    warehouse: string;
    deltaQty: number;
    unitCostCents: number;
  };
}

const SCENARIOS: TransactionScenario[] = [
  {
    id: 'invoice',
    label: 'Posted Sales Invoice',
    documentRef: 'INV-2026-00104',
    timestamp: '2026-11-18 10:14:22 UTC',
    lines: [
      {
        accountCode: '1100',
        accountName: 'Accounts Receivable',
        type: 'debit',
        amountCents: 575000,
        note: 'Customer: Atlas Logistics Ltd',
      },
      {
        accountCode: '4000',
        accountName: 'Finished Goods Revenue',
        type: 'credit',
        amountCents: 500000,
        note: 'Wholesale Pallet SKU-8802',
      },
      {
        accountCode: '2100',
        accountName: 'Output VAT (15%)',
        type: 'credit',
        amountCents: 75000,
        note: 'Tax Authority Fiscal Registration',
      },
    ],
    inventoryEffect: {
      sku: 'SKU-8802-PLT',
      warehouse: 'Central Distribution (WH-01)',
      deltaQty: -10,
      unitCostCents: 32000,
    },
  },
  {
    id: 'receipt',
    label: 'Customer Payment Allocation',
    documentRef: 'RCP-2026-00088',
    timestamp: '2026-11-18 11:02:15 UTC',
    lines: [
      {
        accountCode: '1010',
        accountName: 'Operating Account (GBP)',
        type: 'debit',
        amountCents: 575000,
        note: 'Direct SEPA/BACS Settlement',
      },
      {
        accountCode: '1100',
        accountName: 'Accounts Receivable',
        type: 'credit',
        amountCents: 575000,
        note: 'Settles INV-2026-00104 in full',
      },
    ],
  },
  {
    id: 'avco',
    label: 'Goods Receipt & AVCO Cost Update',
    documentRef: 'GRN-2026-00319',
    timestamp: '2026-11-18 09:30:00 UTC',
    lines: [
      {
        accountCode: '1300',
        accountName: 'Inventory Valuation',
        type: 'debit',
        amountCents: 850000,
        note: '50 units @ $170.00 AVCO recalculation',
      },
      {
        accountCode: '2050',
        accountName: 'Goods Received Not Invoiced',
        type: 'credit',
        amountCents: 850000,
        note: 'PO-2026-00412 Accrual',
      },
    ],
    inventoryEffect: {
      sku: 'RAW-STL-400',
      warehouse: 'Raw Materials Yard (WH-03)',
      deltaQty: +50,
      unitCostCents: 17000,
    },
  },
];

const DEFAULT_SCENARIO: TransactionScenario = SCENARIOS[0]!;

function formatCents(cents: number): string {
  return (cents / 100).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function LedgerPreviewCard() {
  const [activeScenarioId, setActiveScenarioId] = React.useState<string>('invoice');
  const scenario = SCENARIOS.find((s) => s.id === activeScenarioId) ?? DEFAULT_SCENARIO;

  const totalDebitCents = scenario.lines
    .filter((l) => l.type === 'debit')
    .reduce((acc, curr) => acc + curr.amountCents, 0);

  const totalCreditCents = scenario.lines
    .filter((l) => l.type === 'credit')
    .reduce((acc, curr) => acc + curr.amountCents, 0);

  const isBalanced = totalDebitCents === totalCreditCents;

  return (
    <div className="w-full rounded-lg border border-border bg-card text-card-foreground shadow-md overflow-hidden">
      {/* Top Ledger Header Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/60 px-4 py-3 sm:px-6">
        <div className="flex items-center gap-2.5">
          <div className="flex h-7 w-7 items-center justify-center rounded bg-secondary text-secondary-foreground text-xs font-mono font-bold">
            GL
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-mono text-xs font-semibold text-foreground">
                {scenario.documentRef}
              </span>
              <Badge variant="success" className="text-[10px] px-1.5 py-0 font-mono tracking-tight">
                POSTED & LOCKED
              </Badge>
            </div>
            <p className="text-[11px] font-mono text-muted-foreground">{scenario.timestamp}</p>
          </div>
        </div>

        {/* Transaction Scenario Tabs */}
        <div className="flex items-center gap-1 rounded-md border border-border bg-card p-1">
          {SCENARIOS.map((s) => (
            <button
              key={s.id}
              type="button"
              onClick={() => setActiveScenarioId(s.id)}
              className={cn(
                'rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                s.id === activeScenarioId
                  ? 'bg-secondary text-secondary-foreground font-semibold shadow-xs'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted'
              )}
            >
              {s.label}
            </button>
          ))}
        </div>
      </div>

      {/* Ledger Line Items Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="border-b border-border bg-muted/30 text-muted-foreground font-mono">
              <th scope="col" className="px-4 py-2.5 font-medium">Account</th>
              <th scope="col" className="px-4 py-2.5 font-medium">Account Title & Description</th>
              <th scope="col" className="px-4 py-2.5 font-medium text-right">Debit ($)</th>
              <th scope="col" className="px-4 py-2.5 font-medium text-right">Credit ($)</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border/20 font-mono">
            {scenario.lines.map((line, idx) => (
              <tr key={idx} className="hover:bg-muted/40 transition-colors">
                <td className="px-4 py-3 font-semibold text-secondary">
                  {line.accountCode}
                </td>
                <td className="px-4 py-3">
                  <div className="font-sans font-medium text-foreground">{line.accountName}</div>
                  <div className="text-[11px] text-muted-foreground">{line.note}</div>
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground font-medium">
                  {line.type === 'debit' ? `$${formatCents(line.amountCents)}` : '—'}
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground font-medium">
                  {line.type === 'credit' ? `$${formatCents(line.amountCents)}` : '—'}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-border bg-muted/40 font-mono font-bold text-foreground">
              <td colSpan={2} className="px-4 py-2.5 text-right font-sans uppercase tracking-wider text-[11px] text-muted-foreground">
                Trial Balance Total:
              </td>
              <td className="px-4 py-2.5 text-right tabular-nums text-foreground font-semibold">
                ${formatCents(totalDebitCents)}
              </td>
              <td className="px-4 py-2.5 text-right tabular-nums text-foreground font-semibold">
                ${formatCents(totalCreditCents)}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      {/* Footer Validation & Stock Effect Banner */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-card px-4 py-3 text-xs">
        <div className="flex items-center gap-2">
          {isBalanced ? (
            <div className="flex items-center gap-1.5 text-success font-medium">
              <CheckCircle2 className="h-4 w-4" />
              <span>General Ledger Equilibrium Verified (Net Difference: $0.00)</span>
            </div>
          ) : (
            <span className="text-destructive font-medium">Imbalance Detected</span>
          )}
        </div>

        {scenario.inventoryEffect && (
          <div className="flex items-center gap-2 font-mono text-[11px] text-muted-foreground">
            <span className="font-sans text-xs">Inventory Effect:</span>
            <span className="font-semibold text-foreground">{scenario.inventoryEffect.sku}</span>
            <span
              className={cn(
                'inline-flex items-center font-bold px-1.5 py-0.5 rounded text-[10px]',
                scenario.inventoryEffect.deltaQty < 0
                  ? 'bg-warning-bg text-warning'
                  : 'bg-success-bg text-success'
              )}
            >
              {scenario.inventoryEffect.deltaQty > 0 ? '+' : ''}
              {scenario.inventoryEffect.deltaQty} units
            </span>
            <span className="text-muted-foreground">({scenario.inventoryEffect.warehouse})</span>
          </div>
        )}
      </div>
    </div>
  );
}
