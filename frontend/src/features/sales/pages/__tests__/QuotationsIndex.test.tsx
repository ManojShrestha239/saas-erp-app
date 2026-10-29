import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import QuotationsIndex from '../QuotationsIndex';
import { salesApi } from '../../api/salesApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type { SalesQuotation } from '../../types/sales.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { ContactItem } from '@/features/contacts/types/contacts.types';

vi.mock('../../api/salesApi', () => ({
  salesApi: {
    getQuotations: vi.fn(),
    getQuotation: vi.fn(),
    createQuotation: vi.fn(),
    sendQuotation: vi.fn(),
    acceptQuotation: vi.fn(),
    rejectQuotation: vi.fn(),
    convertQuotation: vi.fn(),
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

const mockQuotations: SalesQuotation[] = [
  {
    id: '01HQUOTATION000000000001',
    quotation_number: 'QUO-2026-000001',
    customer_id: '01HCUSTOMER00000000000001',
    status: 'draft',
    status_label: 'Draft',
    issue_date: '2026-10-29',
    expiry_date: '2026-11-28',
    currency: 'USD',
    subtotal_cents: 10000,
    tax_cents: 1300,
    total_cents: 11300,
    notes: 'Draft estimate',
    created_by_user_id: 'test-user-id',
    sent_at: null,
    accepted_at: null,
    rejected_at: null,
    converted_to_invoice_id: null,
    converted_at: null,
    created_at: '2026-10-29T10:00:00Z',
    updated_at: '2026-10-29T10:00:00Z',
    items: [],
  },
  {
    id: '01HQUOTATION000000000002',
    quotation_number: 'QUO-2026-000002',
    customer_id: '01HCUSTOMER00000000000001',
    status: 'accepted',
    status_label: 'Accepted',
    issue_date: '2026-10-28',
    expiry_date: '2026-11-27',
    currency: 'USD',
    subtotal_cents: 20000,
    tax_cents: 2600,
    total_cents: 22600,
    notes: 'Accepted order estimate',
    created_by_user_id: 'test-user-id',
    sent_at: '2026-10-28T11:00:00Z',
    accepted_at: '2026-10-28T12:00:00Z',
    rejected_at: null,
    converted_to_invoice_id: null,
    converted_at: null,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T12:00:00Z',
    items: [],
  },
];

describe('QuotationsIndex', () => {
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

    vi.mocked(salesApi.getQuotations).mockResolvedValue({
      success: true,
      message: 'Sales quotations retrieved successfully.',
      data: mockQuotations,
      meta: {
        current_page: 1,
        per_page: 25,
        total: 2,
        last_page: 1,
      },
    } as ApiSuccessResponse<SalesQuotation[]>);
  });

  it('renders quotations list and action buttons', async () => {
    render(<QuotationsIndex />);

    expect(screen.getByRole('heading', { level: 1, name: 'Sales Quotations' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /New Quotation/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('QUO-2026-000001')).toBeInTheDocument();
      expect(screen.getByText('QUO-2026-000002')).toBeInTheDocument();
    });

    expect(screen.getAllByText('Acme Distribution Ltd').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('$113.00')).toBeInTheDocument();
    expect(screen.getByText('$226.00')).toBeInTheDocument();
  });

  it('filters quotations by status tab', async () => {
    render(<QuotationsIndex />);

    await waitFor(() => {
      expect(screen.getByText('QUO-2026-000001')).toBeInTheDocument();
    });

    const draftTab = screen.getByRole('button', { name: 'Draft' });
    fireEvent.click(draftTab);

    expect(salesApi.getQuotations).toHaveBeenCalledWith(
      expect.objectContaining({
        status: 'draft',
      })
    );
  });

  it('opens New Quotation modal when clicking New Quotation', async () => {
    render(<QuotationsIndex />);

    const newBtn = screen.getByRole('button', { name: /New Quotation/i });
    fireEvent.click(newBtn);

    expect(screen.getByText('Fill in customer details, dates, and line items. Prices are calculated automatically.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'New Sales Quotation' })).toBeInTheDocument();
  });
});
