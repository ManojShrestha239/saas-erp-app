export type ContactType = 'customer' | 'supplier' | 'both';

export interface Address {
  street?: string;
  city?: string;
  state?: string;
  postal_code?: string;
  country?: string;
}

export interface ContactItem {
  id: string;
  type: ContactType;
  type_label: string;
  code: string;
  name: string;
  tax_id: string | null;
  email: string | null;
  phone: string | null;
  payment_terms_days: number;
  credit_limit_cents: number;
  opening_balance_cents: number;
  is_active: boolean;
  notes: string | null;
  billing_address: Address | null;
  shipping_address: Address | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ContactFormData {
  type: ContactType;
  name: string;
  code?: string;
  tax_id?: string;
  email?: string;
  phone?: string;
  payment_terms_days?: number;
  credit_limit_cents?: number;
  opening_balance_cents?: number;
  is_active?: boolean;
  notes?: string;
  billing_address?: Address;
  shipping_address?: Address;
}

export interface ContactFilters {
  search?: string;
  type?: ContactType;
  is_active?: boolean;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export type AgingBucket = 'current' | 'days_1_30' | 'days_31_60' | 'days_61_90' | 'days_over_90';

export interface AgingDocument {
  id: string;
  invoice_number?: string;
  bill_number?: string;
  issue_date?: string;
  bill_date?: string;
  due_date: string;
  total_cents: number;
  amount_paid_cents: number;
  amount_due_cents: number;
  days_overdue: number;
  bucket: AgingBucket;
}

export interface AgingData {
  contact: ContactItem;
  aging_type: 'customer' | 'supplier';
  as_of_date: string;
  current_cents: number;
  days_1_30_cents: number;
  days_31_60_cents: number;
  days_61_90_cents: number;
  days_over_90_cents: number;
  total_due_cents: number;
  unallocated_amount_cents: number;
  net_balance_cents: number;
  documents: AgingDocument[];
}

export interface StatementTransaction {
  id: string;
  date: string;
  due_date: string | null;
  document_number: string;
  type: string;
  description: string;
  debit_cents: number;
  credit_cents: number;
  amount_due_cents: number;
  status: string;
  running_balance_cents: number;
}

export interface StatementSummary {
  opening_balance_cents: number;
  total_debits_cents: number;
  total_credits_cents: number;
  closing_balance_cents: number;
  unallocated_amount_cents: number;
  net_balance_due_cents: number;
}

export interface ContactStatement {
  contact: ContactItem;
  company: {
    name: string;
    tax_id: string | null;
    address: string | null;
    email: string | null;
    phone: string | null;
    bank_details: string | null;
    currency: string;
  };
  statement_type: 'customer' | 'supplier';
  start_date: string;
  end_date: string;
  currency: string;
  summary: StatementSummary;
  aging: {
    current_cents: number;
    days_1_30_cents: number;
    days_31_60_cents: number;
    days_61_90_cents: number;
    days_over_90_cents: number;
    total_due_cents: number;
  };
  transactions: StatementTransaction[];
}
