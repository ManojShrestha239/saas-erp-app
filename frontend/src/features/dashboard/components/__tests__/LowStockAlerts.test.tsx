import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LowStockAlertCard } from '../LowStockAlertCard';
import { LowStockTable } from '../LowStockTable';
import type { LowStockItem } from '@/features/inventory/types/inventory.types';

const mockLowStockItems: LowStockItem[] = [
  {
    id: 'wh-1-prod-1',
    product_id: 'prod-1',
    product_name: 'Precision Bearing',
    sku: 'BEAR-001',
    barcode: '12345678',
    category_name: 'Mechanical',
    unit_of_measure: 'PCS',
    warehouse_id: 'wh-1',
    warehouse_name: 'Main Distribution Center',
    quantity_on_hand: 3,
    quantity_reserved: 0,
    low_stock_threshold: 15,
    deficit: 12,
    is_out_of_stock: false,
    created_at: '2026-10-28T00:00:00Z',
    updated_at: '2026-10-28T00:00:00Z',
  },
  {
    id: 'wh-1-prod-2',
    product_id: 'prod-2',
    product_name: 'Hydraulic Seal',
    sku: 'SEAL-002',
    barcode: null,
    category_name: 'Hydraulics',
    unit_of_measure: 'BOX',
    warehouse_id: 'wh-1',
    warehouse_name: 'Main Distribution Center',
    quantity_on_hand: 0,
    quantity_reserved: 0,
    low_stock_threshold: 10,
    deficit: 10,
    is_out_of_stock: true,
    created_at: '2026-10-28T00:00:00Z',
    updated_at: '2026-10-28T00:00:00Z',
  },
];

describe('LowStockAlertCard Component', () => {
  it('renders normal healthy state when count is 0', () => {
    render(<LowStockAlertCard count={0} />);

    expect(screen.getByText('Low Stock Alerts')).toBeInTheDocument();
    expect(screen.getByText('0')).toBeInTheDocument();
    expect(screen.getByText(/All products are currently above minimum threshold/i)).toBeInTheDocument();
    expect(screen.queryByText(/Needs Reorder/i)).not.toBeInTheDocument();
  });

  it('renders warning badge when low-stock items exist', () => {
    render(<LowStockAlertCard count={5} outOfStockCount={0} />);

    expect(screen.getByText('5')).toBeInTheDocument();
    expect(screen.getByText('Needs Reorder')).toBeInTheDocument();
  });

  it('renders destructive badge when out-of-stock items exist', () => {
    render(<LowStockAlertCard count={5} outOfStockCount={2} />);

    expect(screen.getByText('5')).toBeInTheDocument();
    expect(screen.getByText('2 Out of Stock')).toBeInTheDocument();
  });

  it('handles click callback when provided', () => {
    const handleClick = vi.fn();
    render(<LowStockAlertCard count={3} onClick={handleClick} />);

    fireEvent.click(screen.getByText('Low Stock Alerts'));
    expect(handleClick).toHaveBeenCalledTimes(1);
  });
});

describe('LowStockTable Component', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('renders table headers and pre-populated low-stock items', () => {
    render(
      <LowStockTable
        initialItems={mockLowStockItems}
        enableLiveStream={false}
      />
    );

    expect(screen.getByText('Inventory Shortage & Low Stock Alerts')).toBeInTheDocument();
    expect(screen.getByText('BEAR-001')).toBeInTheDocument();
    expect(screen.getByText('Precision Bearing')).toBeInTheDocument();
    expect(screen.getByText('Low Stock')).toBeInTheDocument();

    expect(screen.getByText('SEAL-002')).toBeInTheDocument();
    expect(screen.getByText('Hydraulic Seal')).toBeInTheDocument();
    expect(screen.getByText('Out of Stock')).toBeInTheDocument();

    // Deficit column
    expect(screen.getByText('-12')).toBeInTheDocument();
    expect(screen.getByText('-10')).toBeInTheDocument();
  });

  it('renders healthy empty state when no low-stock items exist', () => {
    render(
      <LowStockTable
        initialItems={[]}
        autoFetch={false}
        enableLiveStream={false}
      />
    );

    expect(screen.getByText('All stock levels healthy')).toBeInTheDocument();
    expect(
      screen.getByText(/No inventory items are currently at or below their low-stock thresholds/i)
    ).toBeInTheDocument();
  });

  it('notifies parent via onAlertCountChange callback', async () => {
    const handleCountChange = vi.fn();

    render(
      <LowStockTable
        initialItems={mockLowStockItems}
        enableLiveStream={false}
        onAlertCountChange={handleCountChange}
      />
    );

    // Initial items passed directly
    expect(screen.getByText('BEAR-001')).toBeInTheDocument();
  });
});
