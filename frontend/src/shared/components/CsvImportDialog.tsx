import * as React from 'react';
import {
  Dialog,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
  Upload,
  FileSpreadsheet,
  Download,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Loader2,
  RefreshCw,
} from 'lucide-react';
import type {
  CsvDuplicateAction,
  CsvImportItem,
  CsvImportStatus,
} from '@/shared/types/csvImport.types';

export interface CsvImportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  templateUrl: string;
  onUpload: (file: File, duplicateAction: CsvDuplicateAction) => Promise<CsvImportItem>;
  onCheckStatus: (importId: string) => Promise<CsvImportItem>;
  onSuccess?: (item: CsvImportItem) => void;
  pollIntervalMs?: number;
}

export function CsvImportDialog({
  open,
  onOpenChange,
  title,
  description = 'Upload a CSV file to bulk import data into your workspace.',
  templateUrl,
  onUpload,
  onCheckStatus,
  onSuccess,
  pollIntervalMs = 1200,
}: CsvImportDialogProps) {
  const [file, setFile] = React.useState<File | null>(null);
  const [duplicateAction, setDuplicateAction] = React.useState<CsvDuplicateAction>('skip');
  const [isSubmitting, setIsSubmitting] = React.useState<boolean>(false);
  const [importJob, setImportJob] = React.useState<CsvImportItem | null>(null);
  const [errorMessage, setErrorMessage] = React.useState<string | null>(null);
  const [isDragging, setIsDragging] = React.useState<boolean>(false);

  const fileInputRef = React.useRef<HTMLInputElement | null>(null);
  const pollingRef = React.useRef<NodeJS.Timeout | null>(null);

  const stopPolling = React.useCallback(() => {
    if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }
  }, []);

  const resetState = React.useCallback(() => {
    stopPolling();
    setFile(null);
    setDuplicateAction('skip');
    setIsSubmitting(false);
    setImportJob(null);
    setErrorMessage(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  }, [stopPolling]);

  React.useEffect(() => {
    if (!open) {
      resetState();
    }
    return () => stopPolling();
  }, [open, resetState, stopPolling]);

  const pollStatus = React.useCallback(
    (importId: string) => {
      stopPolling();
      pollingRef.current = setInterval(async () => {
        try {
          const updated = await onCheckStatus(importId);
          setImportJob(updated);

          const status = updated.status.value;
          if (status === 'completed' || status === 'failed') {
            stopPolling();
            setIsSubmitting(false);
            if (status === 'completed' && onSuccess) {
              onSuccess(updated);
            }
          }
        } catch (err: unknown) {
          stopPolling();
          setIsSubmitting(false);
          const msg = err instanceof Error ? err.message : 'Error while tracking import status';
          setErrorMessage(msg);
        }
      }, pollIntervalMs);
    },
    [onCheckStatus, onSuccess, pollIntervalMs, stopPolling]
  );

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0];
    if (!selected) return;

    if (!selected.name.endsWith('.csv') && selected.type !== 'text/csv') {
      setErrorMessage('Please select a valid .csv file.');
      return;
    }

    setErrorMessage(null);
    setFile(selected);
  };

  const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setIsDragging(false);

    const dropped = e.dataTransfer.files?.[0];
    if (!dropped) return;

    if (!dropped.name.endsWith('.csv') && dropped.type !== 'text/csv') {
      setErrorMessage('Please select a valid .csv file.');
      return;
    }

    setErrorMessage(null);
    setFile(dropped);
  };

  const handleDragOver = (e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setIsDragging(true);
  };

  const handleDragLeave = () => {
    setIsDragging(false);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) {
      setErrorMessage('Please select a CSV file to upload.');
      return;
    }

    try {
      setIsSubmitting(true);
      setErrorMessage(null);

      const job = await onUpload(file, duplicateAction);
      setImportJob(job);

      if (job.status.value === 'completed') {
        setIsSubmitting(false);
        if (onSuccess) onSuccess(job);
      } else if (job.status.value === 'failed') {
        setIsSubmitting(false);
      } else {
        pollStatus(job.id);
      }
    } catch (err: unknown) {
      setIsSubmitting(false);
      const msg = err instanceof Error ? err.message : 'Failed to upload CSV file.';
      setErrorMessage(msg);
    }
  };

  const currentStatus: CsvImportStatus | null = importJob?.status.value ?? null;
  const isFinished = currentStatus === 'completed' || currentStatus === 'failed';
  const hasErrors = (importJob?.errors?.length ?? 0) > 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <FileSpreadsheet className="h-5 w-5 text-primary" />
          <span>{title}</span>
        </DialogTitle>
        <DialogDescription>{description}</DialogDescription>
      </DialogHeader>

      {errorMessage && (
        <Alert variant="destructive" className="mb-4">
          <AlertTriangle className="h-4 w-4" />
          <AlertDescription>{errorMessage}</AlertDescription>
        </Alert>
      )}

      {/* STEP 1: Upload and Configuration Form */}
      {!importJob && (
        <form onSubmit={handleSubmit} className="space-y-5">
          {/* Template Download Prompt */}
          <div className="flex items-center justify-between rounded-md border border-border bg-muted/40 p-3 text-xs">
            <span className="text-muted-foreground">Need a sample format with standard headers?</span>
            <a
              href={templateUrl}
              download
              className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
            >
              <Download className="h-3.5 w-3.5" />
              Download Template
            </a>
          </div>

          {/* Drag & Drop File Area */}
          <div
            onDrop={handleDrop}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onClick={() => fileInputRef.current?.click()}
            className={`flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 text-center cursor-pointer transition-colors ${
              isDragging
                ? 'border-primary bg-primary/10'
                : 'border-border/80 hover:border-primary/50 bg-background'
            }`}
          >
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv,text/csv"
              onChange={handleFileChange}
              className="hidden"
            />
            <Upload className="h-8 w-8 text-muted-foreground mb-2" />
            {file ? (
              <div>
                <p className="text-sm font-semibold text-foreground">{file.name}</p>
                <p className="text-xs text-muted-foreground">
                  {(file.size / 1024).toFixed(1)} KB — Click to change file
                </p>
              </div>
            ) : (
              <div>
                <p className="text-sm font-medium text-foreground">
                  Drag and drop your .csv file here, or{' '}
                  <span className="text-primary underline">browse</span>
                </p>
                <p className="text-xs text-muted-foreground mt-1">UTF-8 encoded CSV files up to 10MB</p>
              </div>
            )}
          </div>

          {/* Duplicate Strategy Option */}
          <div className="space-y-2">
            <label className="text-xs font-semibold text-foreground">Existing Records Handling</label>
            <div className="grid grid-cols-2 gap-3">
              <label
                className={`flex cursor-pointer items-start gap-2.5 rounded-md border p-3 text-left transition-colors ${
                  duplicateAction === 'skip'
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:bg-muted/30'
                }`}
              >
                <input
                  type="radio"
                  name="duplicateAction"
                  value="skip"
                  checked={duplicateAction === 'skip'}
                  onChange={() => setDuplicateAction('skip')}
                  className="mt-0.5"
                />
                <div className="text-xs">
                  <span className="font-medium text-foreground">Skip Existing</span>
                  <p className="text-muted-foreground mt-0.5">Keep existing records unchanged</p>
                </div>
              </label>

              <label
                className={`flex cursor-pointer items-start gap-2.5 rounded-md border p-3 text-left transition-colors ${
                  duplicateAction === 'update'
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:bg-muted/30'
                }`}
              >
                <input
                  type="radio"
                  name="duplicateAction"
                  value="update"
                  checked={duplicateAction === 'update'}
                  onChange={() => setDuplicateAction('update')}
                  className="mt-0.5"
                />
                <div className="text-xs">
                  <span className="font-medium text-foreground">Update Existing</span>
                  <p className="text-muted-foreground mt-0.5">Overwrite matching records with new data</p>
                </div>
              </label>
            </div>
          </div>

          <DialogFooter className="mt-6 flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={!file || isSubmitting}
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Uploading...
                </>
              ) : (
                'Upload & Import'
              )}
            </Button>
          </DialogFooter>
        </form>
      )}

      {/* STEP 2: Progress & Results View */}
      {importJob && (
        <div className="space-y-5">
          {/* Status Header Badge */}
          <div className="flex items-center justify-between border-b pb-3">
            <div>
              <p className="text-xs text-muted-foreground">Importing file</p>
              <p className="text-sm font-semibold">{importJob.file_name}</p>
            </div>
            <div>
              {currentStatus === 'completed' && <Badge variant="success">Completed</Badge>}
              {currentStatus === 'failed' && <Badge variant="destructive">Failed</Badge>}
              {(currentStatus === 'pending' || currentStatus === 'processing') && (
                <Badge variant="outline" className="flex items-center gap-1.5 animate-pulse">
                  <Loader2 className="h-3 w-3 animate-spin" />
                  Processing ({importJob.progress_percentage}%)
                </Badge>
              )}
            </div>
          </div>

          {/* Progress Bar */}
          <div className="space-y-1.5">
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>Progress</span>
              <span>
                {importJob.processed_rows} of {importJob.total_rows} rows processed
              </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div
                className={`h-full transition-all duration-300 ${
                  currentStatus === 'failed'
                    ? 'bg-destructive'
                    : currentStatus === 'completed'
                    ? 'bg-success'
                    : 'bg-primary'
                }`}
                style={{ width: `${Math.max(5, importJob.progress_percentage)}%` }}
              />
            </div>
          </div>

          {/* Summary KPIs */}
          <div className="grid grid-cols-2 gap-3 text-center">
            <div className="rounded-md border border-border bg-card p-3">
              <div className="flex items-center justify-center gap-1.5 text-success">
                <CheckCircle2 className="h-4 w-4" />
                <span className="text-xs font-medium">Successful</span>
              </div>
              <p className="text-xl font-bold mt-1 text-foreground">
                {importJob.successful_rows}
              </p>
            </div>
            <div className="rounded-md border border-border bg-card p-3">
              <div className="flex items-center justify-center gap-1.5 text-destructive">
                <XCircle className="h-4 w-4" />
                <span className="text-xs font-medium">Failed</span>
              </div>
              <p className="text-xl font-bold mt-1 text-foreground">
                {importJob.failed_rows}
              </p>
            </div>
          </div>

          {/* Error Table if Any */}
          {hasErrors && (
            <div className="space-y-2">
              <p className="text-xs font-semibold text-destructive flex items-center gap-1">
                <AlertTriangle className="h-3.5 w-3.5" />
                Row Errors ({importJob.errors?.length})
              </p>
              <div className="max-h-48 overflow-y-auto rounded-md border border-border bg-muted/20 text-xs">
                <table className="w-full border-collapse">
                  <thead className="bg-muted/60 sticky top-0">
                    <tr className="border-b">
                      <th className="py-1.5 px-3 text-left font-medium w-16">Row</th>
                      <th className="py-1.5 px-3 text-left font-medium">Reason</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {importJob.errors?.map((err, idx) => (
                      <tr key={idx} className="hover:bg-muted/40">
                        <td className="py-1.5 px-3 font-mono text-muted-foreground">{err.row}</td>
                        <td className="py-1.5 px-3 text-destructive">{err.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Dialog Action Buttons */}
          <DialogFooter className="mt-6 flex justify-between sm:justify-between items-center gap-2">
            {isFinished ? (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={resetState}
                  className="flex items-center gap-1.5"
                >
                  <RefreshCw className="h-3.5 w-3.5" />
                  Import Another File
                </Button>
                <Button
                  type="button"
                  variant="primary"
                  onClick={() => onOpenChange(false)}
                >
                  Done
                </Button>
              </>
            ) : (
              <div className="flex w-full justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => onOpenChange(false)}
                >
                  Close & Run in Background
                </Button>
              </div>
            )}
          </DialogFooter>
        </div>
      )}
    </Dialog>
  );
}
