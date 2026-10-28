import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PlaceholderDashboard } from '../PlaceholderDashboard';

describe('PlaceholderDashboard', () => {
  it('renders the application shell and dashboard placeholder content', () => {
    render(<PlaceholderDashboard tenantName="Acme Corp" lowStockItems={[]} />);

    // Shell header & title
    expect(screen.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeInTheDocument();

    // Navigation links in sidebar
    expect(screen.getByRole('link', { name: /Dashboard/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Inventory/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Sales/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Purchasing/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Contacts/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Accounting/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Reports/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Settings/i })).toBeInTheDocument();

    // Welcome text with custom tenant name
    expect(screen.getByText(/Welcome back to/i)).toBeInTheDocument();
    expect(screen.getAllByText('Acme Corp').length).toBeGreaterThanOrEqual(1);

    // Status chip / badge
    expect(screen.getByText('Workspace Active')).toBeInTheDocument();

    // Cards and buttons
    expect(screen.getByText('System Overview')).toBeInTheDocument();
    expect(screen.getByText('Tenant Database')).toBeInTheDocument();
    expect(screen.getByText('Next Steps')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Explore Modules/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /View Configuration/i })).toBeInTheDocument();
  });
});
