import {
  Accordion,
  AccordionItem,
  AccordionTrigger,
  AccordionContent,
} from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';

interface FaqItem {
  id: string;
  question: string;
  answer: string;
}

const FAQS: FaqItem[] = [
  {
    id: 'isolation',
    question: 'How does database-per-tenant isolation prevent cross-tenant data leakage?',
    answer:
      'NexusERP provisions an independent PostgreSQL database for each customer workspace upon signup. Runtime middleware extracts the tenant subdomain, dynamically switches the default tenant connection pool, and executes queries solely within that isolated database. Because tenant tables do not co-mingle data with other organizations, SQL injection or application-layer logic bugs cannot expose records belonging to other tenants.',
  },
  {
    id: 'avco-reconciliation',
    question: 'When and how does continuous AVCO inventory costing recalculate unit cost?',
    answer:
      'Unit valuation updates in real-time upon every posted Goods Receipt (GRN). The moving weighted average formula divides (existing on-hand quantity × current AVCO + newly received quantity × purchase bill price) by total resulting on-hand stock. When customer invoices are posted, Cost of Goods Sold is immediately recognized using the prevailing AVCO, preventing year-end inventory write-down surprises.',
  },
  {
    id: 'general-ledger-immutability',
    question: 'Can posted invoices or journal vouchers be edited or deleted?',
    answer:
      'No. In compliance with GAAP/IFRS standards, posted documents in NexusERP are permanently immutable. If an error occurs, users execute an authorized Credit Note or Journal Reversal voucher. This creates compensating debit and credit entries with an audit reference back to the original document, preserving a tamper-evident financial audit trail.',
  },
  {
    id: 'disaster-recovery',
    question: 'How are tenant database backups created and verified against corruption?',
    answer:
      'Automated tenant backup routines generate standalone PostgreSQL dumps stored securely in encrypted cloud storage with SHA-256 checksums. Automated restore drill commands regularly spin up isolated sandbox containers, restore backup files, and run integrity assertions verifying table counts and ledger balances—achieving a proven Recovery Time Objective (RTO) under 350ms.',
  },
  {
    id: 'multi-currency-integer',
    question: 'Why does NexusERP use integer minor cents instead of floating-point numbers?',
    answer:
      'Standard floating-point arithmetic (IEEE-754) introduces fractional micro-rounding errors that accumulate across thousands of line items, creating discrepancies between invoices and balance sheets. NexusERP stores all monetary values as integer minor units (BIGINT cents) and applies explicit half-up rounding only at presentation, guaranteeing that debits and credits always balance to 0.00.',
  },
  {
    id: 'white-label-export',
    question: 'Can we apply our corporate logo and export ledger records for audit compliance?',
    answer:
      'Yes. Tenant settings allow uploading custom logos, configuring company legal registrations, specifying custom document sequence prefixes (e.g. INV-YYYY-######), and defining tax identification numbers. All reports (Trial Balance, P&L, Balance Sheet, Stock Valuation, VAT Return) can be exported to vectorized PDF and Excel formats on demand.',
  },
];

export function FaqAccordion() {
  return (
    <section id="faq" className="py-16 sm:py-24 border-b border-border bg-card">
      <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
        <div className="text-left space-y-3 mb-12">
          <Badge variant="outline" className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
            Operational Knowledge Base
          </Badge>
          <h2 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground leading-[1.08]">
            Frequently asked engineering & accounting questions.
          </h2>
          <p className="text-base text-muted-foreground">
            Clear technical explanations regarding database tenancy, double-entry ledger invariants, and data sovereignty.
          </p>
        </div>

        {/* WAI-ARIA Accordion */}
        <Accordion type="single" defaultValue="isolation" className="space-y-3">
          {FAQS.map((faq) => (
            <AccordionItem key={faq.id} value={faq.id} className="border border-border rounded-lg">
              <AccordionTrigger className="text-base font-semibold">
                {faq.question}
              </AccordionTrigger>
              <AccordionContent className="text-sm leading-relaxed text-muted-foreground">
                {faq.answer}
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </div>
    </section>
  );
}
