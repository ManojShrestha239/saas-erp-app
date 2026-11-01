import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import InvoicesIndex from '../InvoicesIndex';
import { salesApi } from '../../api/salesApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type { SalesInvoice, CreditNote } from '../../types/sales.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { ContactItem } from '@/features/contacts/types/contacts.types';

vi.mock('../../api/salesApi', () => ({
  salesApi: {
    getInvoices: vi.fn(),
    getInvoice: vi.fn(),
    createInvoice: vi.fn(),
    updateInvoice: vi.fn(),
    deleteInvoice: vi.fn(),
    postInvoice: vi.fn(),
    getCustomerReceivableBalance: vi.fn(),
    getCreditNotes: vi.fn(),
    getCreditNote: vi.fn(),
    createCreditNote: vi.fn(),
    postCreditNote: vi.fn(),
    deleteCreditNote: vi.fn(),
  },
}));

vi.mock('@/features/contacts/api/contactsApi', () => ({
  contactsApi: {
    getContacts: vi.fn(),
  },
}));

vi.mock('@/features/inventory/api/inventoryApi', () => ({
  inventoryApi: {
    getProducts: vi.fn(),
  },
}));

const mockCustomer: ContactItem = {
  id: '01HCUSTOMER00000000000001',
  code: 'CUST-001',
  name: 'Acme Distribution Ltd',
  type: 'customer',
  type_label: 'Customer',
  tax_id: 'PAN-123456789',
  email: 'sales@acme.com',
  phone: '9841000000',
  payment_terms_days: 30,
  credit_limit_cents: 10000000,
  opening_balance_cents: 0,
  is_active: true,
  notes: null,
  billing_address: null,
  shipping_address: null,
  created_at: '2026-10-29T10:00:00Z',
  updated_at: '2026-10-29T10:00:00Z',
};

const mockInvoices: SalesInvoice[] = [
  {
    id: '01HINVOICE000000000000001',
    invoice_number: 'INV-2026-000001',
    sales_quotation_id: null,
    customer_id: '01HCUSTOMER00000000000001',
    status: 'draft',
    status_label: 'Draft',
    issue_date: '2026-10-29',
    due_date: '2026-11-28',
    currency: 'USD',
    subtotal_cents: 10000,
    tax_cents: 1300,
    total_cents: 11300,
    amount_paid_cents: 0,
    amount_due_cents: 11300,
    notes: 'Draft direct invoice',
    created_by_user_id: 'test-user-id',
    posted_by_user_id: null,
    posted_at: null,
    voided_at: null,
    created_at: '2026-10-29T10:00:00Z',
    updated_at: '2026-10-29T10:00:00Z',
    items: [],
  },
  {
    id: '01HINVOICE000000000000002',
    invoice_number: 'INV-2026-000002',
    sales_quotation_id: '01HQUOTATION000000000002',
    customer_id: '01HCUSTOMER00000000000001',
    status: 'posted',
    status_label: 'Posted',
    issue_date: '2026-10-28',
    due_date: '2026-11-27',
    currency: 'USD',
    subtotal_cents: 20000,
    tax_cents: 2600,
    total_cents: 22600,
    amount_paid_cents: 0,
    amount_due_cents: 22600,
    notes: 'Posted commercial invoice',
    created_by_user_id: 'test-user-id',
    posted_by_user_id: 'test-user-id',
    posted_at: '2026-10-28T12:00:00Z',
    voided_at: null,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T12:00:00Z',
    items: [],
  },
];

describe('InvoicesIndex', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(contactsApi.getContacts).mockResolvedValue({
      success: true,
      message: 'OK',
      data: [mockCustomer],
    } as ApiSuccessResponse<ContactItem[]>);

    vi.mocked(inventoryApi.getProducts).mockResolvedValue({
      success: true,
      message: 'OK',
      data: [
        {
          id: '01HPROD0000000000000001',
          sku: 'WIDGET-01',
          name: 'Super Widget',
          selling_price_cents: 5000,
          cost_price_cents: 3000,
          tax_category: 'standard',
          is_active: true,
        },
      ],
    });

    vi.mocked(salesApi.getInvoices).mockResolvedValue({
      success: true,
      message: 'Sales invoices retrieved successfully.',
      data: mockInvoices,
      meta: {
        current_page: 1,
        per_page: 25,
        total: 2,
        last_page: 1,
      },
    } as ApiSuccessResponse<SalesInvoice[]>);

    vi.mocked(salesApi.getCustomerReceivableBalance).mockResolvedValue({
      success: true,
      message: 'OK',
      data: {
        customer_id: '01HCUSTOMER00000000000001',
        outstanding_receivable_cents: 22600,
      },
    } as ApiSuccessResponse<{ customer_id: string; outstanding_receivable_cents: number }>);

    vi.mocked(salesApi.getCreditNotes).mockResolvedValue({
      success: true,
      message: 'Credit notes retrieved successfully.',
      data: [
        {
          id: '01HCN00000000000000001',
          credit_note_number: 'CN-2026-000001',
          customer_id: '01HCUSTOMER00000000000001',
          warehouse_id: '01WH000000000000000001',
          sales_invoice_id: '01HINVOICE000000000000002',
          status: 'draft',
          status_label: 'Draft',
          issue_date: '2026-10-30',
          currency: 'USD',
          subtotal_cents: 5000,
          tax_cents: 650,
          total_cents: 5650,
          reason: 'customer_return',
          restock: true,
          notes: 'Test return',
          created_by_user_id: 'test-user-id',
          posted_at: null,
          created_at: '2026-10-30T10:00:00Z',
          items: [],
        },
      ],
      meta: {
        current_page: 1,
        per_page: 25,
        total: 1,
        last_page: 1,
      },
    } as ApiSuccessResponse<CreditNote[]>);
  });

  it('renders invoices list and action buttons', async () => {
    render(<InvoicesIndex />);

    expect(screen.getByRole('heading', { level: 1, name: 'Sales Invoices' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /New Invoice/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('INV-2026-000001')).toBeInTheDocument();
      expect(screen.getByText('INV-2026-000002')).toBeInTheDocument();
    });

    expect(screen.getAllByText('Acme Distribution Ltd').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('$113.00').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('$226.00').length).toBeGreaterThanOrEqual(1);
  });

  it('filters invoices by status tab', async () => {
    render(<InvoicesIndex />);

    await waitFor(() => {
      expect(screen.getByText('INV-2026-000001')).toBeInTheDocument();
    });

    const postedTab = screen.getByRole('button', { name: 'Posted' });
    fireEvent.click(postedTab);

    expect(salesApi.getInvoices).toHaveBeenCalledWith(
      expect.objectContaining({
        status: 'posted',
      })
    );
  });

  it('loads AR balance widget when selecting customer filter', async () => {
    render(<InvoicesIndex />);

    await waitFor(() => {
      expect(screen.getByText('INV-2026-000001')).toBeInTheDocument();
      expect(screen.getByRole('option', { name: 'Acme Distribution Ltd' })).toBeInTheDocument();
    });

    const customerSelect = screen.getByRole('combobox', { name: /Filter by customer/i });
    fireEvent.change(customerSelect, { target: { value: '01HCUSTOMER00000000000001' } });

    await waitFor(() => {
      expect(salesApi.getCustomerReceivableBalance).toHaveBeenCalledWith('01HCUSTOMER00000000000001');
      expect(screen.getByText('Customer Outstanding AR Balance')).toBeInTheDocument();
    });
  });

  it('opens New Invoice modal when clicking New Invoice', async () => {
    render(<InvoicesIndex />);

    const newBtn = screen.getByRole('button', { name: /New Invoice/i });
    fireEvent.click(newBtn);

    expect(screen.getByText('Create a direct sales invoice. Items will default to draft status and require posting before payment.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'New Sales Invoice' })).toBeInTheDocument();
  });

  it('switches to Credit Notes tab and fetches credit notes', async () => {
    render(<InvoicesIndex />);

    const cnTab = screen.getByRole('button', { name: /Credit Notes/i });
    fireEvent.click(cnTab);

    await waitFor(() => {
      expect(salesApi.getCreditNotes).toHaveBeenCalled();
      expect(screen.getByText('CN-2026-000001')).toBeInTheDocument();
      expect(screen.getByText('Stock Effect')).toBeInTheDocument();
      expect(screen.getByText('Restocked')).toBeInTheDocument();
    });

    const newCnBtn = screen.getByRole('button', { name: /New Credit Note/i });
    expect(newCnBtn).toBeInTheDocument();
  });
});
