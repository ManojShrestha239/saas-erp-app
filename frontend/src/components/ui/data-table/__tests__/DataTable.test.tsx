import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { ColumnDef } from '@tanstack/react-table';
import { DataTable } from '../DataTable';
import { DataTableColumnHeader } from '../DataTableColumnHeader';
import { Button } from '@/components/ui/button';

interface TestProduct {
  id: string;
  sku: string;
  name: string;
  price_cents: number;
}

const mockData: TestProduct[] = [
  { id: '1', sku: 'SKU-001', name: 'Widget Alpha', price_cents: 1500 },
  { id: '2', sku: 'SKU-002', name: 'Widget Beta', price_cents: 2500 },
  { id: '3', sku: 'SKU-003', name: 'Widget Gamma', price_cents: 3500 },
];

const mockColumns: ColumnDef<TestProduct>[] = [
  {
    accessorKey: 'sku',
    header: ({ column }) => <DataTableColumnHeader column={column} title="SKU" />,
    cell: (info) => info.getValue(),
  },
  {
    accessorKey: 'name',
    header: ({ column }) => <DataTableColumnHeader column={column} title="Product Name" />,
    cell: (info) => info.getValue(),
  },
  {
    accessorKey: 'price_cents',
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Price" isNumeric />
    ),
    cell: (info) => `$${((info.getValue() as number) / 100).toFixed(2)}`,
    meta: {
      isNumeric: true,
    },
  },
];

describe('DataTable Component', () => {
  it('renders table headers and data rows correctly', () => {
    render(<DataTable columns={mockColumns} data={mockData} />);

    expect(screen.getByText('SKU')).toBeInTheDocument();
    expect(screen.getByText('Product Name')).toBeInTheDocument();
    expect(screen.getByText('Price')).toBeInTheDocument();

    expect(screen.getByText('SKU-001')).toBeInTheDocument();
    expect(screen.getByText('Widget Alpha')).toBeInTheDocument();
    expect(screen.getByText('$15.00')).toBeInTheDocument();
    expect(screen.getByText('SKU-002')).toBeInTheDocument();
  });

  it('renders skeleton rows when isLoading is true', () => {
    const { container } = render(
      <DataTable columns={mockColumns} data={[]} isLoading={true} skeletonRowCount={3} />
    );

    const pulseElements = container.querySelectorAll('.animate-pulse');
    expect(pulseElements.length).toBe(9); // 3 rows * 3 columns
    expect(screen.queryByText('SKU-001')).not.toBeInTheDocument();
  });

  it('renders designed empty state with action when data is empty and not loading', () => {
    const handleAdd = vi.fn();

    render(
      <DataTable
        columns={mockColumns}
        data={[]}
        emptyTitle="No products found"
        emptyDescription="Create your first product to get started."
        emptyAction={<Button onClick={handleAdd}>Add Product</Button>}
      />
    );

    expect(screen.getByText('No products found')).toBeInTheDocument();
    expect(screen.getByText('Create your first product to get started.')).toBeInTheDocument();

    const actionBtn = screen.getByRole('button', { name: /add product/i });
    expect(actionBtn).toBeInTheDocument();
    fireEvent.click(actionBtn);
    expect(handleAdd).toHaveBeenCalledTimes(1);
  });

  it('supports server-side pagination controls', () => {
    const handlePaginationChange = vi.fn();

    render(
      <DataTable
        columns={mockColumns}
        data={mockData}
        serverSide={{
          pagination: { pageIndex: 0, pageSize: 25 },
          onPaginationChange: handlePaginationChange,
          totalRows: 100,
          pageCount: 4,
        }}
      />
    );

    expect(screen.getByTestId('pagination-range')).toHaveTextContent('Showing 1 to 25 of 100 results');

    // Next page button
    const nextBtn = screen.getByRole('button', { name: /go to next page/i });
    expect(nextBtn).toBeEnabled();
    fireEvent.click(nextBtn);

    expect(handlePaginationChange).toHaveBeenCalled();
  });

  it('supports server-side sorting via column header menu', () => {
    const handleSortingChange = vi.fn();

    render(
      <DataTable
        columns={mockColumns}
        data={mockData}
        serverSide={{
          pagination: { pageIndex: 0, pageSize: 25 },
          onPaginationChange: vi.fn(),
          sorting: [],
          onSortingChange: handleSortingChange,
        }}
      />
    );

    // Open column header dropdown for SKU
    const skuHeaderBtn = screen.getByRole('button', { name: /sku/i });
    fireEvent.click(skuHeaderBtn);

    // Click "Asc" option
    const ascOption = screen.getByRole('menuitem', { name: /asc/i });
    fireEvent.click(ascOption);

    expect(handleSortingChange).toHaveBeenCalled();
  });

  it('toggles column visibility when selected from toolbar menu', () => {
    render(<DataTable columns={mockColumns} data={mockData} showColumnVisibility />);

    expect(screen.getByText('SKU-001')).toBeInTheDocument();

    // Open Columns menu
    const columnsBtn = screen.getByRole('button', { name: /toggle column visibility/i });
    fireEvent.click(columnsBtn);

    // Uncheck SKU
    const skuCheckbox = screen.getByRole('menuitemcheckbox', { name: /sku/i });
    fireEvent.click(skuCheckbox);

    // SKU-001 should now be hidden
    expect(screen.queryByText('SKU-001')).not.toBeInTheDocument();
    // But Product Name should still be visible
    expect(screen.getByText('Widget Alpha')).toBeInTheDocument();
  });

  it('supports density switching between compact and comfortable', () => {
    const handleDensityChange = vi.fn();

    render(
      <DataTable
        columns={mockColumns}
        data={mockData}
        density="compact"
        onDensityChange={handleDensityChange}
        showDensityToggle
      />
    );

    const densityBtn = screen.getByRole('button', { name: /toggle row density/i });
    fireEvent.click(densityBtn);

    const comfortableOption = screen.getByRole('menuitem', { name: /comfortable/i });
    fireEvent.click(comfortableOption);

    expect(handleDensityChange).toHaveBeenCalledWith('comfortable');
  });

  it('triggers search callback when input text changes in toolbar', () => {
    const handleSearchChange = vi.fn();

    render(
      <DataTable
        columns={mockColumns}
        data={mockData}
        searchValue=""
        onSearchChange={handleSearchChange}
        searchPlaceholder="Search products..."
      />
    );

    const searchInput = screen.getByPlaceholderText('Search products...');
    fireEvent.change(searchInput, { target: { value: 'Alpha' } });

    expect(handleSearchChange).toHaveBeenCalledWith('Alpha');
  });

  it('handles row selection when enabled', () => {
    const handleRowSelectionChange = vi.fn();

    render(
      <DataTable
        columns={mockColumns}
        data={mockData}
        enableRowSelection={true}
        rowSelection={{ '0': true }}
        onRowSelectionChange={handleRowSelectionChange}
      />
    );

    expect(screen.getByText(/1 of 3 row\(s\) selected/i)).toBeInTheDocument();
  });
});
