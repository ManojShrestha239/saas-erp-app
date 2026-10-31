import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import PurchasingIndex from '../PurchasingIndex';
import { purchasingApi } from '../../api/purchasingApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type { GoodsReceipt, PurchaseOrder, SupplierBill } from '../../types/purchasing.types';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { ProductItem, WarehouseItem } from '@/features/inventory/types/inventory.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';

vi.mock('../../api/purchasingApi', () => ({
  purchasingApi: {
    getPurchaseOrders: vi.fn(),
    getPurchaseOrder: vi.fn(),
    createPurchaseOrder: vi.fn(),
    approvePurchaseOrder: vi.fn(),
    cancelPurchaseOrder: vi.fn(),
    getPurchaseOrderPdfUrl: vi.fn().mockReturnValue('/api/v1/purchasing/orders/01HPO00000000000000001/pdf'),
    recordGoodsReceipt: vi.fn(),
    getBills: vi.fn(),
    getBill: vi.fn(),
    createBill: vi.fn(),
    postBill: vi.fn(),
    getSupplierPayableBalance: vi.fn().mockResolvedValue({
      success: true,
      message: 'OK',
      data: { supplier_id: '01SUPP0000000000000001', outstanding_payable_cents: 3500000 },
    }),
  },
}));

vi.mock('@/features/contacts/api/contactsApi', () => ({
  contactsApi: {
    getContacts: vi.fn(),
  },
}));

vi.mock('@/features/inventory/api/inventoryApi', () => ({
  inventoryApi: {
    getWarehouses: vi.fn(),
    getProducts: vi.fn(),
  },
}));

const mockSuppliers: ContactItem[] = [
  {
    id: '01SUPP0000000000000001',
    type: 'supplier',
    type_label: 'Supplier',
    code: 'SUPP-000001',
    name: 'Kathmandu Steel Suppliers',
    tax_id: 'PAN-998877665',
    email: 'sales@ktmsteel.com',
    phone: '+977-1-4400112',
    payment_terms_days: 30,
    credit_limit_cents: 10000000,
    opening_balance_cents: 0,
    is_active: true,
    billing_address: { city: 'Kathmandu', country: 'Nepal' },
    shipping_address: null,
    notes: null,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
  },
];

const mockWarehouses: WarehouseItem[] = [
  {
    id: '01WH000000000000000001',
    name: 'Main Central Depot',
    code: 'WH-KTM-01',
    address: 'Teku, Kathmandu',
    is_default: true,
    is_active: true,
    created_at: '2026-10-28T10:00:00Z',
  },
];

const mockProducts: ProductItem[] = [
  {
    id: '01PROD0000000000000001',
    sku: 'STL-ROD-12',
    name: 'Standard Steel Rod 12mm',
    cost_price_cents: 120000,
    selling_price_cents: 150000,
    tax_category: 'standard',
    low_stock_threshold: 20,
    is_active: true,
    created_at: '2026-10-28T10:00:00Z',
  },
];

const mockPurchaseOrders: PurchaseOrder[] = [
  {
    id: '01HPO00000000000000001',
    order_number: 'PO-2026-000001',
    supplier_id: '01SUPP0000000000000001',
    warehouse_id: '01WH000000000000000001',
    status: 'draft',
    status_label: 'Draft',
    order_date: '2026-10-29',
    expected_delivery_date: '2026-11-05',
    currency: 'NPR',
    subtotal_cents: 12000000,
    tax_cents: 1560000,
    total_cents: 13560000,
    notes: 'Urgent site delivery',
    items: [
      {
        id: '01HPOITM0000000000001',
        purchase_order_id: '01HPO0000000000000001',
        product_id: '01PROD0000000000000001',
        quantity_ordered: 100,
        quantity_received: 0,
        quantity_remaining: 100,
        unit_cost_cents: 120000,
        tax_rate_percent: 13,
        subtotal_cents: 12000000,
        tax_cents: 1560000,
        total_cents: 13560000,
      },
    ],
    created_at: '2026-10-29T10:00:00Z',
  },
  {
    id: '01HPO00000000000000002',
    order_number: 'PO-2026-000002',
    supplier_id: '01SUPP0000000000000001',
    warehouse_id: '01WH000000000000000001',
    status: 'approved',
    status_label: 'Approved',
    order_date: '2026-10-29',
    expected_delivery_date: '2026-11-05',
    currency: 'NPR',
    subtotal_cents: 6000000,
    tax_cents: 780000,
    total_cents: 6780000,
    items: [
      {
        id: '01HPOITM0000000000002',
        purchase_order_id: '01HPO0000000000000002',
        product_id: '01PROD0000000000000001',
        quantity_ordered: 50,
        quantity_received: 20,
        quantity_remaining: 30,
        unit_cost_cents: 120000,
        tax_rate_percent: 13,
        subtotal_cents: 6000000,
        tax_cents: 780000,
        total_cents: 6780000,
      },
    ],
    created_at: '2026-10-29T11:00:00Z',
  },
];

const mockBills: SupplierBill[] = [
  {
    id: '01BILL0000000000000001',
    bill_number: 'BILL-2026-000001',
    supplier_invoice_number: 'SUPP-INV-8891',
    supplier_id: '01SUPP0000000000000001',
    purchase_order_id: '01HPO0000000000000001',
    bill_date: '2026-10-29',
    due_date: '2026-11-28',
    currency: 'NPR',
    subtotal_cents: 12000000,
    tax_cents: 1560000,
    total_cents: 13560000,
    amount_paid_cents: 0,
    amount_due_cents: 13560000,
    status: 'draft',
    status_label: 'Draft',
    posted_at: null,
    posted_by_user_id: null,
    notes: 'Terms 30 days',
    created_at: '2026-10-29T10:00:00Z',
    updated_at: '2026-10-29T10:00:00Z',
    items: [
      {
        id: '01BILLITM000000000001',
        supplier_bill_id: '01BILL0000000000000001',
        description: 'Steel Rod 12mm delivery',
        quantity: 100,
        unit_cost_cents: 120000,
        tax_rate_percent: 13,
        subtotal_cents: 12000000,
        tax_cents: 1560000,
        total_cents: 13560000,
      },
    ],
  },
];

describe('PurchasingIndex Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(contactsApi.getContacts).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockSuppliers,
    } as ApiSuccessResponse<ContactItem[]>);

    vi.mocked(inventoryApi.getWarehouses).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockWarehouses,
    } as ApiSuccessResponse<WarehouseItem[]>);

    vi.mocked(inventoryApi.getProducts).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockProducts,
    } as ApiSuccessResponse<ProductItem[]>);

    vi.mocked(purchasingApi.getPurchaseOrders).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockPurchaseOrders,
      meta: { page: 1, per_page: 25, total: 2 },
    } as ApiSuccessResponse<PurchaseOrder[]>);

    vi.mocked(purchasingApi.getBills).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockBills,
      meta: { page: 1, per_page: 25, total: 1 },
    } as ApiSuccessResponse<SupplierBill[]>);
  });

  it('renders purchasing header, tabs, and action buttons', async () => {
    render(<PurchasingIndex />);

    expect(screen.getAllByText('Purchasing & Procurement').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByRole('button', { name: /Create Purchase Order/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Create Supplier Bill/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('PO-2026-000001')).toBeInTheDocument();
      expect(screen.getByText('PO-2026-000002')).toBeInTheDocument();
    });

    expect(screen.getAllByText('Kathmandu Steel Suppliers').length).toBeGreaterThanOrEqual(1);
  });

  it('switches between Purchase Orders, Supplier Bills, and Payables tabs', async () => {
    render(<PurchasingIndex />);

    await waitFor(() => {
      expect(screen.getByText('PO-2026-000001')).toBeInTheDocument();
    });

    // 1. Switch to Supplier Bills
    const billsTab = screen.getByRole('button', { name: /Supplier Bills/i });
    fireEvent.click(billsTab);

    await waitFor(() => {
      expect(purchasingApi.getBills).toHaveBeenCalled();
      expect(screen.getByText('BILL-2026-000001')).toBeInTheDocument();
      expect(screen.getByText('SUPP-INV-8891')).toBeInTheDocument();
    });

    // 2. Switch to Accounts Payable Overview
    const payablesTab = screen.getByRole('button', { name: /Accounts Payable Overview/i });
    fireEvent.click(payablesTab);

    await waitFor(() => {
      expect(screen.getByText(/Total Outstanding Accounts Payable/i)).toBeInTheDocument();
      expect(purchasingApi.getSupplierPayableBalance).toHaveBeenCalledWith('01SUPP0000000000000001');
    });
  });

  it('opens Create Purchase Order modal and submits successfully', async () => {
    vi.mocked(purchasingApi.createPurchaseOrder).mockResolvedValue({
      success: true,
      message: 'Purchase order created successfully.',
      data: {
        ...mockPurchaseOrders[0]!,
        id: '01HPO00000000000000003',
        order_number: 'PO-2026-000003',
      },
    } as ApiSuccessResponse<PurchaseOrder>);

    render(<PurchasingIndex />);

    await waitFor(() => {
      expect(screen.getByText('PO-2026-000001')).toBeInTheDocument();
    });

    const createPoBtn = screen.getByRole('button', { name: /Create Purchase Order/i });
    fireEvent.click(createPoBtn);

    expect(screen.getByRole('heading', { name: 'Create Purchase Order' })).toBeInTheDocument();

    const savePoBtn = screen.getByRole('button', { name: 'Save Purchase Order' });
    fireEvent.click(savePoBtn);

    await waitFor(() => {
      expect(purchasingApi.createPurchaseOrder).toHaveBeenCalledWith(
        expect.objectContaining({
          supplier_id: mockSuppliers[0]!.id,
          warehouse_id: mockWarehouses[0]!.id,
        })
      );
    });
  });

  it('approves a draft purchase order', async () => {
    vi.mocked(purchasingApi.approvePurchaseOrder).mockResolvedValue({
      success: true,
      message: 'Purchase order approved successfully.',
      data: {
        ...mockPurchaseOrders[0]!,
        status: 'approved',
        status_label: 'Approved',
      },
    } as ApiSuccessResponse<PurchaseOrder>);

    render(<PurchasingIndex />);

    await waitFor(() => {
      expect(screen.getByText('PO-2026-000001')).toBeInTheDocument();
    });

    const actionButtons = screen.getAllByRole('button', { name: 'Order actions' });
    fireEvent.click(actionButtons[0]!);

    const approveOption = await screen.findByText('Approve Order');
    fireEvent.click(approveOption);

    await waitFor(() => {
      expect(purchasingApi.approvePurchaseOrder).toHaveBeenCalledWith(mockPurchaseOrders[0]!.id);
    });
  });

  it('opens goods receipt modal for approved PO and records receipt', async () => {
    vi.mocked(purchasingApi.recordGoodsReceipt).mockResolvedValue({
      success: true,
      message: 'Goods receipt recorded successfully.',
      data: {
        id: '01HGR00000000000000001',
        receipt_number: 'GRN-2026-000001',
        purchase_order_id: '01HPO0000000000000002',
        supplier_id: '01SUPP0000000000000001',
        warehouse_id: '01WH000000000000000001',
        received_date: '2026-10-29',
      },
    } as ApiSuccessResponse<GoodsReceipt>);

    render(<PurchasingIndex />);

    await waitFor(() => {
      expect(screen.getByText('PO-2026-000002')).toBeInTheDocument();
    });

    // PO-2026-000002 is the second row
    const actionButtons = screen.getAllByRole('button', { name: 'Order actions' });
    fireEvent.click(actionButtons[1]!);

    const receiveOption = await screen.findByText('Receive Goods');
    fireEvent.click(receiveOption);

    expect(screen.getByRole('heading', { name: /Record Goods Receipt/i })).toBeInTheDocument();

    const confirmBtn = screen.getByRole('button', { name: 'Confirm Goods Receipt' });
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(purchasingApi.recordGoodsReceipt).toHaveBeenCalledWith(
        mockPurchaseOrders[1]!.id,
        expect.objectContaining({
          received_date: expect.any(String),
          items: expect.arrayContaining([
            expect.objectContaining({
              purchase_order_item_id: mockPurchaseOrders[1]!.items![0]!.id,
              quantity_received: 30,
            }),
          ]),
        })
      );
    });
  });
});
