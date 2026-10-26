import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/shared/types/auth';

export interface UseCanOptions {
  requireAll?: boolean;
}

export function useCan(
  permission?: string | string[],
  role?: string | string[],
  options: UseCanOptions = {}
): boolean {
  const { props } = usePage<SharedProps>();
  const user = props?.auth?.user ?? null;

  if (!user) {
    return false;
  }

  const userRolesLower = user.roles.map((r) => r.toLowerCase());

  // Super Admin has unrestricted platform access
  if (
    user.permissions.includes('*') ||
    userRolesLower.includes('super admin') ||
    userRolesLower.includes('super_admin')
  ) {
    return true;
  }

  // Tenant Owner has all tenant permissions
  if (userRolesLower.includes('owner')) {
    return true;
  }

  // Check roles if specified
  if (role) {
    const requiredRoles = Array.isArray(role) ? role : [role];
    const hasRole = options.requireAll
      ? requiredRoles.every((r) => userRolesLower.includes(r.toLowerCase()))
      : requiredRoles.some((r) => userRolesLower.includes(r.toLowerCase()));

    if (!hasRole) {
      return false;
    }
  }

  // Check permissions if specified
  if (permission) {
    const requiredPermissions = Array.isArray(permission) ? permission : [permission];
    return options.requireAll
      ? requiredPermissions.every((p) => user.permissions.includes(p))
      : requiredPermissions.some((p) => user.permissions.includes(p));
  }

  return true;
}
