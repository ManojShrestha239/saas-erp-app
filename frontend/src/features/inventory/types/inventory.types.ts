export type TaxCategory = 'standard' | 'zero_rated' | 'exempt';

export interface CategoryItem {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface CategoryFormData {
  name: string;
  description?: string | null;
  is_active?: boolean;
}

export interface UnitOfMeasureItem {
  id: string;
  name: string;
  symbol: string;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface UnitOfMeasureFormData {
  name: string;
  symbol: string;
  is_active?: boolean;
}

export interface WarehouseItem {
  id: string;
  name: string;
  code: string;
  address: string | null;
  is_default: boolean;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface WarehouseFormData {
  name: string;
  code: string;
  address?: string | null;
  is_default?: boolean;
  is_active?: boolean;
}

export interface ProductItem {
  id: string;
  sku: string;
  name: string;
  cost_price_cents: number;
  selling_price_cents: number;
  tax_category: TaxCategory | string;
  tax_category_label?: string;
  is_active: boolean;
  category_id?: string | null;
  unit_of_measure_id?: string | null;
  barcode?: string | null;
  description?: string | null;
  low_stock_threshold?: number;
  category?: CategoryItem | null;
  unit_of_measure?: UnitOfMeasureItem | null;
  created_at?: string;
  updated_at?: string;
}

export interface ProductFormData {
  name: string;
  sku: string;
  barcode?: string | null;
  category_id?: string | null;
  unit_of_measure_id?: string | null;
  cost_price_cents: number;
  selling_price_cents: number;
  tax_category: TaxCategory;
  low_stock_threshold?: number;
  description?: string | null;
  is_active?: boolean;
}

export interface ProductFilters {
  search?: string;
  category_id?: string;
  unit_of_measure_id?: string;
  tax_category?: string;
  is_active?: boolean;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface StockLevelItem {
  id: string;
  warehouse_id: string;
  product_id: string;
  quantity_on_hand: number;
  quantity_reserved: number;
  quantity_available: number;
  warehouse?: WarehouseItem;
  product?: ProductItem;
  created_at?: string;
  updated_at?: string;
}

export interface StockLevelFilters {
  warehouse_id?: string;
  product_id?: string;
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface StockAdjustmentLineItem {
  id: string;
  stock_adjustment_id: string;
  product_id: string;
  quantity_before: number;
  quantity_delta: number;
  quantity_after: number;
  unit_cost_cents: number | null;
  line_cost_impact_cents: number | null;
  product?: ProductItem;
  created_at?: string;
}

export interface StockAdjustmentItem {
  id: string;
  adjustment_number: string;
  warehouse_id: string;
  reason: string;
  user_id?: string | null;
  status: string;
  total_cost_impact_cents: number;
  warehouse?: WarehouseItem;
  items?: StockAdjustmentLineItem[];
  created_at?: string;
  updated_at?: string;
}

export interface StockAdjustmentFormData {
  warehouse_id: string;
  reason: string;
  items: Array<{
    product_id: string;
    quantity_delta: number;
    unit_cost_cents?: number | null;
  }>;
}

export interface StockAdjustmentFilters {
  warehouse_id?: string;
  search?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface LowStockItem {
  id: string;
  product_id: string;
  product_name: string;
  sku: string;
  barcode: string | null;
  category_name: string | null;
  unit_of_measure: string | null;
  warehouse_id: string;
  warehouse_name: string;
  quantity_on_hand: number;
  quantity_reserved: number;
  low_stock_threshold: number;
  deficit: number;
  is_out_of_stock: boolean;
  created_at: string;
  updated_at: string;
}

export interface LowStockAlertStreamEvent {
  type: 'low_stock_alert';
  product_id: string;
  product_name: string;
  sku: string;
  warehouse_id: string;
  warehouse_name: string;
  quantity_on_hand: number;
  low_stock_threshold: number;
  deficit: number;
  timestamp: string;
}

export interface GetLowStockParams {
  warehouse_id?: string;
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface WarehouseValuationBreakdown {
  warehouse_id: string;
  warehouse_name: string;
  quantity_on_hand: number;
  valuation_cents: number;
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
  warehouse_breakdown: WarehouseValuationBreakdown[];
}

export interface ValuationSummary {
  total_valuation_cents: number;
  total_units_on_hand: number;
  total_products_count: number;
  valued_products_count: number;
  currency: string;
}

export interface ValuationFilters {
  search?: string;
  category_id?: string;
  warehouse_id?: string;
  in_stock_only?: boolean;
  page?: number;
  per_page?: number;
  sort_field?: string;
  sort_direction?: 'asc' | 'desc';
}

export interface CostHistoryItem {
  movement_id: string;
  created_at: string;
  type: string;
  type_label: string;
  warehouse_name: string;
  quantity_delta: number;
  movement_unit_cost_cents: number;
  source_type: string | null;
  source_id: string | null;
  notes: string | null;
  resulting_average_cost_cents: number;
  resulting_valuation_cents: number;
}

export interface ProductCostHistoryResponse {
  product: {
    id: string;
    sku: string;
    name: string;
    category_name: string | null;
    unit_of_measure_name: string | null;
    current_moving_average_cost_cents: number;
    total_quantity_on_hand: number;
    total_valuation_cents: number;
  };
  history: CostHistoryItem[];
}
