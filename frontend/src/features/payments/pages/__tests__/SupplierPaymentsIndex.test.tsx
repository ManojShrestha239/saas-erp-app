import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import SupplierPaymentsIndex from '../SupplierPaymentsIndex';
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

vi.mock('@/features/purchasing/api/purchasingApi', () => ({
  purchasingApi: {
    getBills: vi.fn(),
  },
}));

vi.mock('@/features/contacts/api/contactsApi', () => ({
  contactsApi: {
    getContacts: vi.fn(),
  },
}));

const mockSupplier: ContactItem = {
  id: '01HSUPP0000000000000000001',
  code: 'SUPP-001',
  name: 'Kathmandu Packaging Ltd',
  type: 'supplier',
  type_label: 'Supplier',
  tax_id: 'PAN-987654321',
  email: 'accounts@ktmpack.com',
  phone: '9851000000',
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

const mockPayments: Payment[] = [
  {
    id: '01HPAY00000000000000000002',
    payment_number: 'PAY-2026-000001',
    type: 'outbound',
    contact_id: '01HSUPP0000000000000000001',
    amount_cents: 100000,
    allocated_amount_cents: 60000,
    unallocated_amount_cents: 40000,
    currency: 'USD',
    payment_method: 'bank_transfer',
    payment_date: '2026-10-29',
    reference_number: 'EFT-881122',
    notes: 'Partial supplier disbursement',
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

describe('SupplierPaymentsIndex', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(contactsApi.getContacts).mockResolvedValue({
      success: true,
      data: [mockSupplier],
      message: 'Success',
    } as ApiSuccessResponse<ContactItem[]>);

    vi.mocked(paymentsApi.getPayments).mockResolvedValue({
      success: true,
      data: mockPayments,
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
        contact_id: mockSupplier.id,
        type: 'outbound',
        unallocated_credit_cents: 40000,
      },
      message: 'Success',
    });
  });

  it('renders Supplier Payments page header and table with payments', async () => {
    render(<SupplierPaymentsIndex />);

    expect(screen.getByRole('heading', { level: 1, name: /supplier payments/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /record payment/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('PAY-2026-000001')).toBeInTheDocument();
      expect(screen.getAllByText('Kathmandu Packaging Ltd').length).toBeGreaterThanOrEqual(1);
      expect(screen.getByText('$1,000.00')).toBeInTheDocument();
      expect(screen.getByText('$600.00')).toBeInTheDocument();
      expect(screen.getByText('$400.00')).toBeInTheDocument();
    });
  });

  it('opens record payment modal when button is clicked', async () => {
    render(<SupplierPaymentsIndex />);

    const recordBtn = screen.getByRole('button', { name: /record payment/i });
    fireEvent.click(recordBtn);

    await waitFor(() => {
      expect(screen.getByRole('dialog')).toBeInTheDocument();
      expect(screen.getByText(/record supplier payment/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/amount/i)).toBeInTheDocument();
    });
  });

  it('filters supplier payments when status tabs are clicked', async () => {
    render(<SupplierPaymentsIndex />);

    await waitFor(() => {
      expect(paymentsApi.getPayments).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'outbound' })
      );
    });

    const postedTab = screen.getByRole('button', { name: /^posted$/i });
    fireEvent.click(postedTab);

    await waitFor(() => {
      expect(paymentsApi.getPayments).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'outbound',
          status: 'posted',
        })
      );
    });
  });
});
