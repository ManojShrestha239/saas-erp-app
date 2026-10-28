export type CsvImportStatus = 'pending' | 'processing' | 'completed' | 'failed';
export type CsvImportType = 'products' | 'contacts';
export type CsvDuplicateAction = 'skip' | 'update';

export interface CsvImportErrorItem {
  row: number;
  message: string;
  raw_data?: Record<string, unknown>;
}

export interface CsvImportItem {
  id: string;
  type: {
    value: CsvImportType;
    label: string;
  };
  file_name: string;
  status: {
    value: CsvImportStatus;
    label: string;
  };
  duplicate_action: {
    value: CsvDuplicateAction;
    label: string;
  };
  total_rows: number;
  processed_rows: number;
  successful_rows: number;
  failed_rows: number;
  progress_percentage: number;
  errors: CsvImportErrorItem[] | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
}
