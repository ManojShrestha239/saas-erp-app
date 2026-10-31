export type PurchaseOrderStatus =
  | 'draft'
  | 'approved'
  | 'partially_received'
  | 'received'
  | 'closed'
  | 'cancelled';

export type SupplierBillStatus =
  | 'draft'
  | 'posted'
  | 'partially_paid'
  | 'paid'
  | 'void';

export interface PurchaseOrderItem {
  id: string;
  purchase_order_id: string;
  product_id: string;
  quantity_ordered: number;
  quantity_received: number;
  quantity_remaining: number;
  unit_cost_cents: number;
  tax_rate_percent: number;
  tax_category?: string | null;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface GoodsReceiptItem {
  id: string;
  goods_receipt_id: string;
  purchase_order_item_id: string;
  product_id: string;
  quantity_received: number;
  unit_cost_cents: number;
  created_at?: string;
  updated_at?: string;
}

export interface GoodsReceipt {
  id: string;
  receipt_number: string;
  purchase_order_id: string;
  supplier_id: string;
  warehouse_id: string;
  received_date: string;
  notes?: string | null;
  received_by_user_id?: string | null;
  items?: GoodsReceiptItem[];
  created_at?: string;
  updated_at?: string;
}

export interface PurchaseOrder {
  id: string;
  order_number: string;
  supplier_id: string;
  warehouse_id: string;
  status: PurchaseOrderStatus;
  status_label: string;
  order_date: string;
  expected_delivery_date?: string | null;
  currency: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes?: string | null;
  created_by_user_id?: string | null;
  approved_by_user_id?: string | null;
  approved_at?: string | null;
  created_at?: string;
  updated_at?: string;
  items?: PurchaseOrderItem[];
  receipts?: GoodsReceipt[];
}

export interface PurchaseOrderFilters {
  status?: PurchaseOrderStatus | string;
  supplier_id?: string;
  warehouse_id?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface PurchaseOrderFormData {
  supplier_id: string;
  warehouse_id: string;
  order_date: string;
  expected_delivery_date?: string | null;
  currency?: string;
  notes?: string | null;
  items: Array<{
    product_id: string;
    quantity_ordered: number;
    unit_cost_cents: number;
    tax_rate_percent?: number;
    tax_category?: string;
    notes?: string;
  }>;
}

export interface GoodsReceiptFormData {
  received_date: string;
  notes?: string | null;
  items: Array<{
    purchase_order_item_id: string;
    quantity_received: number;
  }>;
}

export interface SupplierBillItem {
  id: string;
  supplier_bill_id: string;
  purchase_order_item_id?: string | null;
  product_id?: string | null;
  description: string;
  quantity: number;
  unit_cost_cents: number;
  tax_rate_percent: number;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  created_at?: string;
  updated_at?: string;
}

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
  items?: SupplierBillItem[];
}

export interface SupplierBillFilters {
  status?: SupplierBillStatus | string;
  supplier_id?: string;
  purchase_order_id?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface SupplierBillFormData {
  supplier_id: string;
  bill_date: string;
  due_date: string;
  supplier_invoice_number?: string | null;
  purchase_order_id?: string | null;
  currency?: string;
  notes?: string | null;
  items: Array<{
    description: string;
    quantity: number;
    unit_cost_cents: number;
    tax_rate_percent?: number;
    purchase_order_item_id?: string | null;
    product_id?: string | null;
  }>;
}

export interface SupplierPayableResponse {
  supplier_id: string;
  outstanding_payable_cents: number;
}
