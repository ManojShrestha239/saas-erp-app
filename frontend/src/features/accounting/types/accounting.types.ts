export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

export interface AccountItem {
  id: string;
  code: string;
  name: string;
  type: AccountType;
  type_label: string;
  normal_balance: 'debit' | 'credit';
  parent_id?: string | null;
  is_system: boolean;
  system_name?: string | null;
  is_active: boolean;
  currency: string;
  description?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  parent?: AccountItem | null;
  children?: AccountItem[];
}

export type FiscalPeriodStatus = 'open' | 'locked' | 'closed';

export interface FiscalPeriodItem {
  id: string;
  name: string;
  fiscal_year: number;
  period_number: number;
  start_date: string;
  end_date: string;
  status: FiscalPeriodStatus;
  status_label: string;
  locked_at?: string | null;
  locked_by_user_id?: string | null;
  closed_at?: string | null;
  closed_by_user_id?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export type JournalEntryStatus = 'draft' | 'pending_approval' | 'posted' | 'rejected' | 'reversed';

export interface JournalEntryLineItem {
  id?: string;
  account_id: string;
  account?: AccountItem | null;
  line_number?: number;
  debit_cents: number;
  credit_cents: number;
  description?: string | null;
}

export interface JournalEntryItem {
  id: string;
  entry_number?: string | null;
  entry_date: string;
  posting_date?: string | null;
  status: JournalEntryStatus;
  status_label: string;
  description?: string | null;
  source_type?: string | null;
  source_id?: string | null;
  total_debit_cents: number;
  total_credit_cents: number;
  currency: string;
  created_by_user_id?: string | null;
  submitted_by_user_id?: string | null;
  submitted_at?: string | null;
  approved_by_user_id?: string | null;
  approved_at?: string | null;
  rejected_by_user_id?: string | null;
  rejected_at?: string | null;
  rejection_reason?: string | null;
  reversed_by_id?: string | null;
  reversal_of_id?: string | null;
  reversal_reason?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  lines?: JournalEntryLineItem[];
}

export interface CreateFiscalPeriodPayload {
  name: string;
  fiscal_year: number;
  period_number: number;
  start_date: string;
  end_date: string;
}

export interface GenerateFiscalYearPayload {
  fiscal_year: number;
  start_date?: string | null;
}

export interface JournalLinePayload {
  account_id: string;
  debit_cents?: number | null;
  credit_cents?: number | null;
  description?: string | null;
}

export interface CreateJournalEntryPayload {
  entry_date: string;
  status?: 'draft' | 'pending_approval' | 'posted';
  description?: string | null;
  currency?: string;
  lines: JournalLinePayload[];
}

export interface UpdateJournalEntryPayload {
  entry_date: string;
  description?: string | null;
  currency?: string;
  lines: JournalLinePayload[];
}

export interface RejectJournalEntryPayload {
  rejection_reason: string;
}

export interface ReverseJournalEntryPayload {
  reason: string;
}

export interface VatCalculationSummary {
  total_sales_taxable_cents: number;
  total_output_vat_cents: number;
  total_sales_gross_cents: number;
  total_purchases_taxable_cents: number;
  total_input_vat_cents: number;
  total_purchases_gross_cents: number;
  net_vat_payable_cents: number;
}

export interface VatRateBreakdown {
  tax_rate_percent: number;
  sales_taxable_cents: number;
  output_vat_cents: number;
  sales_total_cents: number;
  purchases_taxable_cents: number;
  input_vat_cents: number;
  purchases_total_cents: number;
  net_vat_cents: number;
}

export interface VatLedgerReconciliation {
  gl_output_vat_cents: number;
  gl_input_vat_cents: number;
  gl_net_vat_cents: number;
  output_vat_discrepancy_cents: number;
  input_vat_discrepancy_cents: number;
  net_vat_discrepancy_cents: number;
  is_reconciled: boolean;
}

export interface VatDocumentRate {
  rate_percent: number;
  subtotal_cents: number;
  tax_cents: number;
}

export interface VatDocument {
  document_type: 'sales_invoice' | 'credit_note' | 'supplier_bill' | 'debit_note';
  id: string;
  document_number: string;
  date: string;
  contact_id: string;
  contact_name: string;
  contact_tax_id: string | null;
  direction: 'output' | 'input';
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  rates: VatDocumentRate[];
}

export interface VatCalculationResult {
  from_date: string;
  to_date: string;
  fiscal_period: {
    id: string;
    name: string;
    fiscal_year: number;
    period_number: number;
    status: string;
  } | null;
  summary: VatCalculationSummary;
  rate_breakdown: VatRateBreakdown[];
  ledger_reconciliation: VatLedgerReconciliation;
  documents: VatDocument[];
}

export interface VatReturnItem {
  id: string;
  return_number: string;
  fiscal_period_id: string | null;
  from_date: string;
  to_date: string;
  status: 'draft' | 'filed';
  status_label: string;
  sales_taxable_cents: number;
  output_vat_cents: number;
  purchases_taxable_cents: number;
  input_vat_cents: number;
  net_vat_cents: number;
  rate_breakdown: VatRateBreakdown[];
  is_reconciled: boolean;
  gl_output_vat_cents: number;
  gl_input_vat_cents: number;
  gl_discrepancy_cents: number;
  filed_by_user_id: string | null;
  filed_at: string | null;
  notes: string | null;
  created_at: string | null;
  fiscal_period?: {
    id: string;
    name: string;
    fiscal_year: number;
    period_number: number;
    status: string;
  } | null;
}

export interface CalculateVatReturnParams {
  fiscal_period_id?: string | null;
  from_date?: string | null;
  to_date?: string | null;
}

export interface FileVatReturnPayload {
  fiscal_period_id?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  notes?: string | null;
}
