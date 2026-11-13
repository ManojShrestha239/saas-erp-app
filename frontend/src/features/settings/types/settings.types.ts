export interface TenantSettingsDictionary {
  company_name?: string | null;
  company_tax_id?: string | null;
  tax_number?: string | null;
  company_address?: string | null;
  company_email?: string | null;
  company_phone?: string | null;
  bank_details?: string | null;
  currency?: string | null;
  timezone?: string | null;
  date_format?: string | null;
  fiscal_year_start?: string | null;
  allow_negative_stock?: string | boolean | null;
  low_stock_threshold_default?: string | number | null;
  invoice_prefix?: string | null;
  bill_prefix?: string | null;
  quotation_prefix?: string | null;
  po_prefix?: string | null;
  [key: string]: string | boolean | number | null | undefined;
}

export type SettingsTabId = 'company' | 'finance' | 'inventory' | 'documents';
