import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { FileSpreadsheet, FileText, Loader2 } from 'lucide-react';
import { reportsApi } from '../api/reportsApi';
import type { ReportFormat, ReportType } from '../types/reports.types';

interface ReportExportButtonsProps {
  reportType: ReportType;
  filters?: Record<string, unknown>;
  className?: string;
  pollIntervalMs?: number;
}

export const ReportExportButtons: React.FC<ReportExportButtonsProps> = ({
  reportType,
  filters = {},
  className = '',
  pollIntervalMs = 1500,
}) => {
  const [exportingFormat, setExportingFormat] = useState<ReportFormat | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const pollStatus = async (exportId: string): Promise<string> => {
    const maxAttempts = 40;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      await new Promise((resolve) => setTimeout(resolve, pollIntervalMs));
      const res = await reportsApi.getExportStatus(exportId);
      const data = res.data;

      if (data.status === 'completed' && data.download_url) {
        return data.download_url;
      }

      if (data.status === 'failed') {
        throw new Error(data.error_message || 'Report export failed on worker.');
      }
    }

    throw new Error('Export generation timed out. Please try again.');
  };

  const handleExport = async (format: ReportFormat) => {
    if (exportingFormat !== null) return;

    setExportingFormat(format);
    setErrorMessage(null);

    try {
      const response = await reportsApi.requestExport({
        report_type: reportType,
        format,
        filters,
      });

      const exportId = response.data.id;
      const downloadUrl = await pollStatus(exportId);

      // Trigger download in browser
      const link = document.createElement('a');
      link.href = downloadUrl;
      link.setAttribute('download', '');
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to export report.';
      setErrorMessage(message);
    } finally {
      setExportingFormat(null);
    }
  };

  return (
    <div className={`flex flex-col items-end gap-1 ${className}`}>
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={exportingFormat !== null}
          onClick={() => handleExport('pdf')}
          data-testid="export-pdf-button"
          className="flex items-center gap-1.5 text-xs text-slate-700 hover:text-slate-900 border-slate-300"
        >
          {exportingFormat === 'pdf' ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin text-primary" />
          ) : (
            <FileText className="h-3.5 w-3.5 text-rose-600" />
          )}
          <span>{exportingFormat === 'pdf' ? 'Generating PDF...' : 'Export PDF'}</span>
        </Button>

        <Button
          variant="outline"
          size="sm"
          disabled={exportingFormat !== null}
          onClick={() => handleExport('xlsx')}
          data-testid="export-excel-button"
          className="flex items-center gap-1.5 text-xs text-slate-700 hover:text-slate-900 border-slate-300"
        >
          {exportingFormat === 'xlsx' ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin text-primary" />
          ) : (
            <FileSpreadsheet className="h-3.5 w-3.5 text-emerald-600" />
          )}
          <span>{exportingFormat === 'xlsx' ? 'Generating Excel...' : 'Export Excel'}</span>
        </Button>
      </div>

      {errorMessage && (
        <span className="text-xs text-rose-600 mt-1 max-w-xs text-right" role="alert">
          {errorMessage}
        </span>
      )}
    </div>
  );
};
