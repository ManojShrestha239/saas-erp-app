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
