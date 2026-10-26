import { describe, it, expect, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useCan } from '../useCan';
import type { SharedProps } from '@/shared/types/auth';

const mockUsePage = vi.fn();

vi.mock('@inertiajs/react', () => ({
  usePage: () => mockUsePage(),
}));

describe('useCan hook', function () {
  it('returns false when user is not authenticated', () => {
    mockUsePage.mockReturnValue({
      props: { auth: { user: null } } as unknown as SharedProps,
    });

    const { result } = renderHook(() => useCan('users.manage'));
    expect(result.current).toBe(false);
  });

  it('grants unrestricted access to Super Admin', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '1',
            name: 'Root',
            email: 'admin@nexuserp.test',
            status: 'active',
            email_verified: true,
            roles: ['Super Admin'],
            permissions: ['*'],
          },
        },
      } as unknown as SharedProps,
    });

    const { result } = renderHook(() => useCan('any.arbitrary.permission'));
    expect(result.current).toBe(true);
  });

  it('grants all tenant permissions to Tenant Owner', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '2',
            name: 'Tenant Owner',
            email: 'owner@company.com',
            status: 'active',
            email_verified: true,
            roles: ['Owner'],
            permissions: [],
          },
        },
      } as unknown as SharedProps,
    });

    const { result } = renderHook(() => useCan('users.manage'));
    expect(result.current).toBe(true);
  });

  it('checks individual permissions correctly for standard roles', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '3',
            name: 'Staff Member',
            email: 'staff@company.com',
            status: 'active',
            email_verified: true,
            roles: ['Accountant'],
            permissions: ['finance.view', 'reports.view'],
          },
        },
      } as unknown as SharedProps,
    });

    // Permitted
    const { result: canFinance } = renderHook(() => useCan('finance.view'));
    expect(canFinance.current).toBe(true);

    // Forbidden
    const { result: canUsers } = renderHook(() => useCan('users.manage'));
    expect(canUsers.current).toBe(false);
  });

  it('supports checking multiple permissions with requireAll', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '4',
            name: 'Accountant',
            email: 'acct@company.com',
            status: 'active',
            email_verified: true,
            roles: ['Accountant'],
            permissions: ['finance.view'],
          },
        },
      } as unknown as SharedProps,
    });

    // Has at least one (any) -> true
    const { result: hasAny } = renderHook(() =>
      useCan(['finance.view', 'finance.manage'])
    );
    expect(hasAny.current).toBe(true);

    // Requires all -> false (missing finance.manage)
    const { result: hasAll } = renderHook(() =>
      useCan(['finance.view', 'finance.manage'], undefined, { requireAll: true })
    );
    expect(hasAll.current).toBe(false);
  });

  it('checks role restrictions when specified', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '5',
            name: 'Sales Rep',
            email: 'sales@company.com',
            status: 'active',
            email_verified: true,
            roles: ['SalesRep'],
            permissions: ['sales.view'],
          },
        },
      } as unknown as SharedProps,
    });

    const { result: isSalesRep } = renderHook(() => useCan(undefined, 'SalesRep'));
    expect(isSalesRep.current).toBe(true);

    const { result: isAdmin } = renderHook(() => useCan(undefined, 'Admin'));
    expect(isAdmin.current).toBe(false);
  });
});
