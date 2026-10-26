import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Can } from '../Can';
import type { SharedProps } from '@/shared/types/auth';

const mockUsePage = vi.fn();

vi.mock('@inertiajs/react', () => ({
  usePage: () => mockUsePage(),
}));

describe('Can component', () => {
  it('renders children when permission is granted', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '1',
            roles: ['Staff'],
            permissions: ['users.manage'],
          },
        },
      } as unknown as SharedProps,
    });

    render(
      <Can permission="users.manage">
        <div>Protected Content</div>
      </Can>
    );

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('renders fallback when permission is denied', () => {
    mockUsePage.mockReturnValue({
      props: {
        auth: {
          user: {
            id: '2',
            roles: ['Staff'],
            permissions: ['sales.view'],
          },
        },
      } as unknown as SharedProps,
    });

    render(
      <Can permission="users.manage" fallback={<div>Access Denied</div>}>
        <div>Protected Content</div>
      </Can>
    );

    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
    expect(screen.getByText('Access Denied')).toBeInTheDocument();
  });
});
