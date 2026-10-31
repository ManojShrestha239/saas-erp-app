import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import InventoryIndex from '../InventoryIndex';
import { inventoryApi } from '../../api/inventoryApi';
import type {
  CategoryItem,
  ProductItem,
  StockAdjustmentItem,
  StockLevelItem,
  UnitOfMeasureItem,
  WarehouseItem,
} from '../../types/inventory.types';
import type { ApiSuccessResponse } from '@/shared/types/apiResponse';

vi.mock('../../api/inventoryApi', () => ({
  inventoryApi: {
    getProducts: vi.fn(),
    createProduct: vi.fn(),
    updateProduct: vi.fn(),
    deleteProduct: vi.fn(),
    getCategories: vi.fn(),
    getUnitsOfMeasure: vi.fn(),
    getWarehouses: vi.fn(),
    createWarehouse: vi.fn(),
    updateWarehouse: vi.fn(),
    deleteWarehouse: vi.fn(),
    getStockLevels: vi.fn(),
    getLowStock: vi.fn(),
    getStockAdjustments: vi.fn(),
    createStockAdjustment: vi.fn(),
    uploadProductCsv: vi.fn(),
    getProductImportStatus: vi.fn(),
    getProductTemplateUrl: vi.fn().mockReturnValue('/api/v1/inventory/products/import/template'),
  },
}));

const mockProducts: ProductItem[] = [
  {
    id: '01HPROD0000000000000001',
    sku: 'STL-ROD-12',
    name: 'Standard Steel Rod 12mm',
    barcode: '8901234567890',
    category_id: '01HCAT0000000000000001',
    unit_of_measure_id: '01HUOM0000000000000001',
    cost_price_cents: 120000,
    selling_price_cents: 150000,
    tax_category: 'standard',
    tax_category_label: 'Standard Rate',
    low_stock_threshold: 15,
    is_active: true,
    category: {
      id: '01HCAT0000000000000001',
      name: 'Construction Materials',
      slug: 'construction-materials',
      description: null,
      is_active: true,
    },
    unit_of_measure: {
      id: '01HUOM0000000000000001',
      name: 'Pieces',
      symbol: 'pcs',
      is_active: true,
    },
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
  },
  {
    id: '01HPROD0000000000000002',
    sku: 'CMT-OPC-50',
    name: 'OPC Cement 50kg Bag',
    barcode: '8901234567891',
    category_id: '01HCAT0000000000000001',
    unit_of_measure_id: '01HUOM0000000000000002',
    cost_price_cents: 80000,
    selling_price_cents: 95000,
    tax_category: 'standard',
    tax_category_label: 'Standard Rate',
    low_stock_threshold: 20,
    is_active: true,
    category: {
      id: '01HCAT0000000000000001',
      name: 'Construction Materials',
      slug: 'construction-materials',
      description: null,
      is_active: true,
    },
    unit_of_measure: {
      id: '01HUOM0000000000000002',
      name: 'Bags',
      symbol: 'bags',
      is_active: true,
    },
    created_at: '2026-10-28T10:00:00Z',
    updated_at: '2026-10-28T10:00:00Z',
  },
];

const mockWarehouses: WarehouseItem[] = [
  {
    id: '01HWH00000000000000001',
    name: 'Kathmandu Central Depot',
    code: 'WH-KTM-01',
    address: 'Teku, Kathmandu',
    is_default: true,
    is_active: true,
    created_at: '2026-10-28T10:00:00Z',
  },
  {
    id: '01HWH00000000000000002',
    name: 'Birgunj Logistics Hub',
    code: 'WH-BRG-01',
    address: 'Customs Road, Birgunj',
    is_default: false,
    is_active: true,
    created_at: '2026-10-28T10:00:00Z',
  },
];

const mockCategories: CategoryItem[] = [
  {
    id: '01HCAT0000000000000001',
    name: 'Construction Materials',
    slug: 'construction-materials',
    description: null,
    is_active: true,
  },
];

const mockUoms: UnitOfMeasureItem[] = [
  {
    id: '01HUOM0000000000000001',
    name: 'Pieces',
    symbol: 'pcs',
    is_active: true,
  },
];

const mockStockLevels: StockLevelItem[] = [
  {
    id: '01HSTK0000000000000001',
    warehouse_id: '01HWH00000000000000001',
    product_id: '01HPROD0000000000000001',
    quantity_on_hand: 50,
    quantity_reserved: 5,
    quantity_available: 45,
    warehouse: mockWarehouses[0],
    product: mockProducts[0],
    created_at: '2026-10-28T10:00:00Z',
  },
];

const mockAdjustments: StockAdjustmentItem[] = [
  {
    id: '01HADJ0000000000000001',
    adjustment_number: 'ADJ-2026-0001',
    warehouse_id: '01HWH00000000000000001',
    reason: 'Cycle count discrepancy',
    status: 'completed',
    total_cost_impact_cents: -240000,
    warehouse: mockWarehouses[0],
    items: [
      {
        id: '01HADJITM0000000000001',
        stock_adjustment_id: '01HADJ00000000000001',
        product_id: '01HPROD0000000000000001',
        quantity_before: 52,
        quantity_delta: -2,
        quantity_after: 50,
        unit_cost_cents: 120000,
        line_cost_impact_cents: -240000,
        product: mockProducts[0],
        created_at: '2026-10-28T10:00:00Z',
      },
    ],
    created_at: '2026-10-28T11:00:00Z',
  },
];

describe('InventoryIndex Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(inventoryApi.getCategories).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockCategories,
    } as ApiSuccessResponse<CategoryItem[]>);

    vi.mocked(inventoryApi.getUnitsOfMeasure).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockUoms,
    } as ApiSuccessResponse<UnitOfMeasureItem[]>);

    vi.mocked(inventoryApi.getWarehouses).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockWarehouses,
    } as ApiSuccessResponse<WarehouseItem[]>);

    vi.mocked(inventoryApi.getProducts).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockProducts,
      meta: { page: 1, per_page: 25, total: 2 },
    } as ApiSuccessResponse<ProductItem[]>);

    vi.mocked(inventoryApi.getStockLevels).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockStockLevels,
      meta: { page: 1, per_page: 25, total: 1 },
    } as ApiSuccessResponse<StockLevelItem[]>);

    vi.mocked(inventoryApi.getStockAdjustments).mockResolvedValue({
      success: true,
      message: 'OK',
      data: mockAdjustments,
      meta: { page: 1, per_page: 25, total: 1 },
    } as ApiSuccessResponse<StockAdjustmentItem[]>);
  });

  it('renders inventory header, tabs, and action buttons', async () => {
    render(<InventoryIndex />);

    expect(screen.getByText('Inventory & Products')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Add Product/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Import CSV/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Stock Adjustment' })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText('Standard Steel Rod 12mm')).toBeInTheDocument();
      expect(screen.getByText('OPC Cement 50kg Bag')).toBeInTheDocument();
    });

    expect(screen.getByText('STL-ROD-12')).toBeInTheDocument();
    expect(screen.getByText('CMT-OPC-50')).toBeInTheDocument();
  });

  it('switches between tabs: Stock Balances, Adjustments, and Warehouses', async () => {
    render(<InventoryIndex />);

    await waitFor(() => {
      expect(screen.getByText('Standard Steel Rod 12mm')).toBeInTheDocument();
    });

    // 1. Switch to Stock Balances
    const balancesTab = screen.getByRole('button', { name: /Stock Balances/i });
    fireEvent.click(balancesTab);

    await waitFor(() => {
      expect(inventoryApi.getStockLevels).toHaveBeenCalled();
      expect(screen.getByText('Available')).toBeInTheDocument();
      expect(screen.getByText('In Stock')).toBeInTheDocument();
    });

    // 2. Switch to Stock Adjustments
    const adjustmentsTab = screen.getByRole('button', { name: /Stock Adjustments/i });
    fireEvent.click(adjustmentsTab);

    await waitFor(() => {
      expect(inventoryApi.getStockAdjustments).toHaveBeenCalled();
      expect(screen.getByText('ADJ-2026-0001')).toBeInTheDocument();
      expect(screen.getByText('Cycle count discrepancy')).toBeInTheDocument();
    });

    // 3. Switch to Warehouses
    const warehousesTab = screen.getByRole('button', { name: /Warehouses/i });
    fireEvent.click(warehousesTab);

    await waitFor(() => {
      expect(screen.getByText('Kathmandu Central Depot')).toBeInTheDocument();
      expect(screen.getByText('Birgunj Logistics Hub')).toBeInTheDocument();
      expect(screen.getByText('WH-KTM-01')).toBeInTheDocument();
    });
  });

  it('opens Add Product modal and submits successfully', async () => {
    vi.mocked(inventoryApi.createProduct).mockResolvedValue({
      success: true,
      message: 'Product created successfully.',
      data: {
        ...mockProducts[0]!,
        id: '01HPROD0000000000000003',
        name: 'Brick Red Solid',
        sku: 'BRK-RED-01',
      },
    } as ApiSuccessResponse<ProductItem>);

    render(<InventoryIndex />);

    await waitFor(() => {
      expect(screen.getByText('Standard Steel Rod 12mm')).toBeInTheDocument();
    });

    const addProductBtn = screen.getByRole('button', { name: /Add Product/i });
    fireEvent.click(addProductBtn);

    expect(screen.getByRole('heading', { name: 'Add New Product' })).toBeInTheDocument();

    const nameInput = screen.getByLabelText(/Product Name/i);
    const skuInput = screen.getByLabelText(/SKU Code/i);

    fireEvent.change(nameInput, { target: { value: 'Brick Red Solid' } });
    fireEvent.change(skuInput, { target: { value: 'BRK-RED-01' } });

    const submitBtn = screen.getByRole('button', { name: 'Create Product' });
    fireEvent.click(submitBtn);

    await waitFor(() => {
      expect(inventoryApi.createProduct).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Brick Red Solid',
          sku: 'BRK-RED-01',
        })
      );
    });
  });

  it('opens Stock Adjustment modal and posts adjustment', async () => {
    vi.mocked(inventoryApi.createStockAdjustment).mockResolvedValue({
      success: true,
      message: 'Stock adjustment created successfully.',
      data: mockAdjustments[0]!,
    } as ApiSuccessResponse<StockAdjustmentItem>);

    render(<InventoryIndex />);

    await waitFor(() => {
      expect(screen.getByText('Standard Steel Rod 12mm')).toBeInTheDocument();
    });

    const adjustBtn = screen.getByRole('button', { name: 'Stock Adjustment' });
    fireEvent.click(adjustBtn);

    expect(screen.getByRole('heading', { name: 'Record Stock Adjustment' })).toBeInTheDocument();

    const postBtn = screen.getByRole('button', { name: 'Post Stock Adjustment' });
    fireEvent.click(postBtn);

    await waitFor(() => {
      expect(inventoryApi.createStockAdjustment).toHaveBeenCalledWith(
        expect.objectContaining({
          warehouse_id: mockWarehouses[0]!.id,
          reason: 'Cycle count discrepancy',
        })
      );
    });
  });
});
