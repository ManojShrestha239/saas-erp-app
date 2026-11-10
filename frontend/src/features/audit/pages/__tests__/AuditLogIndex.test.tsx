import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import AuditLogIndex from '../AuditLogIndex';
import { auditApi } from '../../api/auditApi';
import type { AuditLog } from '../../types/audit.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';

vi.mock('../../api/auditApi', () => ({
  auditApi: {
    getAuditLogs: vi.fn(),
    getAuditLog: vi.fn(),
    getGlobalAuditLogs: vi.fn(),
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    props: {
      auth: {
        user: { id: '01TEST001', name: 'Admin User', email: 'admin@acme.test', role: 'owner', roles: ['owner'], permissions: ['audit.view'] },
      },
      tenant: { slug: 'test-tenant' },
    },
  }),
  Link: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

const mockAuditLogs: AuditLog[] = [
  {
    id: 1,
    log_name: 'default',
    description: 'created product',
    subject_type: 'Modules\\Inventory\\Models\\Product',
    subject_id: '01PROD00000000000000000001',
    event: 'created',
    causer_type: 'Modules\\Auth\\Models\\TenantUser',
    causer_id: '01USER00000000000000000001',
    causer: {
      id: '01USER00000000000000000001',
      name: 'John Doe',
      email: 'john@example.com',
    },
    properties: {
      attributes: {
        sku: 'PROD-001',
        name: 'Mechanical Keyboard',
        selling_price_cents: 12000,
      },
    },
    changes: {
      old: null,
      attributes: {
        sku: 'PROD-001',
        name: 'Mechanical Keyboard',
        selling_price_cents: 12000,
      },
    },
    ip_address: '192.168.1.100',
    user_agent: 'Mozilla/5.0 Chrome/120',
    created_at: '2026-11-10T08:00:00.000000Z',
  },
  {
    id: 2,
    log_name: 'default',
    description: 'updated warehouse',
    subject_type: 'Modules\\Inventory\\Models\\Warehouse',
    subject_id: '01WH0000000000000000000001',
    event: 'updated',
    causer_type: 'Modules\\Auth\\Models\\TenantUser',
    causer_id: '01USER00000000000000000001',
    causer: {
      id: '01USER00000000000000000001',
      name: 'John Doe',
      email: 'john@example.com',
    },
    properties: {
      old: { name: 'Old Warehouse Name' },
      attributes: { name: 'Central Distribution Hub' },
    },
    changes: {
      old: { name: 'Old Warehouse Name' },
      attributes: { name: 'Central Distribution Hub' },
    },
    ip_address: '192.168.1.100',
    user_agent: 'Mozilla/5.0 Chrome/120',
    created_at: '2026-11-10T08:15:00.000000Z',
  },
];

describe('AuditLogIndex', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(auditApi.getAuditLogs).mockResolvedValue({
      success: true,
      message: 'Audit logs retrieved successfully.',
      data: mockAuditLogs,
      meta: {
        page: 1,
        per_page: 25,
        total: 2,
      },
    } as ApiSuccessResponse<AuditLog[]>);
  });

  it('renders page title and audit logs table', async () => {
    render(<AuditLogIndex />);

    expect(screen.getByText('Immutable Audit Logs')).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('created product')).toBeInTheDocument();
      expect(screen.getByText('updated warehouse')).toBeInTheDocument();
      expect(screen.getByText('Product')).toBeInTheDocument();
      expect(screen.getAllByText('John Doe').length).toBeGreaterThan(0);
    });
  });

  it('opens diff modal when clicking Diff button', async () => {
    render(<AuditLogIndex />);

    await waitFor(() => {
      expect(screen.getByText('updated warehouse')).toBeInTheDocument();
    });

    const diffButtons = screen.getAllByRole('button', { name: /diff/i });
    expect(diffButtons.length).toBeGreaterThan(1);
    const targetButton = diffButtons[1];
    expect(targetButton).toBeDefined();
    if (targetButton) {
      fireEvent.click(targetButton);
    }

    await waitFor(() => {
      expect(screen.getByText('Audit Log Details')).toBeInTheDocument();
      expect(screen.getByText('Attribute Changes (Diff)')).toBeInTheDocument();
      expect(screen.getByText('Old Warehouse Name')).toBeInTheDocument();
      expect(screen.getByText('Central Distribution Hub')).toBeInTheDocument();
    });
  });

  it('filters by event when event filter tab is clicked', async () => {
    render(<AuditLogIndex />);

    await waitFor(() => {
      expect(auditApi.getAuditLogs).toHaveBeenCalled();
    });

    const createdTab = screen.getByRole('button', { name: 'Created' });
    fireEvent.click(createdTab);

    await waitFor(() => {
      expect(auditApi.getAuditLogs).toHaveBeenCalledWith(
        expect.objectContaining({
          event: 'created',
        })
      );
    });
  });

  it('searches audit logs when query is typed', async () => {
    render(<AuditLogIndex />);

    await waitFor(() => {
      expect(auditApi.getAuditLogs).toHaveBeenCalled();
    });

    const searchInput = screen.getByPlaceholderText('Search logs...');
    fireEvent.change(searchInput, { target: { value: 'Keyboard' } });

    await waitFor(() => {
      expect(auditApi.getAuditLogs).toHaveBeenCalledWith(
        expect.objectContaining({
          search: 'Keyboard',
        })
      );
    });
  });
});
