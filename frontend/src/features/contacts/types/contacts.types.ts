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
