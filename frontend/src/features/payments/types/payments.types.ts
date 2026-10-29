export type PaymentType = 'inbound' | 'outbound';

export type PaymentMethod = 'cash' | 'bank_transfer' | 'cheque' | 'card' | 'other';

export type PaymentStatus = 'draft' | 'posted' | 'void';

export type PayableType = 'invoice' | 'bill';

export interface PaymentAllocation {
  id: string;
  payment_id: string;
  payable_type: PayableType;
  payable_id: string;
  amount_cents: number;
  allocated_at: string;
  allocated_by_user_id: string | null;
  created_at: string;
}

export interface Payment {
  id: string;
  payment_number: string;
  type: PaymentType;
  contact_id: string;
  contact_name?: string;
  amount_cents: number;
  allocated_amount_cents: number;
  unallocated_amount_cents: number;
  currency: string;
  payment_method: PaymentMethod;
  payment_date: string;
  reference_number: string | null;
  notes: string | null;
  status: PaymentStatus;
  status_label: string;
  created_by_user_id: string;
  posted_by_user_id: string | null;
  posted_at: string | null;
  allocations?: PaymentAllocation[];
  created_at: string;
  updated_at: string;
}

export interface PaymentAllocationItemPayload {
  payable_type: PayableType;
  payable_id: string;
  amount_cents: number;
}

export interface CreatePaymentPayload {
  type: PaymentType;
  contact_id: string;
  amount_cents: number;
  currency?: string;
  payment_method: PaymentMethod;
  payment_date: string;
  reference_number?: string | null;
  notes?: string | null;
  post_immediately?: boolean;
  allocations?: PaymentAllocationItemPayload[];
}

export interface AllocatePaymentPayload {
  allocations: PaymentAllocationItemPayload[];
}

export interface PaymentFilters {
  type?: PaymentType;
  status?: PaymentStatus;
  contact_id?: string;
  page?: number;
  per_page?: number;
}
