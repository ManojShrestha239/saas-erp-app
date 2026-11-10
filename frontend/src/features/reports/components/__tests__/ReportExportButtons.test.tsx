import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { ReportExportButtons } from '../ReportExportButtons';
import { reportsApi } from '../../api/reportsApi';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';
import type { ReportExportResponse } from '../../types/reports.types';

vi.mock('../../api/reportsApi', () => ({
  reportsApi: {
    requestExport: vi.fn(),
    getExportStatus: vi.fn(),
  },
}));

describe('ReportExportButtons', () => {
  let clickSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.clearAllMocks();
    clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  });

  afterEach(() => {
    clickSpy.mockRestore();
  });

  it('renders Export PDF and Export Excel buttons', () => {
    render(<ReportExportButtons reportType="trial-balance" filters={{ as_of_date: '2026-11-10' }} />);

    expect(screen.getByTestId('export-pdf-button')).toBeInTheDocument();
    expect(screen.getByTestId('export-excel-button')).toBeInTheDocument();
    expect(screen.getByText('Export PDF')).toBeInTheDocument();
    expect(screen.getByText('Export Excel')).toBeInTheDocument();
  });

  it('handles PDF export flow successfully', async () => {
    const mockCreated: ApiSuccessResponse<ReportExportResponse> = {
      success: true,
      message: 'Report export queued successfully.',
      data: {
        id: 'export-123',
        report_type: 'trial-balance',
        format: 'pdf',
        status: 'queued',
      },
    };

    const mockCompleted: ApiSuccessResponse<ReportExportResponse> = {
      success: true,
      message: 'Report export status retrieved.',
      data: {
        id: 'export-123',
        report_type: 'trial-balance',
        format: 'pdf',
        status: 'completed',
        download_url: 'https://test.nexuserp.test/api/v1/reports/exports/export-123/download?signature=sig123',
      },
    };

    vi.mocked(reportsApi.requestExport).mockResolvedValueOnce(mockCreated);
    vi.mocked(reportsApi.getExportStatus).mockResolvedValueOnce(mockCompleted);

    render(
      <ReportExportButtons
        reportType="trial-balance"
        filters={{ as_of_date: '2026-11-10' }}
        pollIntervalMs={10}
      />
    );

    fireEvent.click(screen.getByTestId('export-pdf-button'));

    expect(reportsApi.requestExport).toHaveBeenCalledWith({
      report_type: 'trial-balance',
      format: 'pdf',
      filters: { as_of_date: '2026-11-10' },
    });

    await waitFor(() => {
      expect(reportsApi.getExportStatus).toHaveBeenCalledWith('export-123');
    });

    await waitFor(() => {
      expect(screen.getByText('Export PDF')).toBeInTheDocument();
      expect(clickSpy).toHaveBeenCalled();
    });
  });

  it('handles Excel export flow successfully', async () => {
    const mockCreated: ApiSuccessResponse<ReportExportResponse> = {
      success: true,
      message: 'Report export queued successfully.',
      data: {
        id: 'export-456',
        report_type: 'stock-valuation',
        format: 'xlsx',
        status: 'queued',
      },
    };

    const mockCompleted: ApiSuccessResponse<ReportExportResponse> = {
      success: true,
      message: 'Report export status retrieved.',
      data: {
        id: 'export-456',
        report_type: 'stock-valuation',
        format: 'xlsx',
        status: 'completed',
        download_url: 'https://test.nexuserp.test/api/v1/reports/exports/export-456/download?signature=sig456',
      },
    };

    vi.mocked(reportsApi.requestExport).mockResolvedValueOnce(mockCreated);
    vi.mocked(reportsApi.getExportStatus).mockResolvedValueOnce(mockCompleted);

    render(
      <ReportExportButtons
        reportType="stock-valuation"
        filters={{ warehouse_id: 'wh-1' }}
        pollIntervalMs={10}
      />
    );

    fireEvent.click(screen.getByTestId('export-excel-button'));

    expect(reportsApi.requestExport).toHaveBeenCalledWith({
      report_type: 'stock-valuation',
      format: 'xlsx',
      filters: { warehouse_id: 'wh-1' },
    });

    await waitFor(() => {
      expect(reportsApi.getExportStatus).toHaveBeenCalledWith('export-456');
    });

    await waitFor(() => {
      expect(screen.getByText('Export Excel')).toBeInTheDocument();
      expect(clickSpy).toHaveBeenCalled();
    });
  });

  it('displays error message when export fails on worker', async () => {
    const mockCreated: ApiSuccessResponse<ReportExportResponse> = {
      success: true,
      message: 'Report export queued successfully.',
      data: {
        id: 'export-err',
        report_type: 'profit-and-loss',
        format: 'pdf',
        status: 'queued',
      },
    };

    const mockFailed: ApiSuccessResponse<ReportExportResponse> = {
      success: true,
      message: 'Report export status retrieved.',
      data: {
        id: 'export-err',
        report_type: 'profit-and-loss',
        format: 'pdf',
        status: 'failed',
        error_message: 'Browsershot failed to launch Chromium.',
      },
    };

    vi.mocked(reportsApi.requestExport).mockResolvedValueOnce(mockCreated);
    vi.mocked(reportsApi.getExportStatus).mockResolvedValueOnce(mockFailed);

    render(
      <ReportExportButtons
        reportType="profit-and-loss"
        pollIntervalMs={10}
      />
    );

    fireEvent.click(screen.getByTestId('export-pdf-button'));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Browsershot failed to launch Chromium.');
    });
  });
});
