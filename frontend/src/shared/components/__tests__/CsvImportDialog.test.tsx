import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { CsvImportDialog } from '../CsvImportDialog';
import type { CsvImportItem } from '@/shared/types/csvImport.types';

describe('CsvImportDialog', () => {
  const mockOnOpenChange = vi.fn();
  const mockOnUpload = vi.fn();
  const mockOnCheckStatus = vi.fn();
  const mockOnSuccess = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  const baseProps = {
    open: true,
    onOpenChange: mockOnOpenChange,
    title: 'Import Products',
    description: 'Upload product catalog in CSV format.',
    templateUrl: '/api/v1/inventory/products/import/template',
    onUpload: mockOnUpload,
    onCheckStatus: mockOnCheckStatus,
    onSuccess: mockOnSuccess,
    pollIntervalMs: 50,
  };

  it('renders modal with title, template download link, and duplicate strategy options', () => {
    render(<CsvImportDialog {...baseProps} />);

    expect(screen.getByText('Import Products')).toBeInTheDocument();
    expect(screen.getByText('Upload product catalog in CSV format.')).toBeInTheDocument();
    expect(screen.getByText('Download Template')).toBeInTheDocument();
    expect(screen.getByText('Skip Existing')).toBeInTheDocument();
    expect(screen.getByText('Update Existing')).toBeInTheDocument();
    expect(screen.getByText(/Drag and drop your .csv file here/i)).toBeInTheDocument();
  });

  it('rejects non-CSV file selection with validation error', async () => {
    render(<CsvImportDialog {...baseProps} />);

    const invalidFile = new File(['hello'], 'document.pdf', { type: 'application/pdf' });
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;

    fireEvent.change(input, { target: { files: [invalidFile] } });

    expect(await screen.findByText('Please select a valid .csv file.')).toBeInTheDocument();
    expect(mockOnUpload).not.toHaveBeenCalled();
  });

  it('submits valid CSV file and starts status polling until completed', async () => {
    const validFile = new File(['sku,name\nSKU-1,Hammer'], 'products.csv', { type: 'text/csv' });

    const pendingItem: CsvImportItem = {
      id: 'import-ulid-123',
      type: { value: 'products', label: 'Products' },
      file_name: 'products.csv',
      status: { value: 'pending', label: 'Pending' },
      duplicate_action: { value: 'skip', label: 'Skip Existing' },
      total_rows: 2,
      processed_rows: 0,
      successful_rows: 0,
      failed_rows: 0,
      progress_percentage: 0,
      errors: null,
      started_at: null,
      completed_at: null,
      created_at: '2026-10-28T00:00:00Z',
    };

    const completedItem: CsvImportItem = {
      ...pendingItem,
      status: { value: 'completed', label: 'Completed' },
      processed_rows: 2,
      successful_rows: 2,
      failed_rows: 0,
      progress_percentage: 100,
      completed_at: '2026-10-28T00:00:02Z',
    };

    mockOnUpload.mockResolvedValue(pendingItem);
    mockOnCheckStatus.mockResolvedValue(completedItem);

    render(<CsvImportDialog {...baseProps} />);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [validFile] } });

    expect(screen.getByText('products.csv')).toBeInTheDocument();

    const uploadBtn = screen.getByRole('button', { name: /Upload & Import/i });
    fireEvent.click(uploadBtn);

    expect(mockOnUpload).toHaveBeenCalledWith(validFile, 'skip');

    await waitFor(() => {
      expect(screen.getByText('Completed')).toBeInTheDocument();
      expect(screen.getByText('2 of 2 rows processed')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Done/i })).toBeInTheDocument();
    });

    expect(mockOnSuccess).toHaveBeenCalledWith(completedItem);
  });

  it('renders error breakdown table when CSV has failing rows', async () => {
    const validFile = new File(['sku,name\n,Bad Product'], 'products_with_errors.csv', {
      type: 'text/csv',
    });

    const failedItem: CsvImportItem = {
      id: 'import-ulid-456',
      type: { value: 'products', label: 'Products' },
      file_name: 'products_with_errors.csv',
      status: { value: 'completed', label: 'Completed' },
      duplicate_action: { value: 'update', label: 'Update Existing' },
      total_rows: 3,
      processed_rows: 3,
      successful_rows: 2,
      failed_rows: 1,
      progress_percentage: 100,
      errors: [{ row: 2, message: 'Row 2: SKU cannot be empty.' }],
      started_at: null,
      completed_at: '2026-10-28T00:00:02Z',
      created_at: '2026-10-28T00:00:00Z',
    };

    mockOnUpload.mockResolvedValue(failedItem);

    render(<CsvImportDialog {...baseProps} />);

    // Select "Update Existing"
    const updateRadio = screen.getByDisplayValue('update');
    fireEvent.click(updateRadio);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [validFile] } });

    const uploadBtn = screen.getByRole('button', { name: /Upload & Import/i });
    fireEvent.click(uploadBtn);

    expect(mockOnUpload).toHaveBeenCalledWith(validFile, 'update');

    await waitFor(() => {
      expect(screen.getByText('Row Errors (1)')).toBeInTheDocument();
      expect(screen.getByText('Row 2: SKU cannot be empty.')).toBeInTheDocument();
    });
  });

  it('resets state when clicking Import Another File', async () => {
    const validFile = new File(['sku,name\nSKU-1,Hammer'], 'products.csv', { type: 'text/csv' });
    const completedItem: CsvImportItem = {
      id: 'import-ulid-789',
      type: { value: 'products', label: 'Products' },
      file_name: 'products.csv',
      status: { value: 'completed', label: 'Completed' },
      duplicate_action: { value: 'skip', label: 'Skip Existing' },
      total_rows: 1,
      processed_rows: 1,
      successful_rows: 1,
      failed_rows: 0,
      progress_percentage: 100,
      errors: null,
      started_at: null,
      completed_at: '2026-10-28T00:00:02Z',
      created_at: '2026-10-28T00:00:00Z',
    };

    mockOnUpload.mockResolvedValue(completedItem);

    render(<CsvImportDialog {...baseProps} />);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [validFile] } });
    fireEvent.click(screen.getByRole('button', { name: /Upload & Import/i }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Import Another File/i })).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: /Import Another File/i }));

    expect(screen.getByText(/Drag and drop your .csv file here/i)).toBeInTheDocument();
  });
});
