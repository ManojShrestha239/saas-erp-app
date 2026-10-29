export type SupplierBillStatus = 'draft' | 'posted' | 'partially_paid' | 'paid' | 'void';

export interface SupplierBill {
  id: string;
  bill_number: string;
  supplier_invoice_number: string | null;
  supplier_id: string;
  purchase_order_id: string | null;
  bill_date: string;
  due_date: string;
  currency: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  amount_due_cents: number;
  amount_paid_cents: number;
  status: SupplierBillStatus;
  status_label: string;
  posted_at: string | null;
  posted_by_user_id: string | null;
  notes: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface SupplierPayableResponse {
  supplier_id: string;
  outstanding_payable_cents: number;
}
