import { useState, useEffect, useCallback, useMemo } from 'react';
import type { ColumnDef, PaginationState, SortingState } from '@tanstack/react-table';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import { CsvImportDialog } from '@/shared/components/CsvImportDialog';
import { ProductFormModal } from '../components/ProductFormModal';
import { StockAdjustmentModal } from '../components/StockAdjustmentModal';
import { WarehouseFormModal } from '../components/WarehouseFormModal';
import { CostHistoryModal } from '../components/CostHistoryModal';
import { useCan } from '@/features/auth/hooks/useCan';
import { inventoryApi } from '../api/inventoryApi';
import type {
  CategoryItem,
  ProductItem,
  ProductValuationItem,
  StockAdjustmentItem,
  StockLevelItem,
  UnitOfMeasureItem,
  ValuationSummary,
  WarehouseItem,
} from '../types/inventory.types';
import {
  Package,
  Boxes,
  ClipboardList,
  Warehouse,
  TrendingUp,
  History,
  Plus,
  RefreshCw,
  Search,
  MoreHorizontal,
  Edit2,
  Trash2,
  FileSpreadsheet,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  Eye,
  SlidersHorizontal,
} from 'lucide-react';

type TabKey = 'products' | 'balances' | 'valuation' | 'adjustments' | 'warehouses';

function formatMoneyCents(cents: number): string {
  const amount = cents / 100;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'NPR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
    .format(amount)
    .replace('NPR', 'NPR ');
}

function formatDate(dateStr?: string): string {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return dateStr;
  }
}

export default function InventoryIndex() {
  const canManageProducts = useCan('products.manage');
  const canAdjustStock = useCan('stock.adjust') || canManageProducts;

  // Active Tab
  const [activeTab, setActiveTab] = useState<TabKey>('products');

  // Shared Reference Data
  const [categories, setCategories] = useState<CategoryItem[]>([]);
  const [unitsOfMeasure, setUnitsOfMeasure] = useState<UnitOfMeasureItem[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseItem[]>([]);

  // Feedback notifications
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // ----------------------------------------------------
  // Tab 1: Products State
  // ----------------------------------------------------
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [productsLoading, setProductsLoading] = useState(true);
  const [productsTotal, setProductsTotal] = useState(0);
  const [productSearch, setProductSearch] = useState('');
  const [productCategoryFilter, setProductCategoryFilter] = useState('');
  const [productStatusFilter, setProductStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [productPagination, setProductPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [productSorting, setProductSorting] = useState<SortingState>([
    { id: 'name', desc: false },
  ]);

  // Product Modals
  const [isProductModalOpen, setIsProductModalOpen] = useState(false);
  const [editingProduct, setEditingProduct] = useState<ProductItem | null>(null);
  const [isCsvImportOpen, setIsCsvImportOpen] = useState(false);
  const [deletingProduct, setDeletingProduct] = useState<ProductItem | null>(null);

  // ----------------------------------------------------
  // Tab 2: Stock Balances State
  // ----------------------------------------------------
  const [stockLevels, setStockLevels] = useState<StockLevelItem[]>([]);
  const [stockLoading, setStockLoading] = useState(false);
  const [stockTotal, setStockTotal] = useState(0);
  const [stockSearch, setStockSearch] = useState('');
  const [stockWarehouseFilter, setStockWarehouseFilter] = useState('');
  const [stockPagination, setStockPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [stockSorting, setStockSorting] = useState<SortingState>([
    { id: 'quantity_on_hand', desc: true },
  ]);

  // ----------------------------------------------------
  // Tab 3: Stock Adjustments State
  // ----------------------------------------------------
  const [adjustments, setAdjustments] = useState<StockAdjustmentItem[]>([]);
  const [adjustmentsLoading, setAdjustmentsLoading] = useState(false);
  const [adjustmentsTotal, setAdjustmentsTotal] = useState(0);
  const [adjustmentSearch, setAdjustmentSearch] = useState('');
  const [adjustmentWarehouseFilter, setAdjustmentWarehouseFilter] = useState('');
  const [adjustmentPagination, setAdjustmentPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [adjustmentSorting, setAdjustmentSorting] = useState<SortingState>([
    { id: 'created_at', desc: true },
  ]);

  // Adjustment Modals
  const [isAdjustmentModalOpen, setIsAdjustmentModalOpen] = useState(false);
  const [adjustmentPrefill, setAdjustmentPrefill] = useState<{ warehouseId?: string; productId?: string }>({});
  const [viewingAdjustment, setViewingAdjustment] = useState<StockAdjustmentItem | null>(null);

  // ----------------------------------------------------
  // Tab 4: Warehouses State
  // ----------------------------------------------------
  const [warehousesLoading, setWarehousesLoading] = useState(false);
  const [isWarehouseModalOpen, setIsWarehouseModalOpen] = useState(false);
  const [editingWarehouse, setEditingWarehouse] = useState<WarehouseItem | null>(null);
  const [deletingWarehouse, setDeletingWarehouse] = useState<WarehouseItem | null>(null);

  // ----------------------------------------------------
  // Tab 5: Stock Valuation & AVCO Costing State
  // ----------------------------------------------------
  const [valuationItems, setValuationItems] = useState<ProductValuationItem[]>([]);
  const [valuationLoading, setValuationLoading] = useState(false);
  const [valuationTotal, setValuationTotal] = useState(0);
  const [valuationSummary, setValuationSummary] = useState<ValuationSummary | null>(null);
  const [valuationSearch, setValuationSearch] = useState('');
  const [valuationCategoryFilter, setValuationCategoryFilter] = useState('');
  const [valuationWarehouseFilter, setValuationWarehouseFilter] = useState('');
  const [valuationInStockOnly, setValuationInStockOnly] = useState(false);
  const [valuationPagination, setValuationPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [valuationSorting, setValuationSorting] = useState<SortingState>([
    { id: 'name', desc: false },
  ]);
  const [selectedCostHistoryProductId, setSelectedCostHistoryProductId] = useState<string | null>(null);
  const [isCostHistoryOpen, setIsCostHistoryOpen] = useState(false);

  const [actionSubmitting, setActionSubmitting] = useState(false);

  // ----------------------------------------------------
  // Data Fetching: Reference data
  // ----------------------------------------------------
  const fetchReferenceData = useCallback(async () => {
    try {
      const [catsRes, uomsRes, whsRes] = await Promise.all([
        inventoryApi.getCategories({ per_page: 100 }),
        inventoryApi.getUnitsOfMeasure({ per_page: 100 }),
        inventoryApi.getWarehouses({ per_page: 100 }),
      ]);
      setCategories(catsRes.data);
      setUnitsOfMeasure(uomsRes.data);
      setWarehouses(whsRes.data);
    } catch {
      // Ignore reference fetch errors
    }
  }, []);

  useEffect(() => {
    fetchReferenceData();
  }, [fetchReferenceData]);

  // ----------------------------------------------------
  // Fetch Products
  // ----------------------------------------------------
  const fetchProducts = useCallback(async () => {
    setProductsLoading(true);
    try {
      const sortField = productSorting[0]?.id || 'name';
      const sortDir = productSorting[0]?.desc ? 'desc' : 'asc';
      const activeFilter =
        productStatusFilter === 'active' ? true : productStatusFilter === 'inactive' ? false : undefined;

      const res = await inventoryApi.getProducts({
        search: productSearch || undefined,
        category_id: productCategoryFilter || undefined,
        is_active: activeFilter,
        page: productPagination.pageIndex + 1,
        per_page: productPagination.pageSize,
        sort_by: sortField,
        sort_direction: sortDir,
      });

      setProducts(res.data);
      if (res.meta && typeof res.meta.total === 'number') {
        setProductsTotal(res.meta.total);
      } else {
        setProductsTotal(res.data.length);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch products';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setProductsLoading(false);
    }
  }, [productSearch, productCategoryFilter, productStatusFilter, productPagination, productSorting]);

  // ----------------------------------------------------
  // Fetch Stock Balances
  // ----------------------------------------------------
  const fetchStockLevels = useCallback(async () => {
    setStockLoading(true);
    try {
      const sortField = stockSorting[0]?.id || 'quantity_on_hand';
      const sortDir = stockSorting[0]?.desc ? 'desc' : 'asc';

      const res = await inventoryApi.getStockLevels({
        search: stockSearch || undefined,
        warehouse_id: stockWarehouseFilter || undefined,
        page: stockPagination.pageIndex + 1,
        per_page: stockPagination.pageSize,
        sort_by: sortField,
        sort_direction: sortDir,
      });

      setStockLevels(res.data);
      if (res.meta && typeof res.meta.total === 'number') {
        setStockTotal(res.meta.total);
      } else {
        setStockTotal(res.data.length);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch stock levels';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setStockLoading(false);
    }
  }, [stockSearch, stockWarehouseFilter, stockPagination, stockSorting]);

  // ----------------------------------------------------
  // Fetch Stock Adjustments
  // ----------------------------------------------------
  const fetchAdjustments = useCallback(async () => {
    setAdjustmentsLoading(true);
    try {
      const sortField = adjustmentSorting[0]?.id || 'created_at';
      const sortDir = adjustmentSorting[0]?.desc ? 'desc' : 'asc';

      const res = await inventoryApi.getStockAdjustments({
        search: adjustmentSearch || undefined,
        warehouse_id: adjustmentWarehouseFilter || undefined,
        page: adjustmentPagination.pageIndex + 1,
        per_page: adjustmentPagination.pageSize,
        sort_by: sortField,
        sort_direction: sortDir,
      });

      setAdjustments(res.data);
      if (res.meta && typeof res.meta.total === 'number') {
        setAdjustmentsTotal(res.meta.total);
      } else {
        setAdjustmentsTotal(res.data.length);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch adjustments';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setAdjustmentsLoading(false);
    }
  }, [adjustmentSearch, adjustmentWarehouseFilter, adjustmentPagination, adjustmentSorting]);

  // ----------------------------------------------------
  // Fetch Warehouses Tab
  // ----------------------------------------------------
  const fetchWarehousesList = useCallback(async () => {
    setWarehousesLoading(true);
    try {
      const res = await inventoryApi.getWarehouses({ per_page: 100 });
      setWarehouses(res.data);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch warehouses';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setWarehousesLoading(false);
    }
  }, []);

  // ----------------------------------------------------
  // Fetch Valuation Tab
  // ----------------------------------------------------
  const fetchValuation = useCallback(async () => {
    setValuationLoading(true);
    try {
      const sortField = valuationSorting[0]?.id || 'name';
      const sortDir = valuationSorting[0]?.desc ? 'desc' : 'asc';

      const res = await inventoryApi.getValuation({
        search: valuationSearch.trim() || undefined,
        category_id: valuationCategoryFilter || undefined,
        warehouse_id: valuationWarehouseFilter || undefined,
        in_stock_only: valuationInStockOnly || undefined,
        page: valuationPagination.pageIndex + 1,
        per_page: valuationPagination.pageSize,
        sort_field: sortField,
        sort_direction: sortDir,
      });

      setValuationItems(res.data.items);
      setValuationSummary(res.data.summary);
      if (res.meta && typeof res.meta.total === 'number') {
        setValuationTotal(res.meta.total);
      } else {
        setValuationTotal(res.data.items.length);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch inventory valuation';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setValuationLoading(false);
    }
  }, [
    valuationSearch,
    valuationCategoryFilter,
    valuationWarehouseFilter,
    valuationInStockOnly,
    valuationPagination,
    valuationSorting,
  ]);

  // Trigger fetches on tab change or filter change
  useEffect(() => {
    if (activeTab === 'products') {
      fetchProducts();
    } else if (activeTab === 'balances') {
      fetchStockLevels();
    } else if (activeTab === 'adjustments') {
      fetchAdjustments();
    } else if (activeTab === 'warehouses') {
      fetchWarehousesList();
    } else if (activeTab === 'valuation') {
      fetchValuation();
    }
  }, [activeTab, fetchProducts, fetchStockLevels, fetchAdjustments, fetchWarehousesList, fetchValuation]);

  // Delete Product Handler
  const handleDeleteProduct = async () => {
    if (!deletingProduct) return;
    setActionSubmitting(true);
    try {
      await inventoryApi.deleteProduct(deletingProduct.id);
      setFeedback({ type: 'success', message: `Product "${deletingProduct.name}" deleted successfully.` });
      setDeletingProduct(null);
      fetchProducts();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to delete product.';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setActionSubmitting(false);
    }
  };

  // Delete Warehouse Handler
  const handleDeleteWarehouse = async () => {
    if (!deletingWarehouse) return;
    setActionSubmitting(true);
    try {
      await inventoryApi.deleteWarehouse(deletingWarehouse.id);
      setFeedback({ type: 'success', message: `Warehouse "${deletingWarehouse.name}" deleted successfully.` });
      setDeletingWarehouse(null);
      fetchWarehousesList();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to delete warehouse.';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setActionSubmitting(false);
    }
  };

  // ----------------------------------------------------
  // Table Columns: Products
  // ----------------------------------------------------
  const productColumns = useMemo<ColumnDef<ProductItem>[]>(() => [
    {
      accessorKey: 'sku',
      header: ({ column }) => <DataTableColumnHeader column={column} title="SKU" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('sku')}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'name',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
      cell: ({ row }) => {
        const prod = row.original;
        return (
          <div className="flex flex-col">
            <span className="font-semibold text-foreground text-sm">{prod.name}</span>
            <div className="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
              {prod.category?.name && <span>{prod.category.name}</span>}
              {prod.barcode && <span>• EAN: {prod.barcode}</span>}
            </div>
          </div>
        );
      },
      enableSorting: true,
    },
    {
      accessorKey: 'unit_of_measure',
      header: 'UoM',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {row.original.unit_of_measure?.symbol || '—'}
        </span>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'cost_price_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Cost Price" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs text-muted-foreground">
          {formatMoneyCents(row.original.cost_price_cents)}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'selling_price_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Selling Price" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-foreground">
          {formatMoneyCents(row.original.selling_price_cents)}
        </span>
      ),
      enableSorting: true,
    },
    {
      id: 'margin',
      header: 'Margin',
      cell: ({ row }) => {
        const cost = row.original.cost_price_cents;
        const sell = row.original.selling_price_cents;
        if (cost <= 0) return <span className="text-xs text-muted-foreground">—</span>;
        const margin = Math.round(((sell - cost) / cost) * 100);
        return (
          <span
            className={`text-xs font-medium ${
              margin >= 20 ? 'text-emerald-600' : margin > 0 ? 'text-amber-600' : 'text-destructive'
            }`}
          >
            {margin}%
          </span>
        );
      },
    },
    {
      accessorKey: 'tax_category',
      header: 'Tax',
      cell: ({ row }) => {
        const cat = row.original.tax_category;
        const label = cat === 'standard' ? 'Standard (13%)' : cat === 'zero_rated' ? 'Zero (0%)' : 'Exempt';
        return (
          <Badge variant="outline" className="text-[11px] font-normal">
            {label}
          </Badge>
        );
      },
      enableSorting: false,
    },
    {
      accessorKey: 'low_stock_threshold',
      header: 'Min Threshold',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {row.original.low_stock_threshold}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'is_active',
      header: 'Status',
      cell: ({ row }) => {
        const active = row.getValue('is_active');
        return (
          <Badge
            variant={active ? 'secondary' : 'destructive'}
            className={
              active
                ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 text-xs'
                : 'text-xs'
            }
          >
            {active ? 'Active' : 'Inactive'}
          </Badge>
        );
      },
      enableSorting: false,
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const prod = row.original;
        if (!canManageProducts) return null;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="h-8 w-8 p-0" title="Product actions">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-40">
              <DropdownMenuItem
                onClick={() => {
                  setEditingProduct(prod);
                  setIsProductModalOpen(true);
                }}
                className="gap-2 text-xs"
              >
                <Edit2 className="h-3.5 w-3.5" /> Edit Product
              </DropdownMenuItem>
              {canAdjustStock && (
                <DropdownMenuItem
                  onClick={() => {
                    setAdjustmentPrefill({ productId: prod.id });
                    setIsAdjustmentModalOpen(true);
                  }}
                  className="gap-2 text-xs"
                >
                  <SlidersHorizontal className="h-3.5 w-3.5" /> Adjust Stock
                </DropdownMenuItem>
              )}
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={() => setDeletingProduct(prod)}
                className="gap-2 text-xs text-destructive focus:text-destructive"
              >
                <Trash2 className="h-3.5 w-3.5" /> Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ], [canManageProducts, canAdjustStock]);

  // ----------------------------------------------------
  // Table Columns: Stock Balances
  // ----------------------------------------------------
  const stockColumns = useMemo<ColumnDef<StockLevelItem>[]>(() => [
    {
      accessorKey: 'warehouse',
      header: 'Warehouse',
      cell: ({ row }) => {
        const wh = row.original.warehouse;
        return (
          <div className="flex flex-col">
            <span className="font-semibold text-foreground text-xs">{wh?.name || '—'}</span>
            <span className="text-[11px] font-mono text-muted-foreground">{wh?.code}</span>
          </div>
        );
      },
    },
    {
      accessorKey: 'product',
      header: 'Product',
      cell: ({ row }) => {
        const prod = row.original.product;
        return (
          <div className="flex flex-col">
            <span className="font-semibold text-foreground text-xs">{prod?.name || '—'}</span>
            <span className="text-[11px] font-mono text-primary">{prod?.sku}</span>
          </div>
        );
      },
    },
    {
      accessorKey: 'quantity_on_hand',
      header: ({ column }) => <DataTableColumnHeader column={column} title="On Hand" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-foreground">
          {row.original.quantity_on_hand}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'quantity_reserved',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Reserved" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs text-muted-foreground">
          {row.original.quantity_reserved}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'quantity_available',
      header: 'Available',
      cell: ({ row }) => {
        const avail = row.original.quantity_available;
        return (
          <span
            className={`font-mono text-xs font-bold ${
              avail <= 0 ? 'text-destructive' : 'text-foreground'
            }`}
          >
            {avail}
          </span>
        );
      },
    },
    {
      id: 'stock_status',
      header: 'Stock Status',
      cell: ({ row }) => {
        const avail = row.original.quantity_available;
        const threshold = row.original.product?.low_stock_threshold ?? 0;

        if (avail <= 0) {
          return (
            <Badge variant="destructive" className="text-[11px] gap-1">
              <AlertCircle className="h-3 w-3" /> Out of Stock
            </Badge>
          );
        }
        if (avail <= threshold) {
          return (
            <Badge className="bg-amber-500/15 text-amber-700 border-amber-500/30 text-[11px] gap-1">
              <AlertTriangle className="h-3 w-3" /> Low Stock
            </Badge>
          );
        }
        return (
          <Badge className="bg-emerald-500/10 text-emerald-600 border-emerald-500/20 text-[11px] gap-1">
            <CheckCircle2 className="h-3 w-3" /> In Stock
          </Badge>
        );
      },
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        if (!canAdjustStock) return null;
        const level = row.original;
        return (
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setAdjustmentPrefill({
                warehouseId: level.warehouse_id,
                productId: level.product_id,
              });
              setIsAdjustmentModalOpen(true);
            }}
            className="h-7 text-xs gap-1"
          >
            <SlidersHorizontal className="h-3.5 w-3.5" /> Adjust
          </Button>
        );
      },
    },
  ], [canAdjustStock]);

  // ----------------------------------------------------
  // Table Columns: Stock Adjustments
  // ----------------------------------------------------
  const adjustmentColumns = useMemo<ColumnDef<StockAdjustmentItem>[]>(() => [
    {
      accessorKey: 'adjustment_number',
      header: 'Adjustment #',
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('adjustment_number')}
        </span>
      ),
    },
    {
      accessorKey: 'warehouse',
      header: 'Warehouse',
      cell: ({ row }) => (
        <span className="text-xs font-medium text-foreground">
          {row.original.warehouse?.name || '—'}
        </span>
      ),
    },
    {
      accessorKey: 'reason',
      header: 'Reason',
      cell: ({ row }) => (
        <span className="text-xs text-foreground truncate max-w-xs block" title={row.original.reason}>
          {row.original.reason}
        </span>
      ),
    },
    {
      accessorKey: 'total_cost_impact_cents',
      header: 'Cost Impact',
      cell: ({ row }) => {
        const cents = row.original.total_cost_impact_cents;
        return (
          <span
            className={`font-mono text-xs font-semibold ${
              cents < 0 ? 'text-destructive' : cents > 0 ? 'text-emerald-600' : 'text-muted-foreground'
            }`}
          >
            {cents < 0 ? '-' : cents > 0 ? '+' : ''}
            {formatMoneyCents(Math.abs(cents))}
          </span>
        );
      },
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => (
        <Badge variant="secondary" className="capitalize text-[11px]">
          {row.getValue('status')}
        </Badge>
      ),
    },
    {
      accessorKey: 'created_at',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Date" />,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.created_at)}
        </span>
      ),
      enableSorting: true,
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setViewingAdjustment(row.original)}
          className="h-8 w-8 p-0"
          title="View Details"
        >
          <Eye className="h-4 w-4" />
        </Button>
      ),
    },
  ], []);

  // ----------------------------------------------------
  // Table Columns: Stock Valuation (AVCO)
  // ----------------------------------------------------
  const valuationColumns = useMemo<ColumnDef<ProductValuationItem>[]>(() => [
    {
      accessorKey: 'sku',
      header: ({ column }) => <DataTableColumnHeader column={column} title="SKU" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.original.sku}
        </span>
      ),
    },
    {
      accessorKey: 'name',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Product Name" />,
      cell: ({ row }) => (
        <div>
          <span className="text-sm font-semibold text-foreground block truncate max-w-xs">
            {row.original.name}
          </span>
          <span className="text-[11px] text-muted-foreground">
            {row.original.category_name || 'Uncategorized'} · {row.original.unit_of_measure_name || 'Units'}
          </span>
        </div>
      ),
    },
    {
      accessorKey: 'total_quantity_on_hand',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Total On Hand" className="text-right" />,
      cell: ({ row }) => {
        const qty = row.original.total_quantity_on_hand;
        return (
          <div className="text-right">
            <span className="font-mono font-semibold text-sm tabular-nums block text-foreground">
              {qty.toLocaleString()}
            </span>
            <Badge
              variant={qty > 0 ? 'success' : 'destructive'}
              className="text-[10px] mt-0.5 px-1.5 py-0"
            >
              {qty > 0 ? 'In Stock' : 'Out of Stock'}
            </Badge>
          </div>
        );
      },
    },
    {
      accessorKey: 'moving_average_cost_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Moving Avg Cost" className="text-right" />,
      cell: ({ row }) => (
        <div className="text-right font-mono font-bold text-sm text-primary tabular-nums">
          {formatMoneyCents(row.original.moving_average_cost_cents)}
        </div>
      ),
    },
    {
      accessorKey: 'selling_price_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Selling Price" className="text-right" />,
      cell: ({ row }) => (
        <div className="text-right font-mono text-xs text-foreground tabular-nums">
          {formatMoneyCents(row.original.selling_price_cents)}
        </div>
      ),
    },
    {
      accessorKey: 'margin_percent',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Margin" className="text-right" />,
      cell: ({ row }) => {
        const margin = row.original.margin_percent;
        if (margin === null) return <span className="text-right text-muted-foreground block">—</span>;
        const isPositive = margin >= 0;
        return (
          <div className="text-right font-mono text-xs font-semibold tabular-nums">
            <span
              className={`px-1.5 py-0.5 rounded text-[11px] ${
                isPositive
                  ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                  : 'bg-destructive/10 text-destructive'
              }`}
            >
              {isPositive ? `+${margin}%` : `${margin}%`}
            </span>
          </div>
        );
      },
    },
    {
      accessorKey: 'total_valuation_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Stock Valuation" className="text-right" />,
      cell: ({ row }) => (
        <div className="text-right font-mono font-bold text-sm text-foreground tabular-nums">
          {formatMoneyCents(row.original.total_valuation_cents)}
        </div>
      ),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <div className="text-right">
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setSelectedCostHistoryProductId(row.original.product_id);
              setIsCostHistoryOpen(true);
            }}
            className="text-xs h-8 gap-1.5"
          >
            <History className="w-3.5 h-3.5 text-muted-foreground" />
            <span>Cost Audit</span>
          </Button>
        </div>
      ),
    },
  ], []);

  return (
    <AppLayout activeNav="inventory" title="Inventory Management">
      <div className="p-6 space-y-6 max-w-[1400px] mx-auto">
        {/* Header section */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
              <Package className="h-7 w-7 text-primary" /> Inventory & Products
            </h1>
            <p className="text-xs text-muted-foreground mt-1">
              Central catalog for items, on-hand multi-warehouse stock levels, and audited adjustments.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            {canAdjustStock && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  setAdjustmentPrefill({});
                  setIsAdjustmentModalOpen(true);
                }}
                className="gap-2 text-xs h-9"
              >
                <SlidersHorizontal className="h-4 w-4" /> Stock Adjustment
              </Button>
            )}

            {canManageProducts && (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setIsCsvImportOpen(true)}
                  className="gap-2 text-xs h-9"
                >
                  <FileSpreadsheet className="h-4 w-4" /> Import CSV
                </Button>
                <Button
                  size="sm"
                  onClick={() => {
                    setEditingProduct(null);
                    setIsProductModalOpen(true);
                  }}
                  className="gap-2 text-xs h-9"
                >
                  <Plus className="h-4 w-4" /> Add Product
                </Button>
              </>
            )}
          </div>
        </div>

        {/* Feedback Alert */}
        {feedback && (
          <Alert
            variant={feedback.type === 'success' ? 'default' : 'destructive'}
            className={
              feedback.type === 'success'
                ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/20'
                : ''
            }
          >
            {feedback.type === 'success' ? (
              <CheckCircle2 className="h-4 w-4" />
            ) : (
              <AlertCircle className="h-4 w-4" />
            )}
            <AlertDescription className="text-xs flex items-center justify-between">
              <span>{feedback.message}</span>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setFeedback(null)}
                className="h-5 text-[10px] px-1 hover:bg-transparent"
              >
                Dismiss
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {/* Tab Navigation */}
        <div className="flex items-center gap-1 border-b pb-px">
          {[
            { id: 'products', label: 'Products Catalog', icon: Package, count: productsTotal },
            { id: 'balances', label: 'Stock Balances', icon: Boxes, count: stockTotal },
            { id: 'valuation', label: 'Stock Valuation (AVCO)', icon: TrendingUp, count: valuationTotal },
            { id: 'adjustments', label: 'Stock Adjustments', icon: ClipboardList, count: adjustmentsTotal },
            { id: 'warehouses', label: 'Warehouses', icon: Warehouse, count: warehouses.length },
          ].map((tab) => {
            const Icon = tab.icon;
            const isActive = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => setActiveTab(tab.id as TabKey)}
                className={`flex items-center gap-2 px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors ${
                  isActive
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground hover:border-muted-foreground/30'
                }`}
              >
                <Icon className="h-4 w-4" />
                <span>{tab.label}</span>
                {tab.count > 0 && (
                  <span
                    className={`ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-mono ${
                      isActive ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                    }`}
                  >
                    {tab.count}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {/* TAB 1: Products Catalog */}
        {activeTab === 'products' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
              <div className="flex flex-wrap items-center gap-2">
                <select
                  aria-label="Filter by category"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={productCategoryFilter}
                  onChange={(e) => {
                    setProductCategoryFilter(e.target.value);
                    setProductPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="">All Categories</option>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>

                <select
                  aria-label="Filter by status"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={productStatusFilter}
                  onChange={(e) => {
                    setProductStatusFilter(e.target.value as 'all' | 'active' | 'inactive');
                    setProductPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="all">All Status</option>
                  <option value="active">Active Only</option>
                  <option value="inactive">Inactive Only</option>
                </select>
              </div>

              <div className="flex items-center gap-2.5">
                <div className="relative w-full sm:w-64">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search name, SKU, barcode..."
                    value={productSearch}
                    onChange={(e) => {
                      setProductSearch(e.target.value);
                      setProductPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchProducts}
                  disabled={productsLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh products"
                >
                  <RefreshCw className={`h-4 w-4 ${productsLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={productColumns}
              data={products}
              isLoading={productsLoading}
              serverSide={{
                totalRows: productsTotal,
                pagination: productPagination,
                onPaginationChange: setProductPagination,
                sorting: productSorting,
                onSortingChange: setProductSorting,
              }}
              emptyTitle="No products found"
              emptyDescription={
                productSearch || productCategoryFilter
                  ? 'Try modifying your filter or search keywords.'
                  : 'Start building your inventory catalog by adding or importing products.'
              }
            />
          </div>
        )}

        {/* TAB 2: Stock Balances */}
        {activeTab === 'balances' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <select
                  aria-label="Filter by warehouse"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={stockWarehouseFilter}
                  onChange={(e) => {
                    setStockWarehouseFilter(e.target.value);
                    setStockPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="">All Warehouses</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex items-center gap-2.5">
                <div className="relative w-full sm:w-64">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search product or warehouse..."
                    value={stockSearch}
                    onChange={(e) => {
                      setStockSearch(e.target.value);
                      setStockPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchStockLevels}
                  disabled={stockLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh stock balances"
                >
                  <RefreshCw className={`h-4 w-4 ${stockLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={stockColumns}
              data={stockLevels}
              isLoading={stockLoading}
              serverSide={{
                totalRows: stockTotal,
                pagination: stockPagination,
                onPaginationChange: setStockPagination,
                sorting: stockSorting,
                onSortingChange: setStockSorting,
              }}
              emptyTitle="No stock balances found"
              emptyDescription={
                stockSearch || stockWarehouseFilter
                  ? 'No matching stock records found for current filters.'
                  : 'Record stock adjustments or purchase orders to populate inventory levels.'
              }
            />
          </div>
        )}

        {/* TAB 5: Stock Valuation & AVCO Costing */}
        {activeTab === 'valuation' && (
          <div className="space-y-4">
            {/* KPI Summary Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                <div className="flex items-center justify-between text-muted-foreground text-xs font-medium">
                  <span>Total Inventory Valuation</span>
                  <TrendingUp className="h-4 w-4 text-primary" />
                </div>
                <div className="mt-2 text-2xl font-bold tracking-tight text-foreground font-mono tabular-nums">
                  {formatMoneyCents(valuationSummary?.total_valuation_cents || 0)}
                </div>
                <p className="mt-1 text-[11px] text-muted-foreground">
                  Continuous moving average asset value
                </p>
              </div>

              <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                <div className="flex items-center justify-between text-muted-foreground text-xs font-medium">
                  <span>Total Units In Stock</span>
                  <Boxes className="h-4 w-4 text-primary" />
                </div>
                <div className="mt-2 text-2xl font-bold tracking-tight text-foreground font-mono tabular-nums">
                  {(valuationSummary?.total_units_on_hand || 0).toLocaleString()}
                </div>
                <p className="mt-1 text-[11px] text-muted-foreground">
                  Physical units across depots
                </p>
              </div>

              <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                <div className="flex items-center justify-between text-muted-foreground text-xs font-medium">
                  <span>Valued Products</span>
                  <Package className="h-4 w-4 text-primary" />
                </div>
                <div className="mt-2 text-2xl font-bold tracking-tight text-foreground font-mono tabular-nums">
                  {valuationSummary?.valued_products_count || 0}{' '}
                  <span className="text-xs font-normal text-muted-foreground">
                    / {valuationSummary?.total_products_count || 0} active
                  </span>
                </div>
                <p className="mt-1 text-[11px] text-muted-foreground">
                  Items currently carrying positive stock
                </p>
              </div>

              <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                <div className="flex items-center justify-between text-muted-foreground text-xs font-medium">
                  <span>Costing Method</span>
                  <Badge variant="outline" className="text-[10px] font-mono">
                    AVCO
                  </Badge>
                </div>
                <div className="mt-2 text-sm font-semibold text-foreground">
                  Perpetual Moving Average
                </div>
                <p className="mt-1 text-[11px] text-muted-foreground">
                  Real-time updates on receipt & COGS release
                </p>
              </div>
            </div>

            {/* Filter toolbar */}
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
              <div className="flex flex-wrap items-center gap-2">
                <select
                  aria-label="Filter valuation by category"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={valuationCategoryFilter}
                  onChange={(e) => {
                    setValuationCategoryFilter(e.target.value);
                    setValuationPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="">All Categories</option>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>

                <select
                  aria-label="Filter valuation by warehouse"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={valuationWarehouseFilter}
                  onChange={(e) => {
                    setValuationWarehouseFilter(e.target.value);
                    setValuationPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="">All Warehouses</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>

                <label className="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer select-none px-2 py-1 rounded border border-border bg-card">
                  <input
                    type="checkbox"
                    checked={valuationInStockOnly}
                    onChange={(e) => {
                      setValuationInStockOnly(e.target.checked);
                      setValuationPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5"
                  />
                  <span>In Stock Only</span>
                </label>
              </div>

              <div className="flex items-center gap-2.5">
                <div className="relative w-full sm:w-64">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search SKU, product name..."
                    value={valuationSearch}
                    onChange={(e) => {
                      setValuationSearch(e.target.value);
                      setValuationPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchValuation}
                  disabled={valuationLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh valuation data"
                >
                  <RefreshCw className={`h-4 w-4 ${valuationLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={valuationColumns}
              data={valuationItems}
              isLoading={valuationLoading}
              serverSide={{
                totalRows: valuationTotal,
                pagination: valuationPagination,
                onPaginationChange: setValuationPagination,
                sorting: valuationSorting,
                onSortingChange: setValuationSorting,
              }}
              emptyTitle="No valuation records found"
              emptyDescription="No inventory products matched your filter criteria."
            />
          </div>
        )}

        {/* TAB 3: Stock Adjustments */}
        {activeTab === 'adjustments' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <select
                  aria-label="Filter adjustments by warehouse"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={adjustmentWarehouseFilter}
                  onChange={(e) => {
                    setAdjustmentWarehouseFilter(e.target.value);
                    setAdjustmentPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="">All Warehouses</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name} ({w.code})
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex items-center gap-2.5">
                <div className="relative w-full sm:w-64">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search adjustment #, reason..."
                    value={adjustmentSearch}
                    onChange={(e) => {
                      setAdjustmentSearch(e.target.value);
                      setAdjustmentPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchAdjustments}
                  disabled={adjustmentsLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh adjustments"
                >
                  <RefreshCw className={`h-4 w-4 ${adjustmentsLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={adjustmentColumns}
              data={adjustments}
              isLoading={adjustmentsLoading}
              serverSide={{
                totalRows: adjustmentsTotal,
                pagination: adjustmentPagination,
                onPaginationChange: setAdjustmentPagination,
                sorting: adjustmentSorting,
                onSortingChange: setAdjustmentSorting,
              }}
              emptyTitle="No stock adjustments recorded"
              emptyDescription="Use 'Stock Adjustment' to record inventory discrepancies, write-offs, or cycle counts."
            />
          </div>
        )}

        {/* TAB 4: Warehouses */}
        {activeTab === 'warehouses' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <p className="text-xs text-muted-foreground">
                Manage physical depots, stores, and fulfillment hubs.
              </p>
              {canManageProducts && (
                <Button
                  size="sm"
                  onClick={() => {
                    setEditingWarehouse(null);
                    setIsWarehouseModalOpen(true);
                  }}
                  className="gap-2 text-xs h-9"
                >
                  <Plus className="h-4 w-4" /> Add Warehouse
                </Button>
              )}
            </div>

            <div className="border rounded-md overflow-hidden bg-card">
              <table className="w-full text-xs">
                <thead className="bg-muted/50 border-b text-muted-foreground">
                  <tr>
                    <th className="p-3 text-left font-semibold">Warehouse Name</th>
                    <th className="p-3 text-left font-semibold">Code</th>
                    <th className="p-3 text-left font-semibold">Address</th>
                    <th className="p-3 text-center font-semibold">Default</th>
                    <th className="p-3 text-center font-semibold">Status</th>
                    {canManageProducts && <th className="p-3 text-right font-semibold">Actions</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {warehousesLoading ? (
                    <tr>
                      <td colSpan={6} className="p-6 text-center text-muted-foreground">
                        Loading warehouses...
                      </td>
                    </tr>
                  ) : warehouses.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="p-6 text-center text-muted-foreground">
                        No warehouses configured. Click &quot;Add Warehouse&quot; to create one.
                      </td>
                    </tr>
                  ) : (
                    warehouses.map((wh) => (
                      <tr key={wh.id} className="hover:bg-muted/20">
                        <td className="p-3 font-semibold text-foreground">{wh.name}</td>
                        <td className="p-3">
                          <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
                            {wh.code}
                          </span>
                        </td>
                        <td className="p-3 text-muted-foreground">{wh.address || '—'}</td>
                        <td className="p-3 text-center">
                          {wh.is_default && (
                            <Badge className="bg-primary/10 text-primary border-primary/20 text-[11px]">
                              Default
                            </Badge>
                          )}
                        </td>
                        <td className="p-3 text-center">
                          <Badge
                            variant={wh.is_active ? 'secondary' : 'destructive'}
                            className={
                              wh.is_active
                                ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 text-[11px]'
                                : 'text-[11px]'
                            }
                          >
                            {wh.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </td>
                        {canManageProducts && (
                          <td className="p-3 text-right">
                            <div className="flex items-center justify-end gap-1">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                  setEditingWarehouse(wh);
                                  setIsWarehouseModalOpen(true);
                                }}
                                className="h-7 w-7 p-0"
                                title="Edit warehouse"
                              >
                                <Edit2 className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setDeletingWarehouse(wh)}
                                className="h-7 w-7 p-0 text-destructive hover:text-destructive"
                                title="Delete warehouse"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            </div>
                          </td>
                        )}
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Product Form Modal */}
        <ProductFormModal
          open={isProductModalOpen}
          onOpenChange={setIsProductModalOpen}
          product={editingProduct}
          categories={categories}
          unitsOfMeasure={unitsOfMeasure}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            fetchProducts();
          }}
        />

        {/* Stock Adjustment Modal */}
        <StockAdjustmentModal
          open={isAdjustmentModalOpen}
          onOpenChange={setIsAdjustmentModalOpen}
          warehouses={warehouses}
          products={products}
          defaultWarehouseId={adjustmentPrefill.warehouseId}
          defaultProductId={adjustmentPrefill.productId}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            if (activeTab === 'balances') fetchStockLevels();
            if (activeTab === 'adjustments') fetchAdjustments();
          }}
        />

        {/* Warehouse Form Modal */}
        <WarehouseFormModal
          open={isWarehouseModalOpen}
          onOpenChange={setIsWarehouseModalOpen}
          warehouse={editingWarehouse}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            fetchWarehousesList();
            fetchReferenceData();
          }}
        />

        {/* CSV Import Dialog */}
        <CsvImportDialog
          open={isCsvImportOpen}
          onOpenChange={setIsCsvImportOpen}
          title="Import Products Catalog"
          description="Upload a CSV spreadsheet with products (SKU, Name, Cost Price, Selling Price, etc.)."
          templateUrl={inventoryApi.getProductTemplateUrl()}
          onUpload={async (file, duplicateAction) => {
            const res = await inventoryApi.uploadProductCsv(file, duplicateAction);
            return res.data;
          }}
          onCheckStatus={async (importId) => {
            const res = await inventoryApi.getProductImportStatus(importId);
            return res.data;
          }}
          onSuccess={() => {
            setFeedback({ type: 'success', message: 'Products imported successfully.' });
            fetchProducts();
          }}
        />

        {/* Delete Product Confirmation Dialog */}
        <Dialog open={Boolean(deletingProduct)} onOpenChange={(open) => !open && setDeletingProduct(null)}>
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
            <div className="bg-background rounded-lg border shadow-lg max-w-md w-full p-6 space-y-4">
              <DialogHeader>
                <DialogTitle>Delete Product</DialogTitle>
                <DialogDescription className="text-xs">
                  Are you sure you want to delete product{' '}
                  <strong className="text-foreground">{deletingProduct?.name}</strong> ({deletingProduct?.sku})?
                  This action cannot be undone.
                </DialogDescription>
              </DialogHeader>
              <DialogFooter className="flex items-center justify-end gap-2 pt-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setDeletingProduct(null)}
                  disabled={actionSubmitting}
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleDeleteProduct}
                  disabled={actionSubmitting}
                >
                  {actionSubmitting ? 'Deleting...' : 'Delete Product'}
                </Button>
              </DialogFooter>
            </div>
          </div>
        </Dialog>

        {/* Delete Warehouse Confirmation Dialog */}
        <Dialog open={Boolean(deletingWarehouse)} onOpenChange={(open) => !open && setDeletingWarehouse(null)}>
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
            <div className="bg-background rounded-lg border shadow-lg max-w-md w-full p-6 space-y-4">
              <DialogHeader>
                <DialogTitle>Delete Warehouse</DialogTitle>
                <DialogDescription className="text-xs">
                  Are you sure you want to delete warehouse{' '}
                  <strong className="text-foreground">{deletingWarehouse?.name}</strong> ({deletingWarehouse?.code})?
                  This action cannot be undone if stock is associated.
                </DialogDescription>
              </DialogHeader>
              <DialogFooter className="flex items-center justify-end gap-2 pt-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setDeletingWarehouse(null)}
                  disabled={actionSubmitting}
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleDeleteWarehouse}
                  disabled={actionSubmitting}
                >
                  {actionSubmitting ? 'Deleting...' : 'Delete Warehouse'}
                </Button>
              </DialogFooter>
            </div>
          </div>
        </Dialog>

        {/* View Adjustment Detail Dialog */}
        <Dialog open={Boolean(viewingAdjustment)} onOpenChange={(open) => !open && setViewingAdjustment(null)}>
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
            <div className="bg-background rounded-lg border shadow-lg max-w-2xl w-full p-6 space-y-4 max-h-[85vh] flex flex-col overflow-hidden">
              <DialogHeader>
                <DialogTitle className="flex items-center justify-between">
                  <span>Stock Adjustment {viewingAdjustment?.adjustment_number}</span>
                  <Badge variant="secondary" className="capitalize text-xs">
                    {viewingAdjustment?.status}
                  </Badge>
                </DialogTitle>
                <DialogDescription className="text-xs">
                  Recorded on {formatDate(viewingAdjustment?.created_at)} for warehouse{' '}
                  <strong className="text-foreground">{viewingAdjustment?.warehouse?.name}</strong>
                </DialogDescription>
              </DialogHeader>

              <div className="flex-1 overflow-y-auto space-y-4">
                <div className="p-3 rounded-md bg-muted/40 space-y-1 text-xs">
                  <span className="font-semibold text-muted-foreground">Reason:</span>
                  <p className="text-foreground">{viewingAdjustment?.reason}</p>
                </div>

                {viewingAdjustment?.items && viewingAdjustment.items.length > 0 && (
                  <div className="border rounded-md overflow-hidden">
                    <table className="w-full text-xs">
                      <thead className="bg-muted/50 border-b text-muted-foreground">
                        <tr>
                          <th className="p-2.5 text-left font-medium">Product</th>
                          <th className="p-2.5 text-right font-medium">Before</th>
                          <th className="p-2.5 text-right font-medium">Delta</th>
                          <th className="p-2.5 text-right font-medium">After</th>
                          <th className="p-2.5 text-right font-medium">Line Impact</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-border">
                        {viewingAdjustment.items.map((line) => (
                          <tr key={line.id}>
                            <td className="p-2.5">
                              <span className="font-semibold text-foreground">
                                {line.product?.name || line.product_id}
                              </span>
                            </td>
                            <td className="p-2.5 text-right font-mono text-muted-foreground">
                              {line.quantity_before}
                            </td>
                            <td className="p-2.5 text-right font-mono font-bold">
                              <span
                                className={
                                  line.quantity_delta < 0
                                    ? 'text-destructive'
                                    : 'text-emerald-600'
                                }
                              >
                                {line.quantity_delta > 0 ? `+${line.quantity_delta}` : line.quantity_delta}
                              </span>
                            </td>
                            <td className="p-2.5 text-right font-mono text-foreground">
                              {line.quantity_after}
                            </td>
                            <td className="p-2.5 text-right font-mono">
                              {line.line_cost_impact_cents !== null
                                ? formatMoneyCents(line.line_cost_impact_cents)
                                : '—'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                <div className="flex items-center justify-between p-3 rounded-md bg-muted/20 text-xs">
                  <span className="font-semibold text-muted-foreground">Total Financial Impact:</span>
                  <span className="font-mono font-bold text-sm">
                    {formatMoneyCents(viewingAdjustment?.total_cost_impact_cents || 0)}
                  </span>
                </div>
              </div>

              <DialogFooter className="pt-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setViewingAdjustment(null)}
                >
                  Close
                </Button>
              </DialogFooter>
            </div>
          </div>
        </Dialog>

        {/* Moving Average Cost History Modal */}
        <CostHistoryModal
          productId={selectedCostHistoryProductId}
          isOpen={isCostHistoryOpen}
          onClose={() => {
            setIsCostHistoryOpen(false);
            setSelectedCostHistoryProductId(null);
          }}
        />
      </div>
    </AppLayout>
  );
}
