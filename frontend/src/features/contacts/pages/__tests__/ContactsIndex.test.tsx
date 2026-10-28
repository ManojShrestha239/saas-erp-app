import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import ContactsIndex from '../ContactsIndex';
import { contactsApi } from '../../api/contactsApi';
import type { ContactItem } from '../../types/contacts.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';

vi.mock('../../api/contactsApi', () => ({
  contactsApi: {
    getContacts: vi.fn(),
    createContact: vi.fn(),
    updateContact: vi.fn(),
    deleteContact: vi.fn(),
    uploadContactCsv: vi.fn(),
    getContactImportStatus: vi.fn(),
    getContactTemplateUrl: vi.fn().mockReturnValue('/api/v1/contacts/import/template'),
  },
}));

const mockContacts: ContactItem[] = [
  {
    id: '01CONTACT0000000000000001',
    type: 'customer',
    type_label: 'Customer',
    code: 'CUST-000001',
    name: 'Himalayan Traders Ltd',
    tax_id: 'PAN-123456789',
    email: 'info@himalayan.com',
    phone: '+977-1-4200000',
    payment_terms_days: 30,
    credit_limit_cents: 5000000,
    opening_balance_cents: 1200000,
    is_active: true,
    notes: 'Key retail distributor',
    billing_address: { city: 'Kathmandu', country: 'Nepal' },
    shipping_address: null,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
  },
  {
    id: '01CONTACT0000000000000002',
    type: 'supplier',
    type_label: 'Supplier',
    code: 'SUPP-000001',
    name: 'Kathmandu Packaging Suppliers',
    tax_id: 'PAN-987654321',
    email: 'orders@ktmpack.com',
    phone: '+977-1-4300000',
    payment_terms_days: 15,
    credit_limit_cents: 2500000,
    opening_balance_cents: 0,
    is_active: false,
    notes: null,
    billing_address: { city: 'Lalitpur', country: 'Nepal' },
    shipping_address: null,
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
  },
];

describe('ContactsIndex', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(contactsApi.getContacts).mockResolvedValue({
      success: true,
      message: 'Contacts retrieved successfully.',
      data: mockContacts,
      meta: {
        page: 1,
        per_page: 25,
        total: 2,
      },
    } as ApiSuccessResponse<ContactItem[]>);
  });

  it('renders contacts directory header and action buttons', async () => {
    render(<ContactsIndex />);

    expect(screen.getByText('Contacts Directory')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Add Contact/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Import CSV/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('Himalayan Traders Ltd')).toBeInTheDocument();
      expect(screen.getByText('Kathmandu Packaging Suppliers')).toBeInTheDocument();
    });

    expect(screen.getByText('CUST-000001')).toBeInTheDocument();
    expect(screen.getByText('SUPP-000001')).toBeInTheDocument();
    expect(screen.getByText(/PAN-123456789/)).toBeInTheDocument();
  });

  it('filters contacts by type tab click', async () => {
    render(<ContactsIndex />);

    await waitFor(() => {
      expect(screen.getByText('Himalayan Traders Ltd')).toBeInTheDocument();
    });

    const customersTab = screen.getByRole('button', { name: 'Customers' });
    fireEvent.click(customersTab);

    await waitFor(() => {
      expect(contactsApi.getContacts).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'customer' })
      );
    });
  });

  it('opens and submits Add Contact dialog', async () => {
    vi.mocked(contactsApi.createContact).mockResolvedValue({
      success: true,
      message: 'Contact created successfully.',
      data: {
        ...mockContacts[0]!,
        id: '01CONTACT0000000000000003',
        name: 'New Nepal Enterprises',
        code: 'CUST-000002',
      },
    } as ApiSuccessResponse<ContactItem>);

    render(<ContactsIndex />);

    const addButton = screen.getByRole('button', { name: /Add Contact/i });
    fireEvent.click(addButton);

    expect(screen.getByRole('heading', { name: 'Create New Contact' })).toBeInTheDocument();

    const nameInput = screen.getByLabelText(/Legal \/ Trading Name/i);
    fireEvent.change(nameInput, { target: { value: 'New Nepal Enterprises' } });

    const submitButton = screen.getByRole('button', { name: 'Create Contact' });
    fireEvent.click(submitButton);

    await waitFor(() => {
      expect(contactsApi.createContact).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'New Nepal Enterprises',
          type: 'customer',
        })
      );
    });
  });
});
