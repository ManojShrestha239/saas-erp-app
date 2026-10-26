import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({
      props: {
        auth: {
          user: {
            id: 'test-user-id',
            name: 'Test Owner',
            email: 'owner@example.com',
            status: 'active',
            email_verified: true,
            roles: ['Owner'],
            permissions: ['*'],
          },
        },
        tenant: {
          id: 'test-tenant-id',
          slug: 'acme',
        },
        flash: { success: null, error: null },
      },
    }),
  };
});
