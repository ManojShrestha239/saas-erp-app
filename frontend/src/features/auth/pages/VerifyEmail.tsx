import { useState } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertCircle, CheckCircle2, Mail, LogOut } from 'lucide-react';

export default function VerifyEmail() {
  const [loading, setLoading] = useState(false);
  const [resent, setResent] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleResend = async () => {
    setErrorMessage(null);
    setLoading(true);

    try {
      const response = await fetch('/api/v1/auth/email/resend', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
      });

      const data = (await response.json()) as { success: boolean; message?: string };

      if (!response.ok || !data.success) {
        setErrorMessage(data.message || 'Unable to resend email. Please try again later.');
        setLoading(false);
        return;
      }

      setResent(true);
      setLoading(false);
    } catch {
      setErrorMessage('Network error. Please try again.');
      setLoading(false);
    }
  };

  const handleLogout = async () => {
    try {
      await fetch('/api/v1/auth/logout', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
      });
      window.location.href = '/login';
    } catch {
      window.location.href = '/login';
    }
  };

  return (
    <AuthLayout
      title="Verify your email address"
      subtitle="Complete email verification to access full features"
    >
      <div className="space-y-4">
        {resent && (
          <Alert variant="success">
            <CheckCircle2 className="h-4 w-4" />
            <AlertDescription>
              A new verification link has been sent to the email address you provided.
            </AlertDescription>
          </Alert>
        )}

        {errorMessage && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{errorMessage}</AlertDescription>
          </Alert>
        )}

        <div className="rounded-lg border border-border bg-muted/40 p-4 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary mb-3">
            <Mail className="h-6 w-6" />
          </div>
          <p className="text-sm text-muted-foreground leading-relaxed">
            Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we emailed to you?
          </p>
        </div>

        <Button
          type="button"
          onClick={handleResend}
          disabled={loading}
          className="w-full mt-2"
        >
          {loading ? 'Resending email...' : 'Resend Verification Email'}
        </Button>

        <div className="text-center pt-2">
          <button
            type="button"
            onClick={handleLogout}
            className="inline-flex items-center text-xs text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
          >
            <LogOut className="h-3.5 w-3.5 mr-1" />
            Sign out of this session
          </button>
        </div>
      </div>
    </AuthLayout>
  );
}
