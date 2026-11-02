import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { accountingApi } from '../api/accountingApi';
import { AlertCircle, Calendar } from 'lucide-react';

interface GeneratePeriodsModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

export function GeneratePeriodsModal({
  open,
  onOpenChange,
  onSuccess,
}: GeneratePeriodsModalProps) {
  const currentYear = new Date().getFullYear();
  const [fiscalYear, setFiscalYear] = useState<number>(currentYear);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await accountingApi.generateFiscalYear({
        fiscal_year: fiscalYear,
      });
      onSuccess();
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate fiscal year periods.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="sm:max-w-md">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Calendar className="h-5 w-5 text-primary" />
          Generate Fiscal Year Periods
        </DialogTitle>
        <DialogDescription>
          Automatically create 12 standard monthly fiscal periods for the chosen fiscal year.
        </DialogDescription>
      </DialogHeader>

      <form onSubmit={handleSubmit} className="space-y-4 py-2">
        {error && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <div className="space-y-2">
          <Label htmlFor="fiscalYear">Fiscal Year</Label>
          <Input
            id="fiscalYear"
            type="number"
            min={2000}
            max={2100}
            value={fiscalYear}
            onChange={(e) => setFiscalYear(parseInt(e.target.value, 10) || currentYear)}
            required
          />
          <p className="text-xs text-muted-foreground">
            Generates Period 1 (Jan) through Period 12 (Dec) in 'Open' status.
          </p>
        </div>

        <DialogFooter className="pt-4">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? 'Generating...' : 'Generate 12 Periods'}
          </Button>
        </DialogFooter>
      </form>
    </Dialog>
  );
}
