import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import ReportsIndex from '../ReportsIndex';
import { reportsApi } from '../../api/reportsApi';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type {
  TrialBalanceData,
  ProfitAndLossData,
  BalanceSheetData,
  ArAgingData,
  StockValuationData,
  StockMovementData,
} from '../../types/reports.types';

vi.mock('../../api/reportsApi', () => ({
  reportsApi: {
    getTrialBalance: vi.fn(),
    getProfitAndLoss: vi.fn(),
    getBalanceSheet: vi.fn(),
    getArAging: vi.fn(),
    getApAging: vi.fn(),
    getStockValuation: vi.fn(),
    getStockMovements: vi.fn(),
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    props: {
      auth: {
        user: {
          id: '01TEST001',
          name: 'Accountant User',
          email: 'accountant@acme.test',
          role: 'accountant',
          roles: ['accountant'],
          permissions: ['reports.financial', 'reports.stock'],
        },
      },
      tenant: { slug: 'acme-corp' },
    },
  }),
  Link: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const mockTrialBalance: TrialBalanceData = {
  as_of_date: '2026-11-10',
  accounts: [
    {
      account_id: '01ACC01',
      code: '1110',
      name: 'Cash on Hand',
      type: 'asset',
      debit_balance_cents: 100000,
      credit_balance_cents: 0,
      net_delta_cents: 100000,
    },
    {
      account_id: '01ACC02',
      code: '4110',
      name: 'Sales Revenue',
      type: 'income',
      debit_balance_cents: 0,
      credit_balance_cents: 100000,
      net_delta_cents: 100000,
    },
  ],
  total_debits_cents: 100000,
  total_credits_cents: 100000,
  is_balanced: true,
  net_difference_cents: 0,
};

const mockProfitAndLoss: ProfitAndLossData = {
  start_date: '2026-01-01',
  end_date: '2026-11-10',
  revenue_accounts: [
    {
      account_id: '01ACC02',
      code: '4110',
      name: 'Sales Revenue',
      amount_cents: 150000,
    },
  ],
  total_revenue_cents: 150000,
  cogs_accounts: [
    {
      account_id: '01ACC03',
      code: '5100',
      name: 'Cost of Goods Sold',
      amount_cents: 60000,
    },
  ],
  total_cogs_cents: 60000,
  gross_profit_cents: 90000,
  expense_accounts: [
    {
      account_id: '01ACC04',
      code: '6100',
      name: 'Office Rent',
      amount_cents: 20000,
    },
  ],
  total_operating_expenses_cents: 20000,
  net_income_cents: 70000,
};

const mockBalanceSheet: BalanceSheetData = {
  as_of_date: '2026-11-10',
  assets: {
    current: [
      {
        account_id: '01ACC01',
        code: '1110',
        name: 'Operating Cash',
        amount_cents: 120000,
      },
    ],
    non_current: [],
    total_cents: 120000,
  },
  liabilities: {
    current: [
      {
        account_id: '01ACC05',
        code: '2110',
        name: 'Accounts Payable',
        amount_cents: 50000,
      },
    ],
    non_current: [],
    total_cents: 50000,
  },
  equity: {
    accounts: [],
    current_period_earnings_cents: 70000,
    total_cents: 70000,
  },
  total_liabilities_and_equity_cents: 120000,
  is_balanced: true,
  net_difference_cents: 0,
};

const mockArAging: ArAgingData = {
  as_of_date: '2026-11-10',
  summary: {
    current_cents: 40000,
    days_1_30_cents: 10000,
    days_31_60_cents: 0,
    days_61_90_cents: 0,
    days_over_90_cents: 0,
    total_due_cents: 50000,
  },
  customers: [
    {
      customer_id: '01CUST01',
      customer_code: 'CUST-001',
      customer_name: 'Alpha Retailers',
      current_cents: 40000,
      days_1_30_cents: 10000,
      days_31_60_cents: 0,
      days_61_90_cents: 0,
      days_over_90_cents: 0,
      total_due_cents: 50000,
      invoice_count: 2,
    },
  ],
  gl_control_account_cents: 50000,
  reconciliation_difference_cents: 0,
  is_reconciled: true,
};

const mockStockValuation: StockValuationData = {
  as_of_date: '2026-11-10',
  summary: {
    total_valuation_cents: 80000,
    total_units_on_hand: 16,
    total_products_count: 1,
    valued_products_count: 1,
    currency: 'NPR',
    gl_control_account_cents: 80000,
    reconciliation_difference_cents: 0,
    is_reconciled: true,
  },
  items: [
    {
      product_id: '01PROD01',
      sku: 'TEA-01',
      name: 'Organic Green Tea',
      category_name: 'Beverages',
      unit_of_measure_name: 'Kg',
      total_quantity_on_hand: 16,
      moving_average_cost_cents: 5000,
      selling_price_cents: 12000,
      total_valuation_cents: 80000,
      margin_percent: 58,
      is_active: true,
      warehouse_breakdown: [],
    },
  ],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 1,
  },
};

const mockStockMovements: StockMovementData = {
  items: [
    {
      id: '01MOV01',
      warehouse_id: '01WH01',
      product_id: '01PROD01',
      type: 'purchase_received',
      type_label: 'Purchase Received',
      quantity: 16,
      unit_cost_cents: 5000,
      source_type: 'purchase_order',
      source_id: '01PO01',
      notes: 'Initial receipt from vendor',
      created_at: '2026-11-10T08:00:00Z',
      product: { id: '01PROD01', name: 'Organic Green Tea', sku: 'TEA-01' },
      warehouse: { id: '01WH01', name: 'Central Warehouse', code: 'WH-01' },
    },
  ],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 1,
  },
};

describe('ReportsIndex Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(reportsApi.getTrialBalance).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockTrialBalance,
    } as ApiSuccessResponse<TrialBalanceData>);

    vi.mocked(reportsApi.getProfitAndLoss).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockProfitAndLoss,
    } as ApiSuccessResponse<ProfitAndLossData>);

    vi.mocked(reportsApi.getBalanceSheet).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockBalanceSheet,
    } as ApiSuccessResponse<BalanceSheetData>);

    vi.mocked(reportsApi.getArAging).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockArAging,
    } as ApiSuccessResponse<ArAgingData>);

    vi.mocked(reportsApi.getStockValuation).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockStockValuation,
    } as ApiSuccessResponse<StockValuationData>);

    vi.mocked(reportsApi.getStockMovements).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockStockMovements,
    } as ApiSuccessResponse<StockMovementData>);
  });

  it('renders report navigation tabs', async () => {
    render(<ReportsIndex />);

    expect(screen.getByRole('button', { name: /Trial Balance/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Profit & Loss/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Balance Sheet/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /AR & AP Aging/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Stock Valuation/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Stock Movements/i })).toBeInTheDocument();
  });

  it('renders Trial Balance by default and displays account rows', async () => {
    render(<ReportsIndex />);

    await waitFor(() => {
      expect(reportsApi.getTrialBalance).toHaveBeenCalled();
      expect(screen.getByText('Cash on Hand')).toBeInTheDocument();
      expect(screen.getByText('Sales Revenue')).toBeInTheDocument();
      expect(screen.getByText(/Balanced \(Net Diff: 0\.00\)/i)).toBeInTheDocument();
    });
  });

  it('switches to Profit & Loss tab when clicked', async () => {
    render(<ReportsIndex />);

    const pnlTab = screen.getByRole('button', { name: /Profit & Loss/i });
    fireEvent.click(pnlTab);

    await waitFor(() => {
      expect(reportsApi.getProfitAndLoss).toHaveBeenCalled();
      expect(screen.getByText('Profit & Loss Statement')).toBeInTheDocument();
      expect(screen.getByText('Gross Profit (Revenue - COGS)')).toBeInTheDocument();
      expect(screen.getByText('Office Rent')).toBeInTheDocument();
    });
  });

  it('switches to Balance Sheet tab and verifies accounting equilibrium', async () => {
    render(<ReportsIndex />);

    const bsTab = screen.getByRole('button', { name: /Balance Sheet/i });
    fireEvent.click(bsTab);

    await waitFor(() => {
      expect(reportsApi.getBalanceSheet).toHaveBeenCalled();
      expect(screen.getByText('Assets = Liab + Equity')).toBeInTheDocument();
      expect(screen.getByText('Operating Cash')).toBeInTheDocument();
      expect(screen.getByText('Accounts Payable')).toBeInTheDocument();
      expect(screen.getByText(/Total Liabilities & Equity/i)).toBeInTheDocument();
    });
  });

  it('switches to AR & AP Aging tab and displays control account reconciliation', async () => {
    render(<ReportsIndex />);

    const agingTab = screen.getByRole('button', { name: /AR & AP Aging/i });
    fireEvent.click(agingTab);

    await waitFor(() => {
      expect(reportsApi.getArAging).toHaveBeenCalled();
      expect(screen.getByText(/Control Account Reconciliation: Fully Reconciled/i)).toBeInTheDocument();
      expect(screen.getByText('Alpha Retailers')).toBeInTheDocument();
      expect(screen.getByText('CUST-001')).toBeInTheDocument();
    });
  });

  it('switches to Stock Valuation tab and renders product valuation', async () => {
    render(<ReportsIndex />);

    const stockTab = screen.getByRole('button', { name: /Stock Valuation/i });
    fireEvent.click(stockTab);

    await waitFor(() => {
      expect(reportsApi.getStockValuation).toHaveBeenCalled();
      expect(screen.getByText('Stock Valuation Report')).toBeInTheDocument();
      expect(screen.getByText('Organic Green Tea')).toBeInTheDocument();
      expect(screen.getByText('TEA-01')).toBeInTheDocument();
    });
  });

  it('switches to Stock Movements tab and renders chronological movement history', async () => {
    render(<ReportsIndex />);

    const movementsTab = screen.getByRole('button', { name: /Stock Movements/i });
    fireEvent.click(movementsTab);

    await waitFor(() => {
      expect(reportsApi.getStockMovements).toHaveBeenCalled();
      expect(screen.getByText('Stock Movement History')).toBeInTheDocument();
      expect(screen.getByText('Initial receipt from vendor')).toBeInTheDocument();
      expect(screen.getByText('+16')).toBeInTheDocument();
    });
  });
});
