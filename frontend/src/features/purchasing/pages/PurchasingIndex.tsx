import { useState, useEffect, useCallback, useMemo } from 'react';
import type { ColumnDef, PaginationState, SortingState } from '@tanstack/react-table';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { DataTable, DataTableColumnHeader } from '@/components/ui/data-table';
import { PurchaseOrderFormModal } from '../components/PurchaseOrderFormModal';
import { GoodsReceiptModal } from '../components/GoodsReceiptModal';
import { SupplierBillFormModal } from '../components/SupplierBillFormModal';
import { PurchaseOrderDetailModal } from '../components/PurchaseOrderDetailModal';
import { SupplierBillDetailModal } from '../components/SupplierBillDetailModal';
import { useCan } from '@/features/auth/hooks/useCan';
import { purchasingApi } from '../api/purchasingApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type {
  PurchaseOrder,
  PurchaseOrderStatus,
  SupplierBill,
  SupplierBillStatus,
} from '../types/purchasing.types';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { ProductItem, WarehouseItem } from '@/features/inventory/types/inventory.types';
import {
  FileText,
  ReceiptText,
  DollarSign,
  Plus,
  RefreshCw,
  Search,
  MoreHorizontal,
  Eye,
  Printer,
  CheckCircle2,
  PackageCheck,
  XCircle,
  AlertCircle,
  TrendingDown,
} from 'lucide-react';

type TabKey = 'orders' | 'bills' | 'payables';

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

function formatDate(dateStr?: string | null): string {
  if (!dateStr) return '—';
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

export default function PurchasingIndex() {
  const canManagePurchases = useCan('purchases.manage');
  const canDraftPo = useCan('purchases.po.draft') || canManagePurchases;
  const canApprovePo = useCan('purchases.po.approve') || canManagePurchases;
  const canPostBill = useCan('purchases.bill.post') || canManagePurchases;
  const canReceiveGoods = useCan('stock.adjust') || canManagePurchases;

  // Active Tab
  const [activeTab, setActiveTab] = useState<TabKey>('orders');

  // Shared Reference Data
  const [suppliers, setSuppliers] = useState<ContactItem[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseItem[]>([]);
  const [products, setProducts] = useState<ProductItem[]>([]);

  // Notifications
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // ----------------------------------------------------
  // Tab 1: Purchase Orders State
  // ----------------------------------------------------
  const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrder[]>([]);
  const [poLoading, setPoLoading] = useState(true);
  const [poTotal, setPoTotal] = useState(0);
  const [poSearch, setPoSearch] = useState('');
  const [poStatusFilter, setPoStatusFilter] = useState<PurchaseOrderStatus | 'all'>('all');
  const [poPagination, setPoPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [poSorting, setPoSorting] = useState<SortingState>([
    { id: 'created_at', desc: true },
  ]);

  // PO Modals
  const [isPoModalOpen, setIsPoModalOpen] = useState(false);
  const [viewingPo, setViewingPo] = useState<PurchaseOrder | null>(null);
  const [receiptPo, setReceiptPo] = useState<PurchaseOrder | null>(null);

  // ----------------------------------------------------
  // Tab 2: Supplier Bills State
  // ----------------------------------------------------
  const [bills, setBills] = useState<SupplierBill[]>([]);
  const [billsLoading, setBillsLoading] = useState(false);
  const [billsTotal, setBillsTotal] = useState(0);
  const [billSearch, setBillSearch] = useState('');
  const [billStatusFilter, setBillStatusFilter] = useState<SupplierBillStatus | 'all'>('all');
  const [billPagination, setBillPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [billSorting, setBillSorting] = useState<SortingState>([
    { id: 'created_at', desc: true },
  ]);

  // Bill Modals
  const [isBillModalOpen, setIsBillModalOpen] = useState(false);
  const [viewingBill, setViewingBill] = useState<SupplierBill | null>(null);

  // ----------------------------------------------------
  // Tab 3: Payables Balances State
  // ----------------------------------------------------
  const [payablesLoading, setPayablesLoading] = useState(false);
  const [supplierBalances, setSupplierBalances] = useState<Array<{ supplier: ContactItem; balanceCents: number }>>([]);

  // Fetch reference data (Suppliers, Warehouses, Products)
  const fetchReferenceData = useCallback(async () => {
    try {
      const [contactsRes, whRes, prodRes] = await Promise.all([
        contactsApi.getContacts({ per_page: 100 }),
        inventoryApi.getWarehouses({ per_page: 100 }),
        inventoryApi.getProducts({ per_page: 100 }),
      ]);
      const supps = contactsRes.data.filter((c) => c.type === 'supplier' || c.type === 'both');
      setSuppliers(supps);
      setWarehouses(whRes.data);
      setProducts(prodRes.data);
    } catch {
      // Ignore reference load errors
    }
  }, []);

  useEffect(() => {
    fetchReferenceData();
  }, [fetchReferenceData]);

  // Fetch Purchase Orders
  const fetchPurchaseOrders = useCallback(async () => {
    setPoLoading(true);
    try {
      const statusParam = poStatusFilter === 'all' ? undefined : poStatusFilter;
      const res = await purchasingApi.getPurchaseOrders({
        search: poSearch || undefined,
        status: statusParam,
        page: poPagination.pageIndex + 1,
        per_page: poPagination.pageSize,
      });

      setPurchaseOrders(res.data);
      if (res.meta && typeof res.meta.total === 'number') {
        setPoTotal(res.meta.total);
      } else {
        setPoTotal(res.data.length);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch purchase orders';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setPoLoading(false);
    }
  }, [poSearch, poStatusFilter, poPagination]);

  // Fetch Supplier Bills
  const fetchBills = useCallback(async () => {
    setBillsLoading(true);
    try {
      const statusParam = billStatusFilter === 'all' ? undefined : billStatusFilter;
      const res = await purchasingApi.getBills({
        search: billSearch || undefined,
        status: statusParam,
        page: billPagination.pageIndex + 1,
        per_page: billPagination.pageSize,
      });

      setBills(res.data);
      if (res.meta && typeof res.meta.total === 'number') {
        setBillsTotal(res.meta.total);
      } else {
        setBillsTotal(res.data.length);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to fetch supplier bills';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setBillsLoading(false);
    }
  }, [billSearch, billStatusFilter, billPagination]);

  // Fetch Supplier Payables
  const fetchPayables = useCallback(async () => {
    if (suppliers.length === 0) return;
    setPayablesLoading(true);
    try {
      const balances = await Promise.all(
        suppliers.map(async (s) => {
          try {
            const res = await purchasingApi.getSupplierPayableBalance(s.id);
            return { supplier: s, balanceCents: res.data.outstanding_payable_cents };
          } catch {
            return { supplier: s, balanceCents: 0 };
          }
        })
      );
      setSupplierBalances(balances);
    } catch {
      // Ignore
    } finally {
      setPayablesLoading(false);
    }
  }, [suppliers]);

  useEffect(() => {
    if (activeTab === 'orders') {
      fetchPurchaseOrders();
    } else if (activeTab === 'bills') {
      fetchBills();
    } else if (activeTab === 'payables') {
      fetchPayables();
    }
  }, [activeTab, fetchPurchaseOrders, fetchBills, fetchPayables]);

  // Quick Action Handlers
  const handleApprovePo = useCallback(async (po: PurchaseOrder) => {
    try {
      await purchasingApi.approvePurchaseOrder(po.id);
      setFeedback({ type: 'success', message: `Purchase Order "${po.order_number}" approved.` });
      fetchPurchaseOrders();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to approve order.';
      setFeedback({ type: 'error', message: msg });
    }
  }, [fetchPurchaseOrders]);

  const handleCancelPo = useCallback(async (po: PurchaseOrder) => {
    try {
      await purchasingApi.cancelPurchaseOrder(po.id);
      setFeedback({ type: 'success', message: `Purchase Order "${po.order_number}" cancelled.` });
      fetchPurchaseOrders();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to cancel order.';
      setFeedback({ type: 'error', message: msg });
    }
  }, [fetchPurchaseOrders]);

  // ----------------------------------------------------
  // Table Columns: Purchase Orders
  // ----------------------------------------------------
  const poColumns = useMemo<ColumnDef<PurchaseOrder>[]>(() => [
    {
      accessorKey: 'order_number',
      header: ({ column }) => <DataTableColumnHeader column={column} title="PO Number" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('order_number')}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'supplier_id',
      header: 'Supplier',
      cell: ({ row }) => {
        const supp = suppliers.find((s) => s.id === row.original.supplier_id);
        return (
          <div className="flex flex-col">
            <span className="font-semibold text-foreground text-xs">{supp?.name || row.original.supplier_id}</span>
            {supp?.code && <span className="font-mono text-[10px] text-muted-foreground">{supp.code}</span>}
          </div>
        );
      },
    },
    {
      accessorKey: 'warehouse_id',
      header: 'Warehouse',
      cell: ({ row }) => {
        const wh = warehouses.find((w) => w.id === row.original.warehouse_id);
        return (
          <span className="text-xs text-muted-foreground">
            {wh?.name || '—'}
          </span>
        );
      },
    },
    {
      accessorKey: 'order_date',
      header: 'Order Date',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.order_date)}
        </span>
      ),
    },
    {
      accessorKey: 'expected_delivery_date',
      header: 'Expected Delivery',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.expected_delivery_date)}
        </span>
      ),
    },
    {
      accessorKey: 'total_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Total" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-foreground">
          {formatMoneyCents(row.original.total_cents)}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => {
        const status = row.original.status;
        const label = row.original.status_label;

        const badgeClass =
          status === 'approved'
            ? 'bg-blue-500/10 text-blue-600 border-blue-500/20'
            : status === 'received'
            ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
            : status === 'partially_received'
            ? 'bg-amber-500/10 text-amber-700 border-amber-500/20'
            : status === 'cancelled'
            ? 'bg-destructive/10 text-destructive border-destructive/20'
            : 'bg-muted text-muted-foreground';

        return (
          <Badge variant="outline" className={`capitalize text-[11px] ${badgeClass}`}>
            {label}
          </Badge>
        );
      },
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const po = row.original;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="h-8 w-8 p-0" title="Order actions">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuItem
                onClick={() => setViewingPo(po)}
                className="gap-2 text-xs"
              >
                <Eye className="h-3.5 w-3.5" /> View Details
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => window.open(purchasingApi.getPurchaseOrderPdfUrl(po.id), '_blank')}
                className="gap-2 text-xs"
              >
                <Printer className="h-3.5 w-3.5" /> Print / PDF
              </DropdownMenuItem>

              {po.status === 'draft' && canApprovePo && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => handleApprovePo(po)}
                    className="gap-2 text-xs text-emerald-600 focus:text-emerald-700"
                  >
                    <CheckCircle2 className="h-3.5 w-3.5" /> Approve Order
                  </DropdownMenuItem>
                </>
              )}

              {(po.status === 'approved' || po.status === 'partially_received') && canReceiveGoods && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => setReceiptPo(po)}
                    className="gap-2 text-xs text-primary font-medium focus:text-primary"
                  >
                    <PackageCheck className="h-3.5 w-3.5" /> Receive Goods
                  </DropdownMenuItem>
                </>
              )}

              {(po.status === 'draft' || po.status === 'approved') && canApprovePo && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => handleCancelPo(po)}
                    className="gap-2 text-xs text-destructive focus:text-destructive"
                  >
                    <XCircle className="h-3.5 w-3.5" /> Cancel Order
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ], [suppliers, warehouses, canApprovePo, canReceiveGoods, handleApprovePo, handleCancelPo]);

  // ----------------------------------------------------
  // Table Columns: Supplier Bills
  // ----------------------------------------------------
  const billColumns = useMemo<ColumnDef<SupplierBill>[]>(() => [
    {
      accessorKey: 'bill_number',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Bill Number" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('bill_number')}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'supplier_invoice_number',
      header: 'Invoice Ref',
      cell: ({ row }) => (
        <span className="font-mono text-xs text-muted-foreground">
          {row.original.supplier_invoice_number || '—'}
        </span>
      ),
    },
    {
      accessorKey: 'supplier_id',
      header: 'Supplier',
      cell: ({ row }) => {
        const supp = suppliers.find((s) => s.id === row.original.supplier_id);
        return (
          <div className="flex flex-col">
            <span className="font-semibold text-foreground text-xs">{supp?.name || row.original.supplier_id}</span>
            {supp?.code && <span className="font-mono text-[10px] text-muted-foreground">{supp.code}</span>}
          </div>
        );
      },
    },
    {
      accessorKey: 'bill_date',
      header: 'Bill Date',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{formatDate(row.original.bill_date)}</span>
      ),
    },
    {
      accessorKey: 'due_date',
      header: 'Due Date',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{formatDate(row.original.due_date)}</span>
      ),
    },
    {
      accessorKey: 'total_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Total Bill" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-foreground">
          {formatMoneyCents(row.original.total_cents)}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'amount_paid_cents',
      header: 'Paid',
      cell: ({ row }) => (
        <span className="font-mono text-xs text-emerald-600">
          {formatMoneyCents(row.original.amount_paid_cents)}
        </span>
      ),
    },
    {
      accessorKey: 'amount_due_cents',
      header: 'Balance Due',
      cell: ({ row }) => (
        <span className="font-mono text-xs font-bold text-foreground">
          {formatMoneyCents(row.original.amount_due_cents)}
        </span>
      ),
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => {
        const status = row.original.status;
        const label = row.original.status_label;

        const badgeClass =
          status === 'posted'
            ? 'bg-blue-500/10 text-blue-600 border-blue-500/20'
            : status === 'paid'
            ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
            : status === 'partially_paid'
            ? 'bg-amber-500/10 text-amber-700 border-amber-500/20'
            : status === 'void'
            ? 'bg-destructive/10 text-destructive border-destructive/20'
            : 'bg-muted text-muted-foreground';

        return (
          <Badge variant="outline" className={`capitalize text-[11px] ${badgeClass}`}>
            {label}
          </Badge>
        );
      },
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setViewingBill(row.original)}
          className="h-8 w-8 p-0"
          title="View Bill Details"
        >
          <Eye className="h-4 w-4" />
        </Button>
      ),
    },
  ], [suppliers]);

  // Total AP Balance calculation
  const totalOutstandingApCents = useMemo(
    () => supplierBalances.reduce((acc, curr) => acc + curr.balanceCents, 0),
    [supplierBalances]
  );

  return (
    <AppLayout activeNav="purchasing" title="Purchasing & Procurement">
      <div className="p-6 space-y-6 max-w-[1400px] mx-auto">
        {/* Header Section */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
              <FileText className="h-7 w-7 text-primary" /> Purchasing & Procurement
            </h1>
            <p className="text-xs text-muted-foreground mt-1">
              End-to-end procurement cycle: Purchase Orders, atomic Goods Receipts into warehouse inventory, and Supplier Bills.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            {canManagePurchases && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setIsBillModalOpen(true)}
                className="gap-2 text-xs h-9"
              >
                <ReceiptText className="h-4 w-4" /> Create Supplier Bill
              </Button>
            )}

            {canDraftPo && (
              <Button
                size="sm"
                onClick={() => setIsPoModalOpen(true)}
                className="gap-2 text-xs h-9"
              >
                <Plus className="h-4 w-4" /> Create Purchase Order
              </Button>
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

        {/* Tabs Bar */}
        <div className="flex items-center gap-1 border-b pb-px">
          {[
            { id: 'orders', label: 'Purchase Orders', icon: FileText, count: poTotal },
            { id: 'bills', label: 'Supplier Bills', icon: ReceiptText, count: billsTotal },
            { id: 'payables', label: 'Accounts Payable Overview', icon: DollarSign, count: supplierBalances.length },
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

        {/* TAB 1: Purchase Orders */}
        {activeTab === 'orders' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <select
                  aria-label="Filter POs by status"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={poStatusFilter}
                  onChange={(e) => {
                    setPoStatusFilter(e.target.value as PurchaseOrderStatus | 'all');
                    setPoPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="all">All Statuses</option>
                  <option value="draft">Draft</option>
                  <option value="approved">Approved</option>
                  <option value="partially_received">Partially Received</option>
                  <option value="received">Fully Received</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>

              <div className="flex items-center gap-2.5">
                <div className="relative w-full sm:w-64">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search PO #, supplier..."
                    value={poSearch}
                    onChange={(e) => {
                      setPoSearch(e.target.value);
                      setPoPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchPurchaseOrders}
                  disabled={poLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh Purchase Orders"
                >
                  <RefreshCw className={`h-4 w-4 ${poLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={poColumns}
              data={purchaseOrders}
              isLoading={poLoading}
              serverSide={{
                totalRows: poTotal,
                pagination: poPagination,
                onPaginationChange: setPoPagination,
                sorting: poSorting,
                onSortingChange: setPoSorting,
              }}
              emptyTitle="No purchase orders found"
              emptyDescription={
                poSearch || poStatusFilter !== 'all'
                  ? 'Try modifying your filter or search keywords.'
                  : 'Start by drafting your first Purchase Order to commercial suppliers.'
              }
            />
          </div>
        )}

        {/* TAB 2: Supplier Bills */}
        {activeTab === 'bills' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <select
                  aria-label="Filter bills by status"
                  className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
                  value={billStatusFilter}
                  onChange={(e) => {
                    setBillStatusFilter(e.target.value as SupplierBillStatus | 'all');
                    setBillPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="all">All Bill Statuses</option>
                  <option value="draft">Draft</option>
                  <option value="posted">Posted</option>
                  <option value="partially_paid">Partially Paid</option>
                  <option value="paid">Fully Paid</option>
                  <option value="void">Voided</option>
                </select>
              </div>

              <div className="flex items-center gap-2.5">
                <div className="relative w-full sm:w-64">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search bill #, invoice ref..."
                    value={billSearch}
                    onChange={(e) => {
                      setBillSearch(e.target.value);
                      setBillPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchBills}
                  disabled={billsLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh Supplier Bills"
                >
                  <RefreshCw className={`h-4 w-4 ${billsLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={billColumns}
              data={bills}
              isLoading={billsLoading}
              serverSide={{
                totalRows: billsTotal,
                pagination: billPagination,
                onPaginationChange: setBillPagination,
                sorting: billSorting,
                onSortingChange: setBillSorting,
              }}
              emptyTitle="No supplier bills found"
              emptyDescription={
                billSearch || billStatusFilter !== 'all'
                  ? 'Try modifying your filter or search keywords.'
                  : 'Record incoming vendor bills for liabilities and Accounts Payable.'
              }
            />
          </div>
        )}

        {/* TAB 3: Accounts Payable Overview */}
        {activeTab === 'payables' && (
          <div className="space-y-4">
            {/* Top KPI Card */}
            <div className="p-4 rounded-lg border bg-card flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div>
                <span className="text-xs text-muted-foreground font-medium block">
                  Total Outstanding Accounts Payable (AP)
                </span>
                <span className="text-2xl font-bold font-mono text-primary">
                  {formatMoneyCents(totalOutstandingApCents)}
                </span>
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={fetchPayables}
                disabled={payablesLoading}
                className="gap-2 text-xs self-start sm:self-auto"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${payablesLoading ? 'animate-spin' : ''}`} /> Refresh Balances
              </Button>
            </div>

            {/* Supplier Balances Grid */}
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
              {supplierBalances.length === 0 ? (
                <div className="col-span-full p-8 text-center text-muted-foreground text-xs border rounded-lg">
                  No suppliers registered yet. Add commercial suppliers in Contacts Directory.
                </div>
              ) : (
                supplierBalances.map(({ supplier, balanceCents }) => (
                  <div key={supplier.id} className="p-4 rounded-lg border bg-card space-y-2">
                    <div className="flex items-start justify-between">
                      <div>
                        <h4 className="font-semibold text-xs text-foreground">{supplier.name}</h4>
                        <span className="font-mono text-[11px] text-muted-foreground">{supplier.code}</span>
                      </div>
                      <Badge variant="outline" className="text-[10px] uppercase">
                        {supplier.type}
                      </Badge>
                    </div>

                    <div className="pt-2 border-t flex items-center justify-between">
                      <span className="text-[11px] text-muted-foreground flex items-center gap-1">
                        <TrendingDown className="h-3.5 w-3.5 text-muted-foreground" /> Balance Due:
                      </span>
                      <span
                        className={`font-mono text-sm font-bold ${
                          balanceCents > 0 ? 'text-destructive' : 'text-muted-foreground'
                        }`}
                      >
                        {formatMoneyCents(balanceCents)}
                      </span>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        )}

        {/* PO Form Modal */}
        <PurchaseOrderFormModal
          open={isPoModalOpen}
          onOpenChange={setIsPoModalOpen}
          suppliers={suppliers}
          warehouses={warehouses}
          products={products}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            fetchPurchaseOrders();
          }}
        />

        {/* Goods Receipt Modal */}
        <GoodsReceiptModal
          open={Boolean(receiptPo)}
          onOpenChange={(open) => !open && setReceiptPo(null)}
          purchaseOrder={receiptPo}
          products={products}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            fetchPurchaseOrders();
          }}
        />

        {/* Supplier Bill Form Modal */}
        <SupplierBillFormModal
          open={isBillModalOpen}
          onOpenChange={setIsBillModalOpen}
          suppliers={suppliers}
          products={products}
          purchaseOrders={purchaseOrders}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            if (activeTab === 'bills') fetchBills();
          }}
        />

        {/* Purchase Order Inspection Modal */}
        <PurchaseOrderDetailModal
          open={Boolean(viewingPo)}
          onOpenChange={(open) => !open && setViewingPo(null)}
          purchaseOrder={viewingPo}
          suppliers={suppliers}
          warehouses={warehouses}
          products={products}
          canApprove={canApprovePo}
          canReceive={canReceiveGoods}
          onOpenReceiveGoods={(po) => setReceiptPo(po)}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            fetchPurchaseOrders();
          }}
        />

        {/* Supplier Bill Inspection Modal */}
        <SupplierBillDetailModal
          open={Boolean(viewingBill)}
          onOpenChange={(open) => !open && setViewingBill(null)}
          bill={viewingBill}
          suppliers={suppliers}
          canPostBill={canPostBill}
          onSuccess={(msg) => {
            setFeedback({ type: 'success', message: msg });
            fetchBills();
          }}
        />
      </div>
    </AppLayout>
  );
}
