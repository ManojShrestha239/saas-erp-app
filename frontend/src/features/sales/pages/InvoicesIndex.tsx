import React, { useState, useEffect, useCallback, useMemo } from 'react';
import type { ColumnDef, PaginationState, SortingState } from '@tanstack/react-table';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { useCan } from '@/features/auth/hooks/useCan';
import { salesApi } from '../api/salesApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import { inventoryApi } from '@/features/inventory/api/inventoryApi';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type {
  SalesInvoice,
  SalesInvoiceStatus,
  SalesItemFormData,
  CreateInvoiceFormData,
  CreditNote,
  CreditNoteStatus,
} from '../types/sales.types';
import { CreditNoteModal } from '../components/CreditNoteModal';
import {
  Plus,
  RefreshCw,
  Search,
  MoreHorizontal,
  CheckCircle,
  Eye,
  Trash2,
  Lock,
  Wallet,
  FileText,
  RotateCcw,
  AlertTriangle,
} from 'lucide-react';

function formatMoneyCents(cents: number, currency = 'USD'): string {
  const amount = cents / 100;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

interface ProductOption {
  id: string;
  sku: string;
  name: string;
  selling_price_cents: number;
}

export default function InvoicesIndex() {
  const canManageSales = useCan('sales.manage');

  // Main navigation tab
  const [activeTab, setActiveTab] = useState<'invoices' | 'credit_notes'>('invoices');

  // Invoices table state
  const [invoices, setInvoices] = useState<SalesInvoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [totalCount, setTotalCount] = useState(0);

  // Credit Notes table state
  const [creditNotes, setCreditNotes] = useState<CreditNote[]>([]);
  const [cnLoading, setCnLoading] = useState(false);
  const [cnTotalCount, setCnTotalCount] = useState(0);
  const [cnSearch, setCnSearch] = useState('');
  const [cnStatusFilter, setCnStatusFilter] = useState<CreditNoteStatus | 'all'>('all');
  const [cnPagination, setCnPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [cnSorting, setCnSorting] = useState<SortingState>([
    { id: 'created_at', desc: true },
  ]);

  // Credit note modal state
  const [isCreditNoteModalOpen, setIsCreditNoteModalOpen] = useState(false);
  const [selectedCreditNote, setSelectedCreditNote] = useState<CreditNote | null>(null);
  const [creditNoteInitialInvoice, setCreditNoteInitialInvoice] = useState<SalesInvoice | null>(null);

  // Reference data
  const [customers, setCustomers] = useState<ContactItem[]>([]);
  const [products, setProducts] = useState<ProductOption[]>([]);

  // Filters & Pagination
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<SalesInvoiceStatus | 'all'>('all');
  const [selectedCustomerFilter, setSelectedCustomerFilter] = useState<string>('all');
  const [customerArBalance, setCustomerArBalance] = useState<number | null>(null);

  const [pagination, setPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [sorting, setSorting] = useState<SortingState>([
    { id: 'created_at', desc: true },
  ]);

  // Dialog states
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isPostConfirmOpen, setIsPostConfirmOpen] = useState(false);
  const [isDeleteConfirmOpen, setIsDeleteConfirmOpen] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState<SalesInvoice | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Create Invoice form state
  const [createFormData, setCreateFormData] = useState<CreateInvoiceFormData>({
    customer_id: '',
    issue_date: new Date().toISOString().split('T')[0] ?? '',
    due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0] ?? '',
    currency: 'USD',
    notes: '',
    items: [],
  });

  // Load Reference Data
  useEffect(() => {
    async function loadRefs() {
      try {
        const [contactsRes, productsRes] = await Promise.all([
          contactsApi.getContacts(),
          inventoryApi.getProducts({ per_page: 100 }),
        ]);
        if (contactsRes.data) {
          setCustomers(contactsRes.data);
        }
        if (productsRes.data) {
          setProducts(productsRes.data);
        }
      } catch {
        // Fallback silently if contacts/products fail to load
      }
    }
    loadRefs();
  }, []);

  const customerMap = useMemo(() => {
    const map = new Map<string, string>();
    for (const c of customers) {
      map.set(c.id, c.name);
    }
    return map;
  }, [customers]);

  const productMap = useMemo(() => {
    const map = new Map<string, ProductOption>();
    for (const p of products) {
      map.set(p.id, p);
    }
    return map;
  }, [products]);

  // Load live AR balance when customer filter is chosen
  useEffect(() => {
    if (selectedCustomerFilter && selectedCustomerFilter !== 'all') {
      salesApi
        .getCustomerReceivableBalance(selectedCustomerFilter)
        .then((res) => {
          setCustomerArBalance(res.data.outstanding_receivable_cents);
        })
        .catch(() => {
          setCustomerArBalance(null);
        });
    } else {
      setCustomerArBalance(null);
    }
  }, [selectedCustomerFilter]);

  // Fetch Invoices
  const fetchInvoices = useCallback(async () => {
    setLoading(true);
    try {
      const response = await salesApi.getInvoices({
        page: pagination.pageIndex + 1,
        per_page: pagination.pageSize,
        search: search.trim() || undefined,
        status: statusFilter === 'all' ? undefined : statusFilter,
        customer_id: selectedCustomerFilter === 'all' ? undefined : selectedCustomerFilter,
      });

      setInvoices(response.data || []);
      if (response.meta && typeof response.meta.total === 'number') {
        setTotalCount(response.meta.total);
      } else {
        setTotalCount(response.data?.length || 0);
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to fetch sales invoices';
      setFeedback({ type: 'error', message });
    } finally {
      setLoading(false);
    }
  }, [pagination.pageIndex, pagination.pageSize, search, statusFilter, selectedCustomerFilter]);

  useEffect(() => {
    fetchInvoices();
  }, [fetchInvoices]);

  // Fetch Credit Notes
  const fetchCreditNotes = useCallback(async () => {
    setCnLoading(true);
    try {
      const response = await salesApi.getCreditNotes({
        page: cnPagination.pageIndex + 1,
        per_page: cnPagination.pageSize,
        search: cnSearch.trim() || undefined,
        status: cnStatusFilter === 'all' ? undefined : cnStatusFilter,
        customer_id: selectedCustomerFilter === 'all' ? undefined : selectedCustomerFilter,
      });

      setCreditNotes(response.data || []);
      if (response.meta && typeof response.meta.total === 'number') {
        setCnTotalCount(response.meta.total);
      } else {
        setCnTotalCount(response.data?.length || 0);
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to fetch credit notes';
      setFeedback({ type: 'error', message });
    } finally {
      setCnLoading(false);
    }
  }, [cnPagination.pageIndex, cnPagination.pageSize, cnSearch, cnStatusFilter, selectedCustomerFilter]);

  useEffect(() => {
    if (activeTab === 'credit_notes') {
      fetchCreditNotes();
    }
  }, [activeTab, fetchCreditNotes]);

  const handlePostCreditNote = useCallback(async (cn: CreditNote) => {
    try {
      await salesApi.postCreditNote(cn.id);
      setFeedback({
        type: 'success',
        message: `Credit Note ${cn.credit_note_number} successfully posted. Accounts receivable balance and inventory updated.`,
      });
      fetchCreditNotes();
      fetchInvoices();
      if (selectedCustomerFilter && selectedCustomerFilter !== 'all') {
        const res = await salesApi.getCustomerReceivableBalance(selectedCustomerFilter);
        setCustomerArBalance(res.data.outstanding_receivable_cents);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to post credit note';
      setFeedback({ type: 'error', message: msg });
    }
  }, [fetchCreditNotes, fetchInvoices, selectedCustomerFilter]);

  const handleDeleteCreditNote = useCallback(async (cn: CreditNote) => {
    try {
      await salesApi.deleteCreditNote(cn.id);
      setFeedback({
        type: 'success',
        message: `Draft credit note ${cn.credit_note_number} has been deleted.`,
      });
      fetchCreditNotes();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to delete credit note';
      setFeedback({ type: 'error', message: msg });
    }
  }, [fetchCreditNotes]);

  // Form Calculations
  const calculatedFormTotals = useMemo(() => {
    let subtotalCents = 0;
    let taxCents = 0;

    for (const item of createFormData.items) {
      const lineSubtotal = Math.round(item.quantity * item.unit_price_cents);
      const taxRate = item.tax_rate_percent || 0;
      const lineTax = Math.round(lineSubtotal * (taxRate / 100));

      subtotalCents += lineSubtotal;
      taxCents += lineTax;
    }

    return {
      subtotalCents,
      taxCents,
      totalCents: subtotalCents + taxCents,
    };
  }, [createFormData.items]);

  // Handlers
  const handleOpenCreate = () => {
    const defaultProduct = products[0];
    setCreateFormData({
      customer_id: customers[0]?.id || '',
      issue_date: new Date().toISOString().split('T')[0] ?? '',
      due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0] ?? '',
      currency: 'USD',
      notes: '',
      items: defaultProduct
        ? [
            {
              product_id: defaultProduct.id,
              quantity: 1,
              unit_price_cents: defaultProduct.selling_price_cents,
              tax_rate_percent: 0,
              description: defaultProduct.name,
            },
          ]
        : [],
    });
    setFormError(null);
    setIsCreateOpen(true);
  };

  const handleAddItem = () => {
    const defaultProduct = products[0];
    const newItem: SalesItemFormData = {
      product_id: defaultProduct ? defaultProduct.id : '',
      quantity: 1,
      unit_price_cents: defaultProduct ? defaultProduct.selling_price_cents : 0,
      tax_rate_percent: 0,
      description: defaultProduct ? defaultProduct.name : '',
    };
    setCreateFormData((prev) => ({
      ...prev,
      items: [...prev.items, newItem],
    }));
  };

  const handleRemoveItem = (index: number) => {
    setCreateFormData((prev) => ({
      ...prev,
      items: prev.items.filter((_, i) => i !== index),
    }));
  };

  const handleItemChange = (index: number, updates: Partial<SalesItemFormData>) => {
    setCreateFormData((prev) => {
      const newItems = [...prev.items];
      const existing = newItems[index];
      if (!existing) return prev;

      if (updates.product_id && updates.product_id !== existing.product_id) {
        const prod = productMap.get(updates.product_id);
        if (prod) {
          updates.unit_price_cents = prod.selling_price_cents;
          updates.description = prod.name;
        }
      }

      newItems[index] = { ...existing, ...updates };
      return { ...prev, items: newItems };
    });
  };

  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!createFormData.customer_id) {
      setFormError('Please select a customer.');
      return;
    }
    if (createFormData.items.length === 0) {
      setFormError('Please add at least one line item.');
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      await salesApi.createInvoice(createFormData);
      setFeedback({ type: 'success', message: 'Sales invoice created successfully in draft mode.' });
      setIsCreateOpen(false);
      fetchInvoices();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to create sales invoice';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenPostConfirm = useCallback((invoice: SalesInvoice) => {
    setSelectedInvoice(invoice);
    setIsPostConfirmOpen(true);
  }, []);

  const handlePostSubmit = async () => {
    if (!selectedInvoice) return;

    setSubmitting(true);
    try {
      await salesApi.postInvoice(selectedInvoice.id);
      setFeedback({
        type: 'success',
        message: `Invoice ${selectedInvoice.invoice_number} successfully posted. Accounts receivable balance updated.`,
      });
      setIsPostConfirmOpen(false);
      fetchInvoices();
      if (selectedCustomerFilter && selectedCustomerFilter !== 'all') {
        const res = await salesApi.getCustomerReceivableBalance(selectedCustomerFilter);
        setCustomerArBalance(res.data.outstanding_receivable_cents);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to post invoice';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenDeleteConfirm = useCallback((invoice: SalesInvoice) => {
    setSelectedInvoice(invoice);
    setIsDeleteConfirmOpen(true);
  }, []);

  const handleDeleteSubmit = async () => {
    if (!selectedInvoice) return;

    setSubmitting(true);
    try {
      await salesApi.deleteInvoice(selectedInvoice.id);
      setFeedback({
        type: 'success',
        message: `Draft invoice ${selectedInvoice.invoice_number} has been deleted.`,
      });
      setIsDeleteConfirmOpen(false);
      fetchInvoices();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to delete invoice';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSubmitting(false);
    }
  };

  const handleViewDetail = useCallback(async (invoice: SalesInvoice) => {
    try {
      const res = await salesApi.getInvoice(invoice.id);
      setSelectedInvoice(res.data);
    } catch {
      setSelectedInvoice(invoice);
    }
    setIsDetailOpen(true);
  }, []);

  // Status Badge Helper
  const renderStatusBadge = (status: SalesInvoiceStatus) => {
    const badgeMap: Record<SalesInvoiceStatus, { label: string; className: string }> = {
      draft: { label: 'Draft', className: 'bg-muted text-muted-foreground' },
      posted: { label: 'Posted', className: 'bg-blue-100 text-blue-800 border-blue-200' },
      partially_paid: { label: 'Partially Paid', className: 'bg-amber-100 text-amber-800 border-amber-200' },
      paid: { label: 'Paid', className: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
      void: { label: 'Void', className: 'bg-red-100 text-red-800 border-red-200' },
    };

    const config = badgeMap[status] || { label: status, className: 'bg-muted text-foreground' };
    return <Badge variant="outline" className={config.className}>{config.label}</Badge>;
  };

  // Columns definition
  const columns = useMemo<ColumnDef<SalesInvoice>[]>(() => [
    {
      accessorKey: 'invoice_number',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Invoice #" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('invoice_number')}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'customer_id',
      header: 'Customer',
      cell: ({ row }) => {
        const customerId = row.original.customer_id;
        const customerName = customerMap.get(customerId) || customerId.substring(0, 8);
        return <span className="font-medium text-foreground text-sm">{customerName}</span>;
      },
      enableSorting: false,
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => renderStatusBadge(row.getValue('status') as SalesInvoiceStatus),
      enableSorting: true,
    },
    {
      accessorKey: 'issue_date',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Issue Date" />,
      cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.getValue('issue_date')}</span>,
      enableSorting: true,
    },
    {
      accessorKey: 'due_date',
      header: 'Due Date',
      cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.original.due_date}</span>,
      enableSorting: false,
    },
    {
      accessorKey: 'total_cents',
      header: ({ column }) => (
        <div className="text-right">
          <DataTableColumnHeader column={column} title="Total" />
        </div>
      ),
      cell: ({ row }) => (
        <div className="text-right">
          <span className="font-mono text-xs font-bold text-foreground tabular-nums">
            {formatMoneyCents(row.getValue('total_cents'), row.original.currency)}
          </span>
        </div>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'amount_due_cents',
      header: ({ column }) => (
        <div className="text-right">
          <DataTableColumnHeader column={column} title="Due" />
        </div>
      ),
      cell: ({ row }) => {
        const dueCents = row.original.amount_due_cents;
        return (
          <div className="text-right">
            <span
              className={`font-mono text-xs font-semibold tabular-nums ${
                dueCents > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'
              }`}
            >
              {formatMoneyCents(dueCents, row.original.currency)}
            </span>
          </div>
        );
      },
      enableSorting: false,
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const inv = row.original;
        const isDraft = inv.status === 'draft';

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label="Invoice actions">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuItem onClick={() => handleViewDetail(inv)}>
                <Eye className="mr-2 h-3.5 w-3.5" />
                <span>View Details</span>
              </DropdownMenuItem>

              <DropdownMenuItem onClick={() => window.open(salesApi.getInvoicePdfUrl(inv.id), '_blank')}>
                <FileText className="mr-2 h-3.5 w-3.5" />
                <span>View / Print PDF</span>
              </DropdownMenuItem>

              {canManageSales && isDraft && (
                <>
                  <DropdownMenuItem onClick={() => handleOpenPostConfirm(inv)} className="text-primary font-semibold">
                    <CheckCircle className="mr-2 h-3.5 w-3.5" />
                    <span>Post Invoice</span>
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => handleOpenDeleteConfirm(inv)}
                    className="text-destructive focus:text-destructive"
                  >
                    <Trash2 className="mr-2 h-3.5 w-3.5" />
                    <span>Delete Draft</span>
                  </DropdownMenuItem>
                </>
              )}

              {canManageSales && !isDraft && inv.status !== 'void' && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => {
                      setSelectedCreditNote(null);
                      setCreditNoteInitialInvoice(inv);
                      setIsCreditNoteModalOpen(true);
                    }}
                    className="text-primary font-medium"
                  >
                    <RotateCcw className="mr-2 h-3.5 w-3.5" />
                    <span>Issue Credit Note</span>
                  </DropdownMenuItem>
                </>
              )}

              {!isDraft && (
                <div className="px-2 py-1.5 text-[11px] text-muted-foreground flex items-center gap-1.5 border-t border-border mt-1">
                  <Lock className="h-3 w-3" />
                  <span>Immutable (Posted)</span>
                </div>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ], [canManageSales, customerMap, handleOpenDeleteConfirm, handleOpenPostConfirm, handleViewDetail]);

  // Credit Notes columns definition
  const cnColumns = useMemo<ColumnDef<CreditNote>[]>(() => [
    {
      accessorKey: 'credit_note_number',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Credit Note #" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('credit_note_number')}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'customer_id',
      header: 'Customer',
      cell: ({ row }) => {
        const customerId = row.original.customer_id;
        const customerName = customerMap.get(customerId) || customerId.substring(0, 8);
        return <span className="font-medium text-foreground text-sm">{customerName}</span>;
      },
      enableSorting: false,
    },
    {
      accessorKey: 'sales_invoice_id',
      header: 'Invoice Ref',
      cell: ({ row }) => {
        const invId = row.original.sales_invoice_id;
        if (!invId) return <span className="text-xs text-muted-foreground">—</span>;
        const linkedInv = invoices.find((i) => i.id === invId);
        return (
          <span className="font-mono text-xs text-muted-foreground">
            {linkedInv ? linkedInv.invoice_number : invId.substring(0, 8) + '...'}
          </span>
        );
      },
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => {
        const status = row.getValue('status') as CreditNoteStatus;
        const badgeMap: Record<CreditNoteStatus, { label: string; className: string }> = {
          draft: { label: 'Draft', className: 'bg-muted text-muted-foreground' },
          posted: { label: 'Posted', className: 'bg-blue-100 text-blue-800 border-blue-200' },
          void: { label: 'Void', className: 'bg-red-100 text-red-800 border-red-200' },
        };
        const config = badgeMap[status] || { label: status, className: 'bg-muted text-foreground' };
        return <Badge variant="outline" className={config.className}>{config.label}</Badge>;
      },
      enableSorting: true,
    },
    {
      accessorKey: 'issue_date',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Issue Date" />,
      cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.getValue('issue_date')}</span>,
      enableSorting: true,
    },
    {
      accessorKey: 'restock',
      header: 'Stock Effect',
      cell: ({ row }) => {
        const restock = row.original.restock;
        return (
          <Badge
            variant="outline"
            className={
              restock
                ? 'bg-emerald-50 text-emerald-700 border-emerald-200 text-[10px]'
                : 'bg-muted text-muted-foreground text-[10px]'
            }
          >
            {restock ? 'Restocked' : 'No Restock'}
          </Badge>
        );
      },
    },
    {
      accessorKey: 'total_cents',
      header: ({ column }) => (
        <div className="text-right">
          <DataTableColumnHeader column={column} title="Total Credit" />
        </div>
      ),
      cell: ({ row }) => (
        <div className="text-right">
          <span className="font-mono text-xs font-bold text-foreground tabular-nums">
            {formatMoneyCents(row.getValue('total_cents'), row.original.currency)}
          </span>
        </div>
      ),
      enableSorting: true,
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const cn = row.original;
        const isDraft = cn.status === 'draft';

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label="Credit note actions">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuItem
                onClick={() => {
                  setSelectedCreditNote(cn);
                  setCreditNoteInitialInvoice(null);
                  setIsCreditNoteModalOpen(true);
                }}
              >
                <Eye className="mr-2 h-3.5 w-3.5" />
                <span>View Details</span>
              </DropdownMenuItem>

              {canManageSales && isDraft && (
                <>
                  <DropdownMenuItem
                    onClick={() => handlePostCreditNote(cn)}
                    className="text-primary font-semibold"
                  >
                    <CheckCircle className="mr-2 h-3.5 w-3.5" />
                    <span>Post Credit Note</span>
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => handleDeleteCreditNote(cn)}
                    className="text-destructive focus:text-destructive"
                  >
                    <Trash2 className="mr-2 h-3.5 w-3.5" />
                    <span>Delete Draft</span>
                  </DropdownMenuItem>
                </>
              )}

              {!isDraft && (
                <div className="px-2 py-1.5 text-[11px] text-muted-foreground flex items-center gap-1.5 border-t border-border mt-1">
                  <Lock className="h-3 w-3" />
                  <span>Immutable (Posted)</span>
                </div>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ], [canManageSales, customerMap, invoices, handleDeleteCreditNote, handlePostCreditNote]);

  return (
    <AppLayout
      title="Invoices"
      activeNav="invoices"
      breadcrumbs={[
        { label: 'Dashboard', href: '/dashboard' },
        { label: 'Sales' },
        { label: 'Invoices' },
      ]}
    >
      <div className="space-y-6">
        {/* Top Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground">Sales Invoices</h1>
            <p className="text-sm text-muted-foreground">
              Manage accounts receivable, issue invoices to customers, and track payment dues.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {canManageSales && activeTab === 'invoices' && (
              <Button
                onClick={handleOpenCreate}
                className="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring font-medium shadow-xs"
              >
                <Plus className="h-4 w-4 mr-2" />
                New Invoice
              </Button>
            )}
            {canManageSales && activeTab === 'credit_notes' && (
              <Button
                onClick={() => {
                  setSelectedCreditNote(null);
                  setCreditNoteInitialInvoice(null);
                  setIsCreditNoteModalOpen(true);
                }}
                className="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring font-medium shadow-xs"
              >
                <Plus className="h-4 w-4 mr-2" />
                New Credit Note
              </Button>
            )}
          </div>
        </div>

        {/* View Tabs */}
        <div className="flex items-center gap-1 border-b pb-px">
          {[
            { id: 'invoices', label: 'Sales Invoices', icon: FileText, count: totalCount },
            { id: 'credit_notes', label: 'Credit Notes', icon: RotateCcw, count: cnTotalCount },
          ].map((tab) => {
            const Icon = tab.icon;
            const isActive = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => setActiveTab(tab.id as 'invoices' | 'credit_notes')}
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

        {/* Global Feedback Alert */}
        {feedback && (
          <Alert
            className={
              feedback.type === 'success'
                ? 'border-emerald-500 bg-emerald-50 text-emerald-900'
                : 'border-destructive bg-destructive/10 text-destructive'
            }
          >
            <AlertDescription className="flex items-center justify-between">
              <span>{feedback.message}</span>
              <button
                type="button"
                onClick={() => setFeedback(null)}
                className="text-xs font-semibold underline ml-4 hover:opacity-80"
              >
                Dismiss
              </button>
            </AlertDescription>
          </Alert>
        )}

        {/* Accounts Receivable (AR) Summary Widget */}
        {selectedCustomerFilter !== 'all' && customerArBalance !== null && (
          <div className="p-4 rounded-xl border border-primary/20 bg-primary/5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-primary/20 flex items-center justify-center text-primary">
                <Wallet className="h-5 w-5" />
              </div>
              <div>
                <span className="text-xs text-muted-foreground font-medium uppercase tracking-wider block">
                  Customer Outstanding AR Balance
                </span>
                <span className="text-sm font-semibold text-foreground">
                  {customerMap.get(selectedCustomerFilter)}
                </span>
              </div>
            </div>
            <div className="text-left sm:text-right">
              <span className="text-xs text-muted-foreground block">Total Amount Due</span>
              <span
                className={`text-xl font-bold font-mono tabular-nums ${
                  customerArBalance > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'
                }`}
              >
                {formatMoneyCents(customerArBalance)}
              </span>
            </div>
          </div>
        )}

        {/* TAB 1: Invoices View */}
        {activeTab === 'invoices' && (
          <div className="space-y-4">
            {/* Filters and Search Bar */}
            <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between bg-card p-3 rounded-lg border border-border">
              <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
                {(
                  [
                    { label: 'All Invoices', value: 'all' },
                    { label: 'Draft', value: 'draft' },
                    { label: 'Posted', value: 'posted' },
                    { label: 'Partially Paid', value: 'partially_paid' },
                    { label: 'Paid', value: 'paid' },
                    { label: 'Void', value: 'void' },
                  ] as const
                ).map((tab) => (
                  <Button
                    key={tab.value}
                    variant={statusFilter === tab.value ? 'primary' : 'ghost'}
                    size="sm"
                    className="text-xs h-8"
                    onClick={() => {
                      setStatusFilter(tab.value);
                      setPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                  >
                    {tab.label}
                  </Button>
                ))}
              </div>

              <div className="flex items-center gap-2.5">
                {/* Customer Filter */}
                <select
                  aria-label="Filter by customer"
                  className="h-9 px-2.5 rounded-md border border-border text-xs bg-background text-foreground max-w-[160px]"
                  value={selectedCustomerFilter}
                  onChange={(e) => {
                    setSelectedCustomerFilter(e.target.value);
                    setPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="all">All Customers</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>

                <div className="relative w-full sm:w-56">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search invoice #..."
                    value={search}
                    onChange={(e) => {
                      setSearch(e.target.value);
                      setPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchInvoices}
                  disabled={loading}
                  className="h-9 w-9 p-0"
                  title="Refresh"
                >
                  <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            {/* Data Table */}
            <DataTable
              columns={columns}
              data={invoices}
              isLoading={loading}
              serverSide={{
                totalRows: totalCount,
                pagination,
                onPaginationChange: setPagination,
                sorting,
                onSortingChange: setSorting,
              }}
              emptyTitle="No sales invoices found"
              emptyDescription={
                search || statusFilter !== 'all' || selectedCustomerFilter !== 'all'
                  ? 'Try modifying your filter or search keywords.'
                  : 'Create a new invoice or convert an accepted quotation.'
              }
            />
          </div>
        )}

        {/* TAB 2: Credit Notes View */}
        {activeTab === 'credit_notes' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between bg-card p-3 rounded-lg border border-border">
              <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
                {(
                  [
                    { label: 'All Statuses', value: 'all' },
                    { label: 'Draft', value: 'draft' },
                    { label: 'Posted', value: 'posted' },
                    { label: 'Void', value: 'void' },
                  ] as const
                ).map((tab) => (
                  <Button
                    key={tab.value}
                    variant={cnStatusFilter === tab.value ? 'primary' : 'ghost'}
                    size="sm"
                    className="text-xs h-8"
                    onClick={() => {
                      setCnStatusFilter(tab.value);
                      setCnPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                  >
                    {tab.label}
                  </Button>
                ))}
              </div>

              <div className="flex items-center gap-2.5">
                <select
                  aria-label="Filter credit notes by customer"
                  className="h-9 px-2.5 rounded-md border border-border text-xs bg-background text-foreground max-w-[160px]"
                  value={selectedCustomerFilter}
                  onChange={(e) => {
                    setSelectedCustomerFilter(e.target.value);
                    setCnPagination((p) => ({ ...p, pageIndex: 0 }));
                  }}
                >
                  <option value="all">All Customers</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>

                <div className="relative w-full sm:w-56">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search credit note #..."
                    value={cnSearch}
                    onChange={(e) => {
                      setCnSearch(e.target.value);
                      setCnPagination((p) => ({ ...p, pageIndex: 0 }));
                    }}
                    className="pl-8 h-9 text-xs"
                  />
                </div>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={fetchCreditNotes}
                  disabled={cnLoading}
                  className="h-9 w-9 p-0"
                  title="Refresh Credit Notes"
                >
                  <RefreshCw className={`h-4 w-4 ${cnLoading ? 'animate-spin' : ''}`} />
                </Button>
              </div>
            </div>

            <DataTable
              columns={cnColumns}
              data={creditNotes}
              isLoading={cnLoading}
              serverSide={{
                totalRows: cnTotalCount,
                pagination: cnPagination,
                onPaginationChange: setCnPagination,
                sorting: cnSorting,
                onSortingChange: setCnSorting,
              }}
              emptyTitle="No credit notes found"
              emptyDescription={
                cnSearch || cnStatusFilter !== 'all' || selectedCustomerFilter !== 'all'
                  ? 'Try modifying your filter or search keywords.'
                  : 'Issue credit notes for customer returns, billing adjustments, or discounts.'
              }
            />
          </div>
        )}

        {/* Create Invoice Modal */}
        <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
          <DialogHeader>
            <DialogTitle>New Sales Invoice</DialogTitle>
            <DialogDescription>
              Create a direct sales invoice. Items will default to draft status and require posting before payment.
            </DialogDescription>
          </DialogHeader>

          {formError && (
            <Alert className="border-destructive bg-destructive/10 text-destructive mb-4">
              <AlertDescription>{formError}</AlertDescription>
            </Alert>
          )}

          <form onSubmit={handleCreateSubmit} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <Label htmlFor="invoice_customer_id" className="text-xs font-semibold">
                  Customer <span className="text-destructive">*</span>
                </Label>
                <select
                  id="invoice_customer_id"
                  className="mt-1 w-full h-10 px-3 rounded-md border border-border bg-background text-foreground text-sm"
                  value={createFormData.customer_id}
                  onChange={(e) => setCreateFormData({ ...createFormData, customer_id: e.target.value })}
                  required
                >
                  <option value="">Select Customer</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} {c.tax_id ? `(${c.tax_id})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <Label htmlFor="invoice_currency" className="text-xs font-semibold">
                  Currency
                </Label>
                <Input
                  id="invoice_currency"
                  className="mt-1 h-10 text-sm"
                  value={createFormData.currency}
                  onChange={(e) => setCreateFormData({ ...createFormData, currency: e.target.value.toUpperCase() })}
                  maxLength={3}
                  required
                />
              </div>

              <div>
                <Label htmlFor="invoice_issue_date" className="text-xs font-semibold">
                  Issue Date <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="invoice_issue_date"
                  type="date"
                  className="mt-1 h-10 text-sm"
                  value={createFormData.issue_date}
                  onChange={(e) => setCreateFormData({ ...createFormData, issue_date: e.target.value })}
                  required
                />
              </div>

              <div>
                <Label htmlFor="invoice_due_date" className="text-xs font-semibold">
                  Due Date <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="invoice_due_date"
                  type="date"
                  className="mt-1 h-10 text-sm"
                  value={createFormData.due_date}
                  onChange={(e) => setCreateFormData({ ...createFormData, due_date: e.target.value })}
                  required
                />
              </div>
            </div>

            <div>
              <Label htmlFor="invoice_notes" className="text-xs font-semibold">
                Notes / Terms
              </Label>
              <textarea
                id="invoice_notes"
                className="mt-1 w-full p-2.5 rounded-md border border-border bg-background text-foreground text-sm"
                rows={2}
                value={createFormData.notes || ''}
                onChange={(e) => setCreateFormData({ ...createFormData, notes: e.target.value })}
                placeholder="Payment instructions, bank wire info, or invoice notes..."
              />
            </div>

            {/* Line items section */}
            <div className="border-t border-border pt-4">
              <div className="flex items-center justify-between mb-2">
                <h4 className="text-sm font-semibold text-foreground">Line Items</h4>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleAddItem}
                  className="h-8 text-xs"
                >
                  <Plus className="h-3.5 w-3.5 mr-1" />
                  Add Line
                </Button>
              </div>

              <div className="space-y-3 max-h-60 overflow-y-auto pr-1">
                {createFormData.items.map((item, index) => {
                  const lineSubtotal = (item.quantity * item.unit_price_cents) / 100;
                  return (
                    <div
                      key={index}
                      className="p-3 rounded-lg border border-border bg-muted/30 grid grid-cols-12 gap-2 items-center"
                    >
                      <div className="col-span-12 sm:col-span-4">
                        <Label className="text-[11px] text-muted-foreground">Product</Label>
                        <select
                          className="w-full h-8 px-2 rounded-md border border-border bg-background text-xs"
                          value={item.product_id}
                          onChange={(e) => handleItemChange(index, { product_id: e.target.value })}
                          required
                        >
                          <option value="">Select Product</option>
                          {products.map((p) => (
                            <option key={p.id} value={p.id}>
                              {p.name} ({p.sku})
                            </option>
                          ))}
                        </select>
                      </div>

                      <div className="col-span-4 sm:col-span-2">
                        <Label className="text-[11px] text-muted-foreground">Qty</Label>
                        <Input
                          type="number"
                          step="any"
                          min="0.0001"
                          className="h-8 text-xs tabular-nums"
                          value={item.quantity}
                          onChange={(e) => handleItemChange(index, { quantity: parseFloat(e.target.value) || 0 })}
                          required
                        />
                      </div>

                      <div className="col-span-4 sm:col-span-2">
                        <Label className="text-[11px] text-muted-foreground">Unit Price ($)</Label>
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          className="h-8 text-xs tabular-nums"
                          value={(item.unit_price_cents / 100).toFixed(2)}
                          onChange={(e) =>
                            handleItemChange(index, {
                              unit_price_cents: Math.round((parseFloat(e.target.value) || 0) * 100),
                            })
                          }
                          required
                        />
                      </div>

                      <div className="col-span-3 sm:col-span-2">
                        <Label className="text-[11px] text-muted-foreground">Tax %</Label>
                        <Input
                          type="number"
                          step="0.5"
                          min="0"
                          max="100"
                          className="h-8 text-xs tabular-nums"
                          value={item.tax_rate_percent ?? 0}
                          onChange={(e) =>
                            handleItemChange(index, { tax_rate_percent: parseFloat(e.target.value) || 0 })
                          }
                        />
                      </div>

                      <div className="col-span-1 sm:col-span-2 flex items-center justify-between sm:justify-end gap-2 pt-3 sm:pt-0">
                        <span className="text-xs font-mono font-semibold tabular-nums">
                          ${lineSubtotal.toFixed(2)}
                        </span>
                        {createFormData.items.length > 1 && (
                          <button
                            type="button"
                            onClick={() => handleRemoveItem(index)}
                            className="p-1 rounded text-destructive hover:bg-destructive/10"
                            title="Remove Line"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>

              {/* Totals */}
              <div className="mt-4 p-3 rounded-lg bg-card border border-border flex flex-col items-end space-y-1">
                <div className="text-xs text-muted-foreground flex justify-between w-48">
                  <span>Subtotal:</span>
                  <span className="font-mono tabular-nums">
                    {formatMoneyCents(calculatedFormTotals.subtotalCents, createFormData.currency)}
                  </span>
                </div>
                <div className="text-xs text-muted-foreground flex justify-between w-48">
                  <span>Tax:</span>
                  <span className="font-mono tabular-nums">
                    {formatMoneyCents(calculatedFormTotals.taxCents, createFormData.currency)}
                  </span>
                </div>
                <div className="text-sm font-bold text-foreground flex justify-between w-48 border-t border-border pt-1">
                  <span>Grand Total:</span>
                  <span className="font-mono text-primary tabular-nums">
                    {formatMoneyCents(calculatedFormTotals.totalCents, createFormData.currency)}
                  </span>
                </div>
              </div>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsCreateOpen(false)}
                disabled={submitting}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={submitting}
                className="bg-primary text-primary-foreground hover:bg-primary/90"
              >
                {submitting ? 'Creating...' : 'Save Draft Invoice'}
              </Button>
            </DialogFooter>
          </form>
        </Dialog>

        {/* Post Confirmation Modal */}
        <Dialog open={isPostConfirmOpen} onOpenChange={setIsPostConfirmOpen}>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Lock className="h-5 w-5 text-primary" />
              Post Sales Invoice
            </DialogTitle>
            <DialogDescription>
              Are you sure you want to post invoice {selectedInvoice?.invoice_number}?
            </DialogDescription>
          </DialogHeader>

          <div className="p-4 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-900 dark:text-amber-200 text-xs space-y-2">
            <div className="flex items-center gap-1.5 font-semibold">
              <AlertTriangle className="h-4 w-4" />
              <span>Important Immutability Notice</span>
            </div>
            <p>
              Once posted, this invoice becomes <strong>permanent and immutable</strong>. Line items, quantities, and prices cannot be modified or deleted. The invoice amount will immediately be committed to the customer's accounts receivable ledger.
            </p>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setIsPostConfirmOpen(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handlePostSubmit}
              disabled={submitting}
              className="bg-primary text-primary-foreground hover:bg-primary/90"
            >
              {submitting ? 'Posting...' : 'Confirm & Post'}
            </Button>
          </DialogFooter>
        </Dialog>

        {/* Delete Draft Confirmation Modal */}
        <Dialog open={isDeleteConfirmOpen} onOpenChange={setIsDeleteConfirmOpen}>
          <DialogHeader>
            <DialogTitle className="text-destructive">Delete Draft Invoice</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete draft invoice {selectedInvoice?.invoice_number}? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setIsDeleteConfirmOpen(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleDeleteSubmit}
              disabled={submitting}
            >
              {submitting ? 'Deleting...' : 'Delete Draft'}
            </Button>
          </DialogFooter>
        </Dialog>

        {/* View Details Modal */}
        <Dialog open={isDetailOpen} onOpenChange={setIsDetailOpen}>
          <DialogHeader>
            <DialogTitle>Invoice Details</DialogTitle>
            <DialogDescription>
              {selectedInvoice?.invoice_number} - {selectedInvoice?.status_label}
            </DialogDescription>
          </DialogHeader>

          {selectedInvoice && (
            <div className="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
              <div className="grid grid-cols-2 gap-4 text-sm bg-muted/20 p-4 rounded-lg border border-border">
                <div>
                  <span className="text-xs text-muted-foreground block">Customer</span>
                  <span className="font-semibold text-foreground">
                    {customerMap.get(selectedInvoice.customer_id) || selectedInvoice.customer_id}
                  </span>
                </div>
                <div>
                  <span className="text-xs text-muted-foreground block">Status</span>
                  <div>{renderStatusBadge(selectedInvoice.status)}</div>
                </div>
                <div>
                  <span className="text-xs text-muted-foreground block">Issue Date</span>
                  <span className="font-medium">{selectedInvoice.issue_date}</span>
                </div>
                <div>
                  <span className="text-xs text-muted-foreground block">Due Date</span>
                  <span className="font-medium">{selectedInvoice.due_date}</span>
                </div>
              </div>

              {selectedInvoice.notes && (
                <div className="text-xs text-muted-foreground bg-card p-3 rounded border border-border">
                  <span className="font-semibold block mb-1">Notes:</span>
                  {selectedInvoice.notes}
                </div>
              )}

              {/* Items Table */}
              <div>
                <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                  Line Items
                </h4>
                <div className="border border-border rounded-lg overflow-hidden">
                  <table className="w-full text-xs text-left">
                    <thead className="bg-muted text-muted-foreground uppercase text-[10px]">
                      <tr>
                        <th className="p-2.5">Product</th>
                        <th className="p-2.5 text-right">Qty</th>
                        <th className="p-2.5 text-right">Unit Price</th>
                        <th className="p-2.5 text-right">Tax %</th>
                        <th className="p-2.5 text-right">Total</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {selectedInvoice.items && selectedInvoice.items.length > 0 ? (
                        selectedInvoice.items.map((item) => (
                          <tr key={item.id} className="hover:bg-muted/30">
                            <td className="p-2.5">
                              <span className="font-medium text-foreground block">
                                {productMap.get(item.product_id)?.name || item.product_id}
                              </span>
                              {item.description && (
                                <span className="text-muted-foreground text-[11px] block">{item.description}</span>
                              )}
                            </td>
                            <td className="p-2.5 text-right font-mono tabular-nums">{item.quantity}</td>
                            <td className="p-2.5 text-right font-mono tabular-nums">
                              {formatMoneyCents(item.unit_price_cents, selectedInvoice.currency)}
                            </td>
                            <td className="p-2.5 text-right font-mono tabular-nums">{item.tax_rate_percent}%</td>
                            <td className="p-2.5 text-right font-mono font-semibold tabular-nums">
                              {formatMoneyCents(item.total_cents, selectedInvoice.currency)}
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td colSpan={5} className="p-4 text-center text-muted-foreground">
                            No items details loaded.
                          </td>
                        </tr>
                      )}
                    </tbody>
                    <tfoot className="bg-muted/50 border-t border-border font-semibold">
                      <tr>
                        <td colSpan={4} className="p-2 text-right">Subtotal:</td>
                        <td className="p-2 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedInvoice.subtotal_cents, selectedInvoice.currency)}
                        </td>
                      </tr>
                      <tr>
                        <td colSpan={4} className="p-2 text-right">Tax:</td>
                        <td className="p-2 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedInvoice.tax_cents, selectedInvoice.currency)}
                        </td>
                      </tr>
                      <tr className="text-sm font-bold text-foreground">
                        <td colSpan={4} className="p-2 text-right">Total Amount:</td>
                        <td className="p-2 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedInvoice.total_cents, selectedInvoice.currency)}
                        </td>
                      </tr>
                      <tr className="text-xs font-semibold text-emerald-700">
                        <td colSpan={4} className="p-1.5 text-right">Amount Paid:</td>
                        <td className="p-1.5 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedInvoice.amount_paid_cents, selectedInvoice.currency)}
                        </td>
                      </tr>
                      <tr className="text-sm font-bold text-primary border-t border-border">
                        <td colSpan={4} className="p-2 text-right">Amount Due:</td>
                        <td className="p-2 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedInvoice.amount_due_cents, selectedInvoice.currency)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              {/* Status and lock footer */}
              {selectedInvoice.status !== 'draft' && (
                <div className="p-3 rounded-lg bg-muted/40 border border-border flex items-center gap-2 text-xs text-muted-foreground">
                  <Lock className="h-4 w-4 text-primary" />
                  <span>
                    Posted on{' '}
                    {selectedInvoice.posted_at
                      ? new Date(selectedInvoice.posted_at).toLocaleDateString()
                      : 'N/A'}{' '}
                    - All modifications are locked.
                  </span>
                </div>
              )}
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDetailOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </Dialog>

        {/* Credit Note Modal */}
        <CreditNoteModal
          open={isCreditNoteModalOpen}
          onOpenChange={setIsCreditNoteModalOpen}
          creditNote={selectedCreditNote}
          initialInvoice={creditNoteInitialInvoice}
          onSuccess={() => {
            fetchCreditNotes();
            fetchInvoices();
            if (selectedCustomerFilter && selectedCustomerFilter !== 'all') {
              salesApi.getCustomerReceivableBalance(selectedCustomerFilter).then((res) => {
                setCustomerArBalance(res.data.outstanding_receivable_cents);
              }).catch(() => {});
            }
          }}
        />
      </div>
    </AppLayout>
  );
}
