import React from 'react';

export interface AuthLayoutProps {
  children: React.ReactNode;
  title?: string;
  subtitle?: string;
}

export function AuthLayout({ children, title, subtitle }: AuthLayoutProps) {
  return (
    <div className="flex min-h-screen flex-col justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
      <div className="sm:mx-auto sm:w-full sm:max-w-md">
        {/* Brand header */}
        <div className="flex justify-center items-center gap-2 mb-4">
          <div className="flex h-10 w-10 items-center justify-center rounded bg-primary text-primary-foreground font-bold text-xl">
            N
          </div>
          <span className="text-2xl font-bold tracking-tight text-foreground">
            Nexus<span className="text-primary">ERP</span>
          </span>
        </div>

        {title && (
          <h2 className="text-center text-xl font-semibold tracking-tight text-foreground">
            {title}
          </h2>
        )}
        {subtitle && (
          <p className="mt-1 text-center text-sm text-muted-foreground">
            {subtitle}
          </p>
        )}
      </div>

      <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div className="rounded-lg border border-border bg-card p-6 shadow-sm sm:p-8">
          {children}
        </div>
      </div>
    </div>
  );
}
