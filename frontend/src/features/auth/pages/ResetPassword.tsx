import React, { useState } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertCircle, CheckCircle2, Lock, Mail } from 'lucide-react';

export interface ResetPasswordProps {
  token: string;
}

export default function ResetPassword({ token }: ResetPasswordProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Password rule criteria
  const isLengthValid = password.length >= 12;
  const hasLetter = /[a-zA-Z]/.test(password);
  const hasNumber = /[0-9]/.test(password);
  const passwordsMatch = password !== '' && password === passwordConfirmation;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    if (!isLengthValid || !hasLetter || !hasNumber) {
      setErrorMessage('Password must be at least 12 characters and contain letters and numbers.');
      return;
    }

    if (!passwordsMatch) {
      setErrorMessage('Passwords do not match.');
      return;
    }

    setLoading(true);

    try {
      const response = await fetch('/api/v1/auth/password/reset', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });

      const data = (await response.json()) as { success: boolean; message?: string };

      if (!response.ok || !data.success) {
        setErrorMessage(data.message || 'Password reset failed. The token may be invalid or expired.');
        setLoading(false);
        return;
      }

      setSuccess(true);
      setLoading(false);
    } catch {
      setErrorMessage('Network error. Please try again.');
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      title="Set new password"
      subtitle="Ensure your new password meets complexity rules"
    >
      {success ? (
        <div className="space-y-4">
          <Alert variant="success">
            <CheckCircle2 className="h-4 w-4" />
            <AlertDescription>
              Your password has been reset successfully. All active sessions have been invalidated. You may now sign in.
            </AlertDescription>
          </Alert>
          <Button
            type="button"
            className="w-full mt-4"
            onClick={() => (window.location.href = '/login')}
          >
            Proceed to sign in
          </Button>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          {errorMessage && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{errorMessage}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="email" required>
              Email address
            </Label>
            <div className="relative">
              <Mail className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="name@company.com"
                className="pl-9"
                required
                autoComplete="email"
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="password" required>
              New password
            </Label>
            <div className="relative">
              <Lock className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••••••"
                className="pl-9"
                required
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="password_confirmation" required>
              Confirm new password
            </Label>
            <div className="relative">
              <Lock className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                id="password_confirmation"
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                placeholder="••••••••••••"
                className="pl-9"
                required
              />
            </div>
          </div>

          {/* Checklist */}
          <div className="rounded-md border border-border bg-muted/40 p-3 text-xs space-y-1 text-muted-foreground">
            <p className="font-medium text-foreground mb-1">Password requirements:</p>
            <div className="flex items-center gap-1.5">
              <span className={isLengthValid ? 'text-emerald-600 font-semibold' : ''}>
                {isLengthValid ? '✓' : '•'} At least 12 characters
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <span className={hasLetter ? 'text-emerald-600 font-semibold' : ''}>
                {hasLetter ? '✓' : '•'} Contains letters
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <span className={hasNumber ? 'text-emerald-600 font-semibold' : ''}>
                {hasNumber ? '✓' : '•'} Contains numbers
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <span className={passwordsMatch ? 'text-emerald-600 font-semibold' : ''}>
                {passwordsMatch ? '✓' : '•'} Passwords match
              </span>
            </div>
          </div>

          <Button type="submit" className="w-full mt-2" disabled={loading}>
            {loading ? 'Resetting password...' : 'Reset password'}
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
