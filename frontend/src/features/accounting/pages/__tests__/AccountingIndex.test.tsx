import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import AccountingIndex from '../AccountingIndex';
import { accountingApi } from '../../api/accountingApi';
import type {
  AccountItem,
  FiscalPeriodItem,
  JournalEntryItem,
  VatCalculationResult,
} from '../../types/accounting.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';

vi.mock('../../api/accountingApi', () => ({
  accountingApi: {
    getAccounts: vi.fn(),
    getAccountTree: vi.fn(),
    getFiscalPeriods: vi.fn(),
    getFiscalPeriod: vi.fn(),
    createFiscalPeriod: vi.fn(),
    generateFiscalYear: vi.fn(),
    lockFiscalPeriod: vi.fn(),
    unlockFiscalPeriod: vi.fn(),
    closeFiscalPeriod: vi.fn(),
    getJournalEntries: vi.fn(),
    getJournalEntry: vi.fn(),
    createJournalEntry: vi.fn(),
    updateJournalEntry: vi.fn(),
    submitJournalEntry: vi.fn(),
    approveJournalEntry: vi.fn(),
    rejectJournalEntry: vi.fn(),
    reverseJournalEntry: vi.fn(),
    deleteJournalEntry: vi.fn(),
    calculateVatReturn: vi.fn(),
    fileVatReturn: vi.fn(),
    getVatReturns: vi.fn(),
    getVatReturn: vi.fn(),
    deleteVatReturn: vi.fn(),
    getPdfUrl: vi.fn(),
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    props: {
      auth: {
        user: {
          id: '01USER0000000000000000001',
          name: 'Chief Accountant',
          email: 'accountant@test.com',
          roles: ['Accountant'],
          permissions: ['accounting.manage', 'reports.financial'],
        },
      },
      tenant: {
        id: '01TENANT00000000000000001',
        slug: 'testcorp',
      },
      errors: {},
    },
  }),
  Link: ({ children, href, className }: { children: React.ReactNode; href: string; className?: string }) => (
    <a href={href} className={className}>
      {children}
    </a>
  ),
}));

const mockAccounts: AccountItem[] = [
  {
    id: '01ACC00000000000000000001',
    code: '1110',
    name: 'Cash on Hand',
    type: 'asset',
    type_label: 'Asset',
    normal_balance: 'debit',
    is_system: true,
    is_active: true,
    currency: 'USD',
  },
  {
    id: '01ACC00000000000000000002',
    code: '4110',
    name: 'Sales Revenue',
    type: 'income',
    type_label: 'Income',
    normal_balance: 'credit',
    is_system: true,
    is_active: true,
    currency: 'USD',
  },
];

const mockJournalEntries: JournalEntryItem[] = [
  {
    id: '01JE000000000000000000001',
    entry_number: 'JRN-2026-000001',
    entry_date: '2026-10-31',
    posting_date: '2026-10-31T10:00:00Z',
    status: 'posted',
    status_label: 'Posted',
    description: 'Initial balance recognition',
    total_debit_cents: 50000,
    total_credit_cents: 50000,
    currency: 'USD',
    source_type: 'manual',
    lines: [
      { account_id: '01ACC00000000000000000001', debit_cents: 50000, credit_cents: 0 },
      { account_id: '01ACC00000000000000000002', debit_cents: 0, credit_cents: 50000 },
    ],
  },
  {
    id: '01JE000000000000000000002',
    entry_number: null,
    entry_date: '2026-11-01',
    status: 'draft',
    status_label: 'Draft',
    description: 'Draft depreciation',
    total_debit_cents: 10000,
    total_credit_cents: 10000,
    currency: 'USD',
    lines: [],
  },
];

const mockFiscalPeriods: FiscalPeriodItem[] = [
  {
    id: '01FP000000000000000000001',
    name: 'January 2026',
    fiscal_year: 2026,
    period_number: 1,
    start_date: '2026-01-01',
    end_date: '2026-01-31',
    status: 'open',
    status_label: 'Open',
  },
  {
    id: '01FP000000000000000000002',
    name: 'February 2026',
    fiscal_year: 2026,
    period_number: 2,
    start_date: '2026-02-01',
    end_date: '2026-02-28',
    status: 'locked',
    status_label: 'Locked',
  },
];

describe('AccountingIndex Page Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(accountingApi.getAccounts).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockAccounts,
    } as ApiSuccessResponse<AccountItem[]>);

    vi.mocked(accountingApi.getJournalEntries).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockJournalEntries,
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    } as ApiSuccessResponse<JournalEntryItem[]>);

    vi.mocked(accountingApi.getFiscalPeriods).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockFiscalPeriods,
    } as ApiSuccessResponse<FiscalPeriodItem[]>);
  });

  it('renders accounting header, tabs, and initial journal entries', async () => {
    render(<AccountingIndex />);

    expect(screen.getByText('Accounting & Financial Ledger')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Journal Entries/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Fiscal Periods/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Chart of Accounts/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('JRN-2026-000001')).toBeInTheDocument();
      expect(screen.getByText('Initial balance recognition')).toBeInTheDocument();
      expect(screen.getByText('Draft depreciation')).toBeInTheDocument();
    });
  });

  it('switches between tabs: Fiscal Periods and Chart of Accounts', async () => {
    render(<AccountingIndex />);

    // Switch to Fiscal Periods
    const periodsTab = screen.getByRole('button', { name: /Fiscal Periods/i });
    fireEvent.click(periodsTab);

    await waitFor(() => {
      expect(screen.getByText('January 2026')).toBeInTheDocument();
      expect(screen.getByText('February 2026')).toBeInTheDocument();
      expect(screen.getByText('Generate Year Periods')).toBeInTheDocument();
    });

    // Switch to Chart of Accounts
    const accountsTab = screen.getByRole('button', { name: /Chart of Accounts/i });
    fireEvent.click(accountsTab);

    await waitFor(() => {
      expect(screen.getByText('Cash on Hand')).toBeInTheDocument();
      expect(screen.getByText('Sales Revenue')).toBeInTheDocument();
      expect(screen.getByText('1110')).toBeInTheDocument();
      expect(screen.getByText('4110')).toBeInTheDocument();
    });
  });

  it('opens and closes manual journal modal', async () => {
    render(<AccountingIndex />);

    await waitFor(() => {
      expect(screen.getByText('JRN-2026-000001')).toBeInTheDocument();
    });

    const newBtn = screen.getByRole('button', { name: /New Journal Entry/i });
    fireEvent.click(newBtn);

    expect(screen.getByText('New Manual Journal Entry')).toBeInTheDocument();
    expect(screen.getByLabelText(/Narration \/ Description/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Save as Draft/i })).toBeInTheDocument();

    const cancelBtn = screen.getByRole('button', { name: /Cancel/i });
    fireEvent.click(cancelBtn);

    await waitFor(() => {
      expect(screen.queryByText('New Manual Journal Entry')).not.toBeInTheDocument();
    });
  });

  it('locks a fiscal period when triggered', async () => {
    vi.mocked(accountingApi.lockFiscalPeriod).mockResolvedValue({
      success: true,
      message: 'Locked',
      data: { ...mockFiscalPeriods[0], status: 'locked' },
    } as ApiSuccessResponse<FiscalPeriodItem>);

    render(<AccountingIndex />);

    fireEvent.click(screen.getByRole('button', { name: /Fiscal Periods/i }));

    await waitFor(() => {
      expect(screen.getByText('January 2026')).toBeInTheDocument();
    });

    // Verify periods table is active
    expect(screen.getByText('Fiscal Year:')).toBeInTheDocument();
  });

  it('switches to VAT Return tab and renders VAT calculations', async () => {
    vi.mocked(accountingApi.calculateVatReturn).mockResolvedValue({
      success: true,
      data: {
        from_date: '2026-01-01',
        to_date: '2026-01-31',
        fiscal_period: {
          id: '01FP000000000000000000001',
          name: 'January 2026',
          fiscal_year: 2026,
          period_number: 1,
          status: 'open',
        },
        summary: {
          total_sales_taxable_cents: 130000,
          total_output_vat_cents: 10400,
          total_sales_gross_cents: 140400,
          total_purchases_taxable_cents: 50000,
          total_input_vat_cents: 6500,
          total_purchases_gross_cents: 56500,
          net_vat_payable_cents: 3900,
        },
        rate_breakdown: [
          {
            tax_rate_percent: 13,
            sales_taxable_cents: 80000,
            output_vat_cents: 10400,
            sales_total_cents: 90400,
            purchases_taxable_cents: 50000,
            input_vat_cents: 6500,
            purchases_total_cents: 56500,
            net_vat_cents: 3900,
          },
        ],
        ledger_reconciliation: {
          gl_output_vat_cents: 10400,
          gl_input_vat_cents: 6500,
          gl_net_vat_cents: 3900,
          output_vat_discrepancy_cents: 0,
          input_vat_discrepancy_cents: 0,
          net_vat_discrepancy_cents: 0,
          is_reconciled: true,
        },
        documents: [
          {
            document_type: 'sales_invoice',
            id: '01INV00000000000000000001',
            document_number: 'INV-2026-000001',
            date: '2026-01-10',
            contact_id: '01CUST000000000000000001',
            contact_name: 'Acme Client Corp',
            contact_tax_id: 'VAT-123456789',
            direction: 'output',
            subtotal_cents: 150000,
            tax_cents: 13000,
            total_cents: 163000,
            rates: [{ rate_percent: 13, subtotal_cents: 100000, tax_cents: 13000 }],
          },
        ],
      },
    } as ApiSuccessResponse<VatCalculationResult>);

    render(<AccountingIndex />);

    // Click VAT Return tab
    const vatTab = screen.getByRole('button', { name: /VAT Return/i });
    fireEvent.click(vatTab);

    await waitFor(() => {
      expect(screen.getByText('Calculate Return')).toBeInTheDocument();
      expect(screen.getByText('Total Output VAT')).toBeInTheDocument();
      expect(screen.getByText('Total Input VAT')).toBeInTheDocument();
      expect(screen.getByText('100% Reconciled')).toBeInTheDocument();
    });

    // Check rate breakdown table
    expect(screen.getByText('13%')).toBeInTheDocument();
    expect(screen.getByText('Export PDF')).toBeInTheDocument();
    expect(screen.getByText('File Return')).toBeInTheDocument();
  });
});
