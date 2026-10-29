import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import CustomerReceiptsIndex from '../CustomerReceiptsIndex';
import { paymentsApi } from '../../api/paymentsApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import type { Payment } from '../../types/payments.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { ContactItem } from '@/features/contacts/types/contacts.types';

vi.mock('../../api/paymentsApi', () => ({
  paymentsApi: {
    getPayments: vi.fn(),
    getPayment: vi.fn(),
    createPayment: vi.fn(),
    postPayment: vi.fn(),
    allocatePayment: vi.fn(),
    getUnallocatedCredit: vi.fn(),
  },
}));

vi.mock('@/features/sales/api/salesApi', () => ({
  salesApi: {
    getInvoices: vi.fn(),
  },
}));

vi.mock('@/features/contacts/api/contactsApi', () => ({
  contactsApi: {
    getContacts: vi.fn(),
  },
}));

const mockCustomer: ContactItem = {
  id: '01HCUST0000000000000000001',
  code: 'CUST-001',
  name: 'Everest Retailers Ltd',
  type: 'customer',
  type_label: 'Customer',
  tax_id: 'PAN-123456789',
  email: 'billing@everest.com',
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

const mockReceipts: Payment[] = [
  {
    id: '01HPAY00000000000000000001',
    payment_number: 'REC-2026-000001',
    type: 'inbound',
    contact_id: '01HCUST0000000000000000001',
    amount_cents: 50000,
    allocated_amount_cents: 20000,
    unallocated_amount_cents: 30000,
    currency: 'USD',
    payment_method: 'bank_transfer',
    payment_date: '2026-10-29',
    reference_number: 'WIRE-998811',
    notes: 'Advance deposit',
    status: 'posted',
    status_label: 'Posted',
    created_by_user_id: '01HUSER000000000000000001',
    posted_by_user_id: '01HUSER000000000000000001',
    posted_at: '2026-10-29T12:00:00Z',
    allocations: [],
    created_at: '2026-10-29T10:00:00Z',
    updated_at: '2026-10-29T12:00:00Z',
  },
];

describe('CustomerReceiptsIndex', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(contactsApi.getContacts).mockResolvedValue({
      success: true,
      data: [mockCustomer],
      message: 'Success',
    } as ApiSuccessResponse<ContactItem[]>);

    vi.mocked(paymentsApi.getPayments).mockResolvedValue({
      success: true,
      data: mockReceipts,
      message: 'Success',
      meta: {
        current_page: 1,
        per_page: 25,
        total: 1,
        last_page: 1,
      },
    } as ApiSuccessResponse<Payment[]>);

    vi.mocked(paymentsApi.getUnallocatedCredit).mockResolvedValue({
      success: true,
      data: {
        contact_id: mockCustomer.id,
        type: 'inbound',
        unallocated_credit_cents: 30000,
      },
      message: 'Success',
    });
  });

  it('renders Customer Receipts page header and table with receipts', async () => {
    render(<CustomerReceiptsIndex />);

    expect(screen.getByRole('heading', { level: 1, name: /customer receipts/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /record receipt/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('REC-2026-000001')).toBeInTheDocument();
      expect(screen.getAllByText('Everest Retailers Ltd').length).toBeGreaterThanOrEqual(1);
      expect(screen.getByText('$500.00')).toBeInTheDocument();
      expect(screen.getByText('$200.00')).toBeInTheDocument();
      expect(screen.getByText('$300.00')).toBeInTheDocument();
    });
  });

  it('opens record receipt modal when button is clicked', async () => {
    render(<CustomerReceiptsIndex />);

    const recordBtn = screen.getByRole('button', { name: /record receipt/i });
    fireEvent.click(recordBtn);

    await waitFor(() => {
      expect(screen.getByRole('dialog')).toBeInTheDocument();
      expect(screen.getByText(/record customer receipt/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/amount/i)).toBeInTheDocument();
    });
  });

  it('filters payments when status tabs are clicked', async () => {
    render(<CustomerReceiptsIndex />);

    await waitFor(() => {
      expect(paymentsApi.getPayments).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'inbound' })
      );
    });

    const draftTab = screen.getByRole('button', { name: /^draft$/i });
    fireEvent.click(draftTab);

    await waitFor(() => {
      expect(paymentsApi.getPayments).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'inbound',
          status: 'draft',
        })
      );
    });
  });
});
