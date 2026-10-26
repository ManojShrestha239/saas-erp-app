import React from 'react';
import { useCan, type UseCanOptions } from '../hooks/useCan';

export interface CanProps extends UseCanOptions {
  permission?: string | string[];
  role?: string | string[];
  fallback?: React.ReactNode;
  children: React.ReactNode;
}

export function Can({ permission, role, fallback = null, children, ...options }: CanProps) {
  const allowed = useCan(permission, role, options);

  if (!allowed) {
    return <>{fallback}</>;
  }

  return <>{children}</>;
}
