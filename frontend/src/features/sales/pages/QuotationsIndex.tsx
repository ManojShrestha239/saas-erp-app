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
  SalesQuotation,
  SalesQuotationStatus,
  SalesItemFormData,
  CreateQuotationFormData,
} from '../types/sales.types';
import {
  Plus,
  RefreshCw,
  Search,
  MoreHorizontal,
  Send,
  CheckCircle,
  XCircle,
  ArrowRightCircle,
  Eye,
  FileText,
  Trash2,
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

export default function QuotationsIndex() {
  const canManageSales = useCan('sales.manage');

  // Quotation table state
  const [quotations, setQuotations] = useState<SalesQuotation[]>([]);
  const [loading, setLoading] = useState(true);
  const [totalCount, setTotalCount] = useState(0);

  // Reference data
  const [customers, setCustomers] = useState<ContactItem[]>([]);
  const [products, setProducts] = useState<ProductOption[]>([]);

  // Filters & Pagination
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<SalesQuotationStatus | 'all'>('all');
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
  const [isConvertOpen, setIsConvertOpen] = useState(false);
  const [selectedQuotation, setSelectedQuotation] = useState<SalesQuotation | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Conversion form state
  const [convertDueDate, setConvertDueDate] = useState<string>('');
  const [convertAutoPost, setConvertAutoPost] = useState<boolean>(false);

  // Create quotation form state
  const [createFormData, setCreateFormData] = useState<CreateQuotationFormData>({
    customer_id: '',
    issue_date: new Date().toISOString().split('T')[0] ?? '',
    expiry_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0] ?? '',
    currency: 'USD',
    notes: '',
    items: [],
  });

  // Load Reference Data (Customers & Products)
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

  // Fetch Quotations
  const fetchQuotations = useCallback(async () => {
    setLoading(true);
    try {
      const response = await salesApi.getQuotations({
        page: pagination.pageIndex + 1,
        per_page: pagination.pageSize,
        search: search.trim() || undefined,
        status: statusFilter === 'all' ? undefined : statusFilter,
      });

      setQuotations(response.data || []);
      if (response.meta && typeof response.meta.total === 'number') {
        setTotalCount(response.meta.total);
      } else {
        setTotalCount(response.data?.length || 0);
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to fetch sales quotations';
      setFeedback({ type: 'error', message });
    } finally {
      setLoading(false);
    }
  }, [pagination.pageIndex, pagination.pageSize, search, statusFilter]);

  useEffect(() => {
    fetchQuotations();
  }, [fetchQuotations]);

  // Form Item Calculations
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
      expiry_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0] ?? '',
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

      // If product_id changed, auto-update price & description
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
      await salesApi.createQuotation(createFormData);
      setFeedback({ type: 'success', message: 'Sales quotation created successfully.' });
      setIsCreateOpen(false);
      fetchQuotations();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to create quotation';
      setFormError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handleSend = useCallback(async (quotation: SalesQuotation) => {
    setSubmitting(true);
    try {
      await salesApi.sendQuotation(quotation.id);
      setFeedback({ type: 'success', message: `Quotation ${quotation.quotation_number} marked as sent.` });
      fetchQuotations();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to send quotation';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSubmitting(false);
    }
  }, [fetchQuotations]);

  const handleAccept = useCallback(async (quotation: SalesQuotation) => {
    setSubmitting(true);
    try {
      await salesApi.acceptQuotation(quotation.id);
      setFeedback({ type: 'success', message: `Quotation ${quotation.quotation_number} accepted.` });
      fetchQuotations();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to accept quotation';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSubmitting(false);
    }
  }, [fetchQuotations]);

  const handleReject = useCallback(async (quotation: SalesQuotation) => {
    setSubmitting(true);
    try {
      await salesApi.rejectQuotation(quotation.id);
      setFeedback({ type: 'success', message: `Quotation ${quotation.quotation_number} rejected.` });
      fetchQuotations();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to reject quotation';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSubmitting(false);
    }
  }, [fetchQuotations]);

  const handleOpenConvert = useCallback((quotation: SalesQuotation) => {
    setSelectedQuotation(quotation);
    setConvertDueDate(new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0] ?? '');
    setConvertAutoPost(false);
    setIsConvertOpen(true);
  }, []);

  const handleConvertSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedQuotation) return;

    setSubmitting(true);
    try {
      const response = await salesApi.convertQuotation(selectedQuotation.id, {
        due_date: convertDueDate || undefined,
        post_immediately: convertAutoPost,
      });
      setFeedback({
        type: 'success',
        message: `Quotation ${selectedQuotation.quotation_number} successfully converted to Invoice ${response.data.invoice_number}.`,
      });
      setIsConvertOpen(false);
      fetchQuotations();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to convert quotation';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSubmitting(false);
    }
  };

  const handleViewDetail = useCallback(async (quotation: SalesQuotation) => {
    try {
      const res = await salesApi.getQuotation(quotation.id);
      setSelectedQuotation(res.data);
    } catch {
      setSelectedQuotation(quotation);
    }
    setIsDetailOpen(true);
  }, []);

  // Status Badge Helper
  const renderStatusBadge = (status: SalesQuotationStatus) => {
    const badgeMap: Record<SalesQuotationStatus, { label: string; className: string }> = {
      draft: { label: 'Draft', className: 'bg-muted text-muted-foreground' },
      sent: { label: 'Sent', className: 'bg-blue-100 text-blue-800 border-blue-200' },
      accepted: { label: 'Accepted', className: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
      rejected: { label: 'Rejected', className: 'bg-red-100 text-red-800 border-red-200' },
      expired: { label: 'Expired', className: 'bg-amber-100 text-amber-800 border-amber-200' },
    };

    const config = badgeMap[status] || { label: status, className: 'bg-muted text-foreground' };
    return <Badge variant="outline" className={config.className}>{config.label}</Badge>;
  };

  // Columns definition
  const columns = useMemo<ColumnDef<SalesQuotation>[]>(() => [
    {
      accessorKey: 'quotation_number',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Quotation #" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('quotation_number')}
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
      cell: ({ row }) => renderStatusBadge(row.getValue('status') as SalesQuotationStatus),
      enableSorting: true,
    },
    {
      accessorKey: 'issue_date',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Issue Date" />,
      cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.getValue('issue_date')}</span>,
      enableSorting: true,
    },
    {
      accessorKey: 'expiry_date',
      header: 'Expiry Date',
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {row.original.expiry_date || '—'}
        </span>
      ),
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
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const q = row.original;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label="Quotation actions">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuItem onClick={() => handleViewDetail(q)}>
                <Eye className="mr-2 h-3.5 w-3.5" />
                <span>View Details</span>
              </DropdownMenuItem>

              {canManageSales && q.status === 'draft' && (
                <DropdownMenuItem onClick={() => handleSend(q)}>
                  <Send className="mr-2 h-3.5 w-3.5" />
                  <span>Send Quotation</span>
                </DropdownMenuItem>
              )}

              {canManageSales && q.status === 'sent' && (
                <>
                  <DropdownMenuItem onClick={() => handleAccept(q)} className="text-emerald-700">
                    <CheckCircle className="mr-2 h-3.5 w-3.5" />
                    <span>Mark Accepted</span>
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => handleReject(q)} className="text-destructive">
                    <XCircle className="mr-2 h-3.5 w-3.5" />
                    <span>Mark Rejected</span>
                  </DropdownMenuItem>
                </>
              )}

              {canManageSales && q.status === 'accepted' && !q.converted_to_invoice_id && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={() => handleOpenConvert(q)} className="font-semibold text-primary">
                    <ArrowRightCircle className="mr-2 h-3.5 w-3.5" />
                    <span>Convert to Invoice</span>
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ], [canManageSales, customerMap, handleAccept, handleOpenConvert, handleReject, handleSend, handleViewDetail]);

  return (
    <AppLayout
      title="Quotations"
      activeNav="sales"
      breadcrumbs={[
        { label: 'Dashboard', href: '/dashboard' },
        { label: 'Sales' },
        { label: 'Quotations' },
      ]}
    >
      <div className="space-y-6">
        {/* Top Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground">Sales Quotations</h1>
            <p className="text-sm text-muted-foreground">
              Create, send, and convert customer price estimates to official invoices.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {canManageSales && (
              <Button
                onClick={handleOpenCreate}
                className="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring font-medium shadow-xs"
              >
                <Plus className="h-4 w-4 mr-2" />
                New Quotation
              </Button>
            )}
          </div>
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

        {/* Filters and Search Bar */}
        <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between bg-card p-3 rounded-lg border border-border">
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
            {(
              [
                { label: 'All Quotations', value: 'all' },
                { label: 'Draft', value: 'draft' },
                { label: 'Sent', value: 'sent' },
                { label: 'Accepted', value: 'accepted' },
                { label: 'Rejected', value: 'rejected' },
                { label: 'Expired', value: 'expired' },
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
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search quote # or customer..."
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
              onClick={fetchQuotations}
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
          data={quotations}
          isLoading={loading}
          serverSide={{
            totalRows: totalCount,
            pagination,
            onPaginationChange: setPagination,
            sorting,
            onSortingChange: setSorting,
          }}
          emptyTitle="No sales quotations found"
          emptyDescription={
            search || statusFilter !== 'all'
              ? 'Try modifying your filter or search keywords.'
              : 'Create your first commercial quotation to send to prospects.'
          }
        />

        {/* Create Quotation Modal */}
        <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
          <DialogHeader>
            <DialogTitle>New Sales Quotation</DialogTitle>
            <DialogDescription>
              Fill in customer details, dates, and line items. Prices are calculated automatically.
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
                <Label htmlFor="customer_id" className="text-xs font-semibold">
                  Customer <span className="text-destructive">*</span>
                </Label>
                <select
                  id="customer_id"
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
                <Label htmlFor="currency" className="text-xs font-semibold">
                  Currency
                </Label>
                <Input
                  id="currency"
                  className="mt-1 h-10 text-sm"
                  value={createFormData.currency}
                  onChange={(e) => setCreateFormData({ ...createFormData, currency: e.target.value.toUpperCase() })}
                  maxLength={3}
                  required
                />
              </div>

              <div>
                <Label htmlFor="issue_date" className="text-xs font-semibold">
                  Issue Date <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="issue_date"
                  type="date"
                  className="mt-1 h-10 text-sm"
                  value={createFormData.issue_date}
                  onChange={(e) => setCreateFormData({ ...createFormData, issue_date: e.target.value })}
                  required
                />
              </div>

              <div>
                <Label htmlFor="expiry_date" className="text-xs font-semibold">
                  Expiry Date
                </Label>
                <Input
                  id="expiry_date"
                  type="date"
                  className="mt-1 h-10 text-sm"
                  value={createFormData.expiry_date || ''}
                  onChange={(e) => setCreateFormData({ ...createFormData, expiry_date: e.target.value })}
                />
              </div>
            </div>

            <div>
              <Label htmlFor="notes" className="text-xs font-semibold">
                Terms / Notes
              </Label>
              <textarea
                id="notes"
                className="mt-1 w-full p-2.5 rounded-md border border-border bg-background text-foreground text-sm"
                rows={2}
                value={createFormData.notes || ''}
                onChange={(e) => setCreateFormData({ ...createFormData, notes: e.target.value })}
                placeholder="Payment terms, delivery conditions, or special discounts..."
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

              {/* Total calculations footer */}
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
                {submitting ? 'Creating...' : 'Create Quotation'}
              </Button>
            </DialogFooter>
          </form>
        </Dialog>

        {/* Convert to Invoice Modal */}
        <Dialog open={isConvertOpen} onOpenChange={setIsConvertOpen}>
          <DialogHeader>
            <DialogTitle>Convert Quotation to Invoice</DialogTitle>
            <DialogDescription>
              Convert quotation {selectedQuotation?.quotation_number} into a formal sales invoice.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleConvertSubmit} className="space-y-4">
            <div className="p-4 rounded-lg bg-muted/40 border border-border space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Quotation Number:</span>
                <span className="font-mono font-semibold">{selectedQuotation?.quotation_number}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Customer:</span>
                <span className="font-medium">
                  {selectedQuotation ? customerMap.get(selectedQuotation.customer_id) : '—'}
                </span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Quotation Total:</span>
                <span className="font-mono font-bold text-primary tabular-nums">
                  {selectedQuotation
                    ? formatMoneyCents(selectedQuotation.total_cents, selectedQuotation.currency)
                    : '$0.00'}
                </span>
              </div>
            </div>

            <div>
              <Label htmlFor="convert_due_date" className="text-xs font-semibold">
                Invoice Due Date
              </Label>
              <Input
                id="convert_due_date"
                type="date"
                className="mt-1 h-10 text-sm"
                value={convertDueDate}
                onChange={(e) => setConvertDueDate(e.target.value)}
                required
              />
            </div>

            <div className="flex items-center space-x-2">
              <input
                id="convert_auto_post"
                type="checkbox"
                checked={convertAutoPost}
                onChange={(e) => setConvertAutoPost(e.target.checked)}
                className="h-4 w-4 rounded border-border text-primary focus:ring-ring"
              />
              <Label htmlFor="convert_auto_post" className="text-xs text-foreground cursor-pointer">
                Post invoice immediately (makes invoice immutable and commits AR balance)
              </Label>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsConvertOpen(false)}
                disabled={submitting}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={submitting}
                className="bg-primary text-primary-foreground hover:bg-primary/90"
              >
                {submitting ? 'Converting...' : 'Confirm Conversion'}
              </Button>
            </DialogFooter>
          </form>
        </Dialog>

        {/* View Details Modal */}
        <Dialog open={isDetailOpen} onOpenChange={setIsDetailOpen}>
          <DialogHeader>
            <DialogTitle>Quotation Details</DialogTitle>
            <DialogDescription>
              {selectedQuotation?.quotation_number} - {selectedQuotation?.status_label}
            </DialogDescription>
          </DialogHeader>

          {selectedQuotation && (
            <div className="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
              <div className="grid grid-cols-2 gap-4 text-sm bg-muted/20 p-4 rounded-lg border border-border">
                <div>
                  <span className="text-xs text-muted-foreground block">Customer</span>
                  <span className="font-semibold text-foreground">
                    {customerMap.get(selectedQuotation.customer_id) || selectedQuotation.customer_id}
                  </span>
                </div>
                <div>
                  <span className="text-xs text-muted-foreground block">Status</span>
                  <div>{renderStatusBadge(selectedQuotation.status)}</div>
                </div>
                <div>
                  <span className="text-xs text-muted-foreground block">Issue Date</span>
                  <span className="font-medium">{selectedQuotation.issue_date}</span>
                </div>
                <div>
                  <span className="text-xs text-muted-foreground block">Expiry Date</span>
                  <span className="font-medium">{selectedQuotation.expiry_date || 'None'}</span>
                </div>
              </div>

              {selectedQuotation.notes && (
                <div className="text-xs text-muted-foreground bg-card p-3 rounded border border-border">
                  <span className="font-semibold block mb-1">Notes:</span>
                  {selectedQuotation.notes}
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
                      {selectedQuotation.items && selectedQuotation.items.length > 0 ? (
                        selectedQuotation.items.map((item) => (
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
                              {formatMoneyCents(item.unit_price_cents, selectedQuotation.currency)}
                            </td>
                            <td className="p-2.5 text-right font-mono tabular-nums">{item.tax_rate_percent}%</td>
                            <td className="p-2.5 text-right font-mono font-semibold tabular-nums">
                              {formatMoneyCents(item.total_cents, selectedQuotation.currency)}
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
                          {formatMoneyCents(selectedQuotation.subtotal_cents, selectedQuotation.currency)}
                        </td>
                      </tr>
                      <tr>
                        <td colSpan={4} className="p-2 text-right">Tax:</td>
                        <td className="p-2 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedQuotation.tax_cents, selectedQuotation.currency)}
                        </td>
                      </tr>
                      <tr className="text-sm font-bold text-primary">
                        <td colSpan={4} className="p-2.5 text-right">Total Amount:</td>
                        <td className="p-2.5 text-right font-mono tabular-nums">
                          {formatMoneyCents(selectedQuotation.total_cents, selectedQuotation.currency)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              {/* Conversion or lifecycle notes */}
              {selectedQuotation.converted_to_invoice_id && (
                <div className="p-3 rounded-lg bg-primary/10 border border-primary/20 flex items-center gap-2 text-xs">
                  <FileText className="h-4 w-4 text-primary" />
                  <span>
                    Converted to invoice on{' '}
                    {selectedQuotation.converted_at
                      ? new Date(selectedQuotation.converted_at).toLocaleDateString()
                      : 'N/A'}
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
      </div>
    </AppLayout>
  );
}
