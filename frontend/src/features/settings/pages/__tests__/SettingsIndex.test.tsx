import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import SettingsIndex from '../SettingsIndex';
import { settingsApi } from '../../api/settingsApi';
import type { TenantSettingsDictionary } from '../../types/settings.types';

vi.mock('../../api/settingsApi', () => ({
  settingsApi: {
    getSettings: vi.fn(),
    updateSettings: vi.fn(),
  },
}));

const mockInitialSettings: TenantSettingsDictionary = {
  company_name: 'Acme Global Traders Ltd.',
  company_tax_id: 'PAN-123456789',
  company_email: 'finance@acmeglobal.test',
  company_phone: '+977-1-4200000',
  company_address: '123 Market Street, Kathmandu',
  bank_details: 'Standard Chartered Bank, Acc: 01020304',
  currency: 'NPR',
  timezone: 'Asia/Kathmandu',
  date_format: 'Y-m-d',
  fiscal_year_start: '07-16',
  allow_negative_stock: false,
  low_stock_threshold_default: '15',
  invoice_prefix: 'INV',
  bill_prefix: 'BILL',
  quotation_prefix: 'QUO',
  po_prefix: 'PO',
};

describe('SettingsIndex Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders settings page with initial values in company profile tab', () => {
    render(<SettingsIndex settings={mockInitialSettings} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Settings' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Company Profile/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Financial & Localization/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Inventory Policies/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Document Sequences/i })).toBeInTheDocument();

    const companyNameInput = screen.getByLabelText(/Company Legal Name/i) as HTMLInputElement;
    expect(companyNameInput.value).toBe('Acme Global Traders Ltd.');

    const taxInput = screen.getByLabelText(/Tax \/ VAT Registration Number/i) as HTMLInputElement;
    expect(taxInput.value).toBe('PAN-123456789');
  });

  it('switches between tabs and displays the respective configuration sections', () => {
    render(<SettingsIndex settings={mockInitialSettings} />);

    // 1. Switch to Financial & Localization
    fireEvent.click(screen.getByRole('button', { name: /Financial & Localization/i }));
    expect(screen.getByLabelText(/Base Currency/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Default Timezone/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Fiscal Year Start/i)).toBeInTheDocument();

    // 2. Switch to Inventory Policies
    fireEvent.click(screen.getByRole('button', { name: /Inventory Policies/i }));
    expect(screen.getByLabelText(/Allow Negative Stock/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Default Low Stock Alert Threshold/i)).toBeInTheDocument();

    // 3. Switch to Document Sequences
    fireEvent.click(screen.getByRole('button', { name: /Document Sequences/i }));
    expect(screen.getByLabelText(/Sales Invoice Prefix/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Supplier Bill Prefix/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Sales Quotation Prefix/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Purchase Order Prefix/i)).toBeInTheDocument();
  });

  it('submits updated settings successfully when form is submitted', async () => {
    vi.mocked(settingsApi.updateSettings).mockResolvedValueOnce({
      success: true,
      message: 'Tenant settings saved successfully.',
      data: {
        ...mockInitialSettings,
        company_name: 'Acme International Traders',
      },
    });

    render(<SettingsIndex settings={mockInitialSettings} />);

    const companyNameInput = screen.getByLabelText(/Company Legal Name/i);
    fireEvent.change(companyNameInput, { target: { value: 'Acme International Traders' } });

    const submitBtn = screen.getByRole('button', { name: /Save Settings/i });
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(settingsApi.updateSettings).toHaveBeenCalledTimes(1);
    });

    expect(settingsApi.updateSettings).toHaveBeenCalledWith(
      expect.objectContaining({
        company_name: 'Acme International Traders',
        currency: 'NPR',
      })
    );

    await waitFor(() => {
      expect(screen.getByText('Tenant settings saved successfully.')).toBeInTheDocument();
    });
  });

  it('displays error message when updateSettings fails', async () => {
    vi.mocked(settingsApi.updateSettings).mockRejectedValueOnce(
      new Error('Validation failed for currency format.')
    );

    render(<SettingsIndex settings={mockInitialSettings} />);

    const submitBtn = screen.getByRole('button', { name: /Save Settings/i });
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(screen.getByText('Validation failed for currency format.')).toBeInTheDocument();
    });
  });

  it('fetches settings from API if no initial settings are provided', async () => {
    vi.mocked(settingsApi.getSettings).mockResolvedValueOnce({
      success: true,
      message: 'Settings loaded',
      data: mockInitialSettings,
    });

    render(<SettingsIndex settings={{}} />);

    await waitFor(() => {
      expect(settingsApi.getSettings).toHaveBeenCalledTimes(1);
    });

    const companyNameInput = screen.getByLabelText(/Company Legal Name/i) as HTMLInputElement;
    await waitFor(() => {
      expect(companyNameInput.value).toBe('Acme Global Traders Ltd.');
    });
  });
});
