export interface TrialBalanceAccount {
  account_id: string;
  code: string;
  name: string;
  type: string;
  debit_balance_cents: number;
  credit_balance_cents: number;
  net_delta_cents: number;
}

export interface TrialBalanceData {
  as_of_date: string;
  accounts: TrialBalanceAccount[];
  total_debits_cents: number;
  total_credits_cents: number;
  is_balanced: boolean;
  net_difference_cents: number;
}

export interface PLAccount {
  account_id: string;
  code: string;
  name: string;
  amount_cents: number;
}

export interface ProfitAndLossData {
  start_date: string;
  end_date: string;
  revenue_accounts: PLAccount[];
  total_revenue_cents: number;
  cogs_accounts: PLAccount[];
  total_cogs_cents: number;
  gross_profit_cents: number;
  expense_accounts: PLAccount[];
  total_operating_expenses_cents: number;
  net_income_cents: number;
}

export interface BSAccount {
  account_id: string;
  code: string;
  name: string;
  amount_cents: number;
}

export interface BalanceSheetData {
  as_of_date: string;
  assets: {
    current: BSAccount[];
    non_current: BSAccount[];
    total_cents: number;
  };
  liabilities: {
    current: BSAccount[];
    non_current: BSAccount[];
    total_cents: number;
  };
  equity: {
    accounts: BSAccount[];
    current_period_earnings_cents: number;
    total_cents: number;
  };
  total_liabilities_and_equity_cents: number;
  is_balanced: boolean;
  net_difference_cents: number;
}

export interface AgingSummary {
  current_cents: number;
  days_1_30_cents: number;
  days_31_60_cents: number;
  days_61_90_cents: number;
  days_over_90_cents: number;
  total_due_cents: number;
}

export interface ArCustomerRow {
  customer_id: string;
  customer_code: string;
  customer_name: string;
  current_cents: number;
  days_1_30_cents: number;
  days_31_60_cents: number;
  days_61_90_cents: number;
  days_over_90_cents: number;
  total_due_cents: number;
  invoice_count: number;
}

export interface InvoiceDetailRow {
  id: string;
  invoice_number: string;
  issue_date: string;
  due_date: string;
  total_cents: number;
  amount_paid_cents: number;
  amount_due_cents: number;
  days_overdue: number;
  bucket: string;
}

export interface ArAgingData {
  as_of_date: string;
  summary: AgingSummary;
  customers: ArCustomerRow[];
  invoices?: InvoiceDetailRow[];
  gl_control_account_cents: number;
  reconciliation_difference_cents: number;
  is_reconciled: boolean;
}

export interface ApSupplierRow {
  supplier_id: string;
  supplier_code: string;
  supplier_name: string;
  current_cents: number;
  days_1_30_cents: number;
  days_31_60_cents: number;
  days_61_90_cents: number;
  days_over_90_cents: number;
  total_due_cents: number;
  bill_count: number;
}

export interface BillDetailRow {
  id: string;
  bill_number: string;
  supplier_invoice_number: string | null;
  bill_date: string;
  due_date: string;
  total_cents: number;
  amount_paid_cents: number;
  amount_due_cents: number;
  days_overdue: number;
  bucket: string;
}

export interface ApAgingData {
  as_of_date: string;
  summary: AgingSummary;
  suppliers: ApSupplierRow[];
  bills?: BillDetailRow[];
  gl_control_account_cents: number;
  reconciliation_difference_cents: number;
  is_reconciled: boolean;
}

export interface ValuationSummary {
  total_valuation_cents: number;
  total_units_on_hand: number;
  total_products_count: number;
  valued_products_count: number;
  currency: string;
  gl_control_account_cents: number;
  reconciliation_difference_cents: number;
  is_reconciled: boolean;
}

export interface ProductValuationItem {
  product_id: string;
  sku: string;
  name: string;
  category_name: string | null;
  unit_of_measure_name: string | null;
  total_quantity_on_hand: number;
  moving_average_cost_cents: number;
  selling_price_cents: number;
  total_valuation_cents: number;
  margin_percent: number | null;
  is_active: boolean;
  warehouse_breakdown: Array<{
    warehouse_id: string;
    warehouse_name: string;
    quantity_on_hand: number;
    valuation_cents: number;
  }>;
}

export interface StockValuationData {
  as_of_date: string;
  summary: ValuationSummary;
  items: ProductValuationItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface StockMovementItem {
  id: string;
  warehouse_id: string;
  product_id: string;
  type: string;
  type_label: string;
  quantity: number;
  unit_cost_cents: number;
  source_type: string | null;
  source_id: string | null;
  notes: string | null;
  created_at: string;
  warehouse?: { id: string; name: string; code: string };
  product?: { id: string; name: string; sku: string };
}

export interface StockMovementData {
  items: StockMovementItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
