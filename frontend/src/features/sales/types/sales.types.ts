export type SalesQuotationStatus = 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired';

export type SalesInvoiceStatus = 'draft' | 'posted' | 'partially_paid' | 'paid' | 'void';

export interface SalesQuotationItem {
  id: string;
  sales_quotation_id: string;
  product_id: string;
  description: string | null;
  quantity: number;
  unit_price_cents: number;
  tax_rate_percent: number;
  tax_category: string | null;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes: string | null;
}

export interface SalesQuotation {
  id: string;
  quotation_number: string;
  customer_id: string;
  warehouse_id?: string | null;
  status: SalesQuotationStatus;
  status_label: string;
  issue_date: string;
  expiry_date: string | null;
  currency: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes: string | null;
  created_by_user_id: string | null;
  sent_at: string | null;
  accepted_at: string | null;
  rejected_at: string | null;
  converted_to_invoice_id: string | null;
  converted_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  items?: SalesQuotationItem[];
}

export interface SalesInvoiceItem {
  id: string;
  sales_invoice_id: string;
  product_id: string;
  description: string | null;
  quantity: number;
  unit_price_cents: number;
  tax_rate_percent: number;
  tax_category: string | null;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes: string | null;
}

export interface SalesInvoice {
  id: string;
  invoice_number: string;
  sales_quotation_id: string | null;
  customer_id: string;
  warehouse_id?: string | null;
  status: SalesInvoiceStatus;
  status_label: string;
  issue_date: string;
  due_date: string;
  currency: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  amount_paid_cents: number;
  amount_due_cents: number;
  notes: string | null;
  created_by_user_id: string | null;
  posted_by_user_id: string | null;
  posted_at: string | null;
  voided_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  items?: SalesInvoiceItem[];
  quotation?: SalesQuotation;
}

export interface SalesItemFormData {
  product_id: string;
  quantity: number;
  unit_price_cents: number;
  tax_rate_percent?: number;
  tax_category?: string;
  description?: string;
  notes?: string;
}

export interface CreateQuotationFormData {
  customer_id: string;
  warehouse_id?: string;
  issue_date: string;
  expiry_date?: string;
  currency?: string;
  notes?: string;
  items: SalesItemFormData[];
}

export interface CreateInvoiceFormData {
  customer_id: string;
  warehouse_id?: string;
  issue_date: string;
  due_date: string;
  currency?: string;
  sales_quotation_id?: string;
  notes?: string;
  items: SalesItemFormData[];
}

export interface SalesFilters {
  search?: string;
  status?: string;
  customer_id?: string;
  page?: number;
  per_page?: number;
}
