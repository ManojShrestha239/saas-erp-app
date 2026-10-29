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
import { paymentsApi } from '../api/paymentsApi';
import { purchasingApi } from '@/features/purchasing/api/purchasingApi';
import { contactsApi } from '@/features/contacts/api/contactsApi';
import type { ContactItem } from '@/features/contacts/types/contacts.types';
import type { SupplierBill } from '@/features/purchasing/types/purchasing.types';
import type {
  Payment,
  PaymentStatus,
  PaymentMethod,
  CreatePaymentPayload,
  PaymentAllocationItemPayload,
} from '../types/payments.types';
import {
  Plus,
  RefreshCw,
  Search,
  MoreHorizontal,
  CheckCircle,
  Eye,
  Wallet,
  AlertCircle,
  ArrowUpRight,
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

const METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  bank_transfer: 'Bank Transfer',
  cheque: 'Cheque',
  card: 'Card',
  other: 'Other',
};

export default function SupplierPaymentsIndex() {
  const canManage = useCan('payments.manage');
  const canSupplierBills = useCan('payments.supplier.bills');
  const canPurchases = useCan('purchases.manage');
  const canCreate = canManage || canSupplierBills || canPurchases;

  // Table State
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [totalCount, setTotalCount] = useState(0);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [supplierFilter, setSupplierFilter] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 25 });
  const [sorting, setSorting] = useState<SortingState>([]);

  // Reference data
  const [suppliers, setSuppliers] = useState<ContactItem[]>([]);
  const [supplierCreditCents, setSupplierCreditCents] = useState<number | null>(null);

  // Dialog States
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isAllocateOpen, setIsAllocateOpen] = useState(false);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isPostConfirmOpen, setIsPostConfirmOpen] = useState(false);
  const [selectedPayment, setSelectedPayment] = useState<Payment | null>(null);

  // Submitting & Feedback
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Create Form State
  const [createSupplierId, setCreateSupplierId] = useState('');
  const [createAmountDollars, setCreateAmountDollars] = useState('');
  const [createMethod, setCreateMethod] = useState<PaymentMethod>('bank_transfer');
  const [createDate, setCreateDate] = useState(new Date().toISOString().split('T')[0] ?? '');
  const [createReference, setCreateReference] = useState('');
  const [createNotes, setCreateNotes] = useState('');
  const [createPostImmediately, setCreatePostImmediately] = useState(true);

  // Allocation State
  const [openBills, setOpenBills] = useState<SupplierBill[]>([]);
  const [loadingBills, setLoadingBills] = useState(false);
  const [allocationInputs, setAllocationInputs] = useState<Record<string, string>>({});

  // Load Suppliers
  useEffect(() => {
    async function loadSuppliers() {
      try {
        const res = await contactsApi.getContacts({ type: 'supplier' });
        if (res.data) {
          setSuppliers(res.data);
        }
      } catch {
        // silent fallback
      }
    }
    loadSuppliers();
  }, []);

  const supplierMap = useMemo(() => {
    const map = new Map<string, string>();
    for (const s of suppliers) {
      map.set(s.id, s.name);
    }
    return map;
  }, [suppliers]);

  // Load Payments
  const fetchPayments = useCallback(async () => {
    setLoading(true);
    try {
      const filters: Record<string, unknown> = {
        type: 'outbound',
        page: pagination.pageIndex + 1,
        per_page: pagination.pageSize,
      };

      if (statusFilter !== 'all') {
        filters.status = statusFilter;
      }
      if (supplierFilter) {
        filters.contact_id = supplierFilter;
      }

      const res = await paymentsApi.getPayments(filters);
      if (res.data) {
        let items = res.data;
        if (searchQuery.trim()) {
          const q = searchQuery.toLowerCase();
          items = items.filter((p) => {
            const suppName = (supplierMap.get(p.contact_id) || '').toLowerCase();
            return (
              p.payment_number.toLowerCase().includes(q) ||
              (p.reference_number && p.reference_number.toLowerCase().includes(q)) ||
              suppName.includes(q)
            );
          });
        }
        setPayments(items);
        setTotalCount(res.meta?.total ?? items.length);
      }
    } catch {
      setFeedback({ type: 'error', message: 'Failed to load supplier payments.' });
    } finally {
      setLoading(false);
    }
  }, [pagination, statusFilter, supplierFilter, searchQuery, supplierMap]);

  useEffect(() => {
    fetchPayments();
  }, [fetchPayments]);

  // Fetch unallocated credit for filtered supplier
  useEffect(() => {
    if (!supplierFilter) {
      setSupplierCreditCents(null);
      return;
    }
    async function loadCredit() {
      try {
        const res = await paymentsApi.getUnallocatedCredit(supplierFilter, 'outbound');
        if (res.data) {
          setSupplierCreditCents(res.data.unallocated_credit_cents);
        }
      } catch {
        setSupplierCreditCents(null);
      }
    }
    loadCredit();
  }, [supplierFilter]);

  // Load open bills for a supplier
  const loadOpenBills = useCallback(async (supplierId: string) => {
    setLoadingBills(true);
    try {
      const res = await purchasingApi.getBills({ supplier_id: supplierId });
      if (res.data) {
        const unpaid = res.data.filter(
          (bill) =>
            (bill.status === 'posted' || bill.status === 'partially_paid') &&
            bill.amount_due_cents > 0
        );
        setOpenBills(unpaid);
        const initialInputs: Record<string, string> = {};
        for (const bill of unpaid) {
          initialInputs[bill.id] = '';
        }
        setAllocationInputs(initialInputs);
      }
    } catch {
      setOpenBills([]);
    } finally {
      setLoadingBills(false);
    }
  }, []);

  // Open Allocate Modal
  const handleOpenAllocate = useCallback(async (payment: Payment) => {
    setSelectedPayment(payment);
    setFormError(null);
    setIsAllocateOpen(true);
    await loadOpenBills(payment.contact_id);
  }, [loadOpenBills]);

  // Open Details Modal
  const handleOpenDetails = async (payment: Payment) => {
    try {
      const res = await paymentsApi.getPayment(payment.id);
      setSelectedPayment(res.data || payment);
    } catch {
      setSelectedPayment(payment);
    }
    setIsDetailOpen(true);
  };

  // Handle Post Payment
  const handleConfirmPost = async () => {
    if (!selectedPayment) return;
    setSubmitting(true);
    try {
      await paymentsApi.postPayment(selectedPayment.id);
      setFeedback({ type: 'success', message: `Payment ${selectedPayment.payment_number} posted successfully.` });
      setIsPostConfirmOpen(false);
      setSelectedPayment(null);
      fetchPayments();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to post payment.';
      setFeedback({ type: 'error', message });
    } finally {
      setSubmitting(false);
    }
  };

  // Submit Allocate
  const handleAllocateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedPayment) return;

    const allocations: PaymentAllocationItemPayload[] = [];
    let totalCents = 0;

    for (const [billId, amountStr] of Object.entries(allocationInputs)) {
      const val = parseFloat(amountStr);
      if (!isNaN(val) && val > 0) {
        const cents = Math.round(val * 100);
        allocations.push({
          payable_type: 'bill',
          payable_id: billId,
          amount_cents: cents,
        });
        totalCents += cents;
      }
    }

    if (allocations.length === 0) {
      setFormError('Please specify at least one bill allocation amount greater than zero.');
      return;
    }

    if (totalCents > selectedPayment.unallocated_amount_cents) {
      setFormError(
        `Total allocated (${formatMoneyCents(totalCents)}) exceeds available unallocated credit (${formatMoneyCents(selectedPayment.unallocated_amount_cents)}).`
      );
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      await paymentsApi.allocatePayment(selectedPayment.id, { allocations });
      setFeedback({ type: 'success', message: 'Allocations applied successfully.' });
      setIsAllocateOpen(false);
      setSelectedPayment(null);
      fetchPayments();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Allocation failed.';
      setFormError(message);
    } finally {
      setSubmitting(false);
    }
  };

  // Auto-allocate helper for Allocate modal
  const handleAutoAllocate = () => {
    if (!selectedPayment) return;
    let availableCents = selectedPayment.unallocated_amount_cents;
    const nextInputs: Record<string, string> = {};

    for (const bill of openBills) {
      if (availableCents <= 0) {
        nextInputs[bill.id] = '';
        continue;
      }
      const canAllocCents = Math.min(bill.amount_due_cents, availableCents);
      if (canAllocCents > 0) {
        nextInputs[bill.id] = (canAllocCents / 100).toFixed(2);
        availableCents -= canAllocCents;
      } else {
        nextInputs[bill.id] = '';
      }
    }
    setAllocationInputs(nextInputs);
  };

  // Submit Create Payment
  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!createSupplierId) {
      setFormError('Please select a supplier.');
      return;
    }

    const dollars = parseFloat(createAmountDollars);
    if (isNaN(dollars) || dollars <= 0) {
      setFormError('Please enter a valid amount greater than zero.');
      return;
    }

    const amountCents = Math.round(dollars * 100);

    const payload: CreatePaymentPayload = {
      type: 'outbound',
      contact_id: createSupplierId,
      amount_cents: amountCents,
      currency: 'USD',
      payment_method: createMethod,
      payment_date: createDate,
      reference_number: createReference.trim() || undefined,
      notes: createNotes.trim() || undefined,
      post_immediately: createPostImmediately,
    };

    setSubmitting(true);
    setFormError(null);

    try {
      const res = await paymentsApi.createPayment(payload);
      setFeedback({
        type: 'success',
        message: `Supplier payment ${res.data.payment_number} recorded successfully.`,
      });
      setIsCreateOpen(false);
      // Reset form
      setCreateSupplierId('');
      setCreateAmountDollars('');
      setCreateReference('');
      setCreateNotes('');
      fetchPayments();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to record payment.';
      setFormError(message);
    } finally {
      setSubmitting(false);
    }
  };

  // Status Badge Helper
  const renderStatusBadge = (status: PaymentStatus) => {
    switch (status) {
      case 'posted':
        return (
          <Badge variant="outline" className="bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30">
            Posted
          </Badge>
        );
      case 'draft':
        return (
          <Badge variant="outline" className="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30">
            Draft
          </Badge>
        );
      case 'void':
        return (
          <Badge variant="outline" className="bg-destructive/15 text-destructive border-destructive/30">
            Void
          </Badge>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  // Columns
  const columns = useMemo<ColumnDef<Payment>[]>(() => [
    {
      accessorKey: 'payment_number',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Payment #" />,
      cell: ({ row }) => (
        <button
          type="button"
          onClick={() => handleOpenDetails(row.original)}
          className="font-mono text-xs font-semibold text-primary hover:underline px-2 py-0.5 rounded bg-primary/10 text-left"
        >
          {row.getValue('payment_number')}
        </button>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'contact_id',
      header: 'Supplier',
      cell: ({ row }) => {
        const suppId = row.original.contact_id;
        const name = supplierMap.get(suppId) || suppId.substring(0, 8);
        return <span className="font-medium text-foreground text-sm">{name}</span>;
      },
      enableSorting: false,
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => renderStatusBadge(row.getValue('status') as PaymentStatus),
      enableSorting: true,
    },
    {
      accessorKey: 'payment_method',
      header: 'Method',
      cell: ({ row }) => {
        const method = row.original.payment_method;
        return <span className="text-xs text-muted-foreground">{METHOD_LABELS[method] || method}</span>;
      },
      enableSorting: false,
    },
    {
      accessorKey: 'payment_date',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Date" />,
      cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.getValue('payment_date')}</span>,
      enableSorting: true,
    },
    {
      accessorKey: 'amount_cents',
      header: ({ column }) => (
        <div className="text-right">
          <DataTableColumnHeader column={column} title="Amount" />
        </div>
      ),
      cell: ({ row }) => (
        <div className="text-right">
          <span className="font-mono text-xs font-bold text-foreground tabular-nums">
            {formatMoneyCents(row.getValue('amount_cents'), row.original.currency)}
          </span>
        </div>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'allocated_amount_cents',
      header: ({ column }) => (
        <div className="text-right">
          <DataTableColumnHeader column={column} title="Allocated" />
        </div>
      ),
      cell: ({ row }) => (
        <div className="text-right">
          <span className="font-mono text-xs text-muted-foreground tabular-nums">
            {formatMoneyCents(row.original.allocated_amount_cents, row.original.currency)}
          </span>
        </div>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'unallocated_amount_cents',
      header: ({ column }) => (
        <div className="text-right">
          <DataTableColumnHeader column={column} title="Unallocated Debit" />
        </div>
      ),
      cell: ({ row }) => {
        const unalloc = row.original.unallocated_amount_cents;
        return (
          <div className="text-right">
            {unalloc > 0 ? (
              <span className="font-mono text-xs font-semibold text-sky-600 dark:text-sky-400 bg-sky-500/10 px-1.5 py-0.5 rounded tabular-nums">
                {formatMoneyCents(unalloc, row.original.currency)}
              </span>
            ) : (
              <span className="font-mono text-xs text-muted-foreground tabular-nums">$0.00</span>
            )}
          </div>
        );
      },
      enableSorting: true,
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const p = row.original;
        return (
          <div className="flex justify-end">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8">
                  <MoreHorizontal className="h-4 w-4" />
                  <span className="sr-only">Open actions</span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem onClick={() => handleOpenDetails(p)}>
                  <Eye className="mr-2 h-4 w-4" /> View Details
                </DropdownMenuItem>

                {p.status === 'draft' && canCreate && (
                  <DropdownMenuItem
                    onClick={() => {
                      setSelectedPayment(p);
                      setIsPostConfirmOpen(true);
                    }}
                  >
                    <CheckCircle className="mr-2 h-4 w-4 text-emerald-600" /> Post Payment
                  </DropdownMenuItem>
                )}

                {p.status === 'posted' && p.unallocated_amount_cents > 0 && canCreate && (
                  <DropdownMenuItem onClick={() => handleOpenAllocate(p)}>
                    <ArrowUpRight className="mr-2 h-4 w-4 text-primary" /> Allocate to Bills
                  </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        );
      },
      enableSorting: false,
    },
  ], [supplierMap, canCreate, handleOpenAllocate]);

  return (
    <AppLayout
      activeNav="supplier-payments"
      breadcrumbs={[{ label: 'Payments', href: '/supplier-payments' }, { label: 'Supplier Payments' }]}
    >
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
              <Wallet className="h-6 w-6 text-primary" />
              Supplier Payments
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Disburse and track vendor payments, manage unallocated debits, and apply allocations against bills.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => fetchPayments()}
              disabled={loading}
              className="gap-1.5"
            >
              <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            {canCreate && (
              <Button
                size="sm"
                onClick={() => {
                  setFormError(null);
                  setIsCreateOpen(true);
                }}
                className="gap-1.5"
              >
                <Plus className="h-4 w-4" />
                Record Payment
              </Button>
            )}
          </div>
        </div>

        {/* Feedback message */}
        {feedback && (
          <Alert variant={feedback.type === 'error' ? 'destructive' : 'default'} className="animate-in fade-in">
            {feedback.type === 'error' ? <AlertCircle className="h-4 w-4" /> : <CheckCircle className="h-4 w-4" />}
            <AlertDescription className="flex justify-between items-center">
              <span>{feedback.message}</span>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setFeedback(null)}
                className="h-6 px-2 text-xs"
              >
                Dismiss
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {/* Supplier Unallocated Debit/Credit Widget */}
        {supplierCreditCents !== null && (
          <div className="p-4 rounded-lg border bg-card text-card-foreground shadow-sm flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-full bg-sky-500/10 text-sky-600 dark:text-sky-400">
                <Wallet className="h-5 w-5" />
              </div>
              <div>
                <p className="text-xs text-muted-foreground uppercase font-semibold tracking-wider">
                  Unallocated Debit Advance Balance
                </p>
                <p className="text-lg font-bold text-foreground">
                  {supplierMap.get(supplierFilter) || 'Selected Supplier'}
                </p>
              </div>
            </div>
            <div className="text-right">
              <span className="font-mono text-xl font-extrabold text-sky-600 dark:text-sky-400">
                {formatMoneyCents(supplierCreditCents)}
              </span>
            </div>
          </div>
        )}

        {/* Filters Toolbar */}
        <div className="flex flex-col sm:flex-row gap-3 items-center justify-between bg-card p-3 rounded-lg border shadow-sm">
          <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
            {/* Search */}
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search payment #, ref, supplier..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-8 h-9 text-xs"
              />
            </div>

            {/* Supplier Filter */}
            <select
              aria-label="Filter by supplier"
              value={supplierFilter}
              onChange={(e) => setSupplierFilter(e.target.value)}
              className="h-9 px-3 py-1 text-xs rounded-md border border-input bg-background text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option value="">All Suppliers</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>

          {/* Status Tabs */}
          <div className="flex items-center gap-1 self-stretch sm:self-auto overflow-x-auto">
            {['all', 'draft', 'posted', 'void'].map((st) => (
              <Button
                key={st}
                variant={statusFilter === st ? 'secondary' : 'ghost'}
                size="sm"
                onClick={() => setStatusFilter(st)}
                className="h-8 text-xs capitalize"
              >
                {st}
              </Button>
            ))}
          </div>
        </div>

        {/* Data Table */}
        <div className="rounded-lg border bg-card shadow-sm">
          <DataTable
            columns={columns}
            data={payments}
            isLoading={loading}
            serverSide={{
              pageCount: Math.ceil(totalCount / pagination.pageSize),
              totalRows: totalCount,
              pagination,
              onPaginationChange: setPagination,
              sorting,
              onSortingChange: setSorting,
            }}
            emptyTitle="No supplier payments found"
            emptyDescription="Record a payment or adjust filters."
          />
        </div>
      </div>

      {/* Record Payment Modal */}
      <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
        <DialogHeader>
          <DialogTitle>Record Supplier Payment</DialogTitle>
          <DialogDescription>
            Enter details of the disbursement made to the supplier.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleCreateSubmit} className="space-y-4 py-2">
          {formError && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{formError}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="create-supplier">Supplier *</Label>
            <select
              id="create-supplier"
              value={createSupplierId}
              onChange={(e) => setCreateSupplierId(e.target.value)}
              required
              className="w-full h-9 px-3 py-1 text-sm rounded-md border border-input bg-background text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option value="">Select a supplier...</option>
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.code})
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="create-pay-amount">Amount ($) *</Label>
              <Input
                id="create-pay-amount"
                type="number"
                step="0.01"
                min="0.01"
                placeholder="0.00"
                value={createAmountDollars}
                onChange={(e) => setCreateAmountDollars(e.target.value)}
                required
                className="font-mono text-sm"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="create-pay-method">Payment Method *</Label>
              <select
                id="create-pay-method"
                value={createMethod}
                onChange={(e) => setCreateMethod(e.target.value as PaymentMethod)}
                required
                className="w-full h-9 px-3 py-1 text-sm rounded-md border border-input bg-background text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
              >
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="create-pay-date">Payment Date *</Label>
              <Input
                id="create-pay-date"
                type="date"
                value={createDate}
                onChange={(e) => setCreateDate(e.target.value)}
                required
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="create-pay-reference">Reference / Check #</Label>
              <Input
                id="create-pay-reference"
                placeholder="e.g. EFT-99231, CHQ-552"
                value={createReference}
                onChange={(e) => setCreateReference(e.target.value)}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="create-pay-notes">Notes</Label>
            <Input
              id="create-pay-notes"
              placeholder="Optional notes or memos"
              value={createNotes}
              onChange={(e) => setCreateNotes(e.target.value)}
            />
          </div>

          <div className="flex items-center gap-2 pt-2">
            <input
              id="create-pay-post-immediately"
              type="checkbox"
              checked={createPostImmediately}
              onChange={(e) => setCreatePostImmediately(e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
            />
            <Label htmlFor="create-pay-post-immediately" className="cursor-pointer text-xs font-normal">
              Post immediately and make disbursement available for bill allocations
            </Label>
          </div>

          <DialogFooter className="pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setIsCreateOpen(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Recording...' : 'Record Payment'}
            </Button>
          </DialogFooter>
        </form>
      </Dialog>

      {/* Allocate to Bills Modal */}
      <Dialog open={isAllocateOpen} onOpenChange={setIsAllocateOpen}>
        <DialogHeader>
          <DialogTitle>Allocate Payment to Supplier Bills</DialogTitle>
          <DialogDescription>
            {selectedPayment && (
              <span>
                Payment <strong className="font-mono text-foreground">{selectedPayment.payment_number}</strong> &bull; Total:{' '}
                {formatMoneyCents(selectedPayment.amount_cents)} &bull; Available Debit Credit:{' '}
                <strong className="text-sky-600 dark:text-sky-400 font-mono">
                  {formatMoneyCents(selectedPayment.unallocated_amount_cents)}
                </strong>
              </span>
            )}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleAllocateSubmit} className="space-y-4 py-2">
          {formError && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{formError}</AlertDescription>
            </Alert>
          )}

          <div className="flex items-center justify-between">
            <p className="text-xs text-muted-foreground font-medium">Unpaid Supplier Bills</p>
            {openBills.length > 0 && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleAutoAllocate}
                className="text-xs h-7 gap-1"
              >
                Auto-Allocate Balance
              </Button>
            )}
          </div>

          {loadingBills ? (
            <div className="py-8 text-center text-xs text-muted-foreground">Loading open bills...</div>
          ) : openBills.length === 0 ? (
            <div className="py-6 text-center text-xs text-muted-foreground border rounded-md">
              No open unpaid bills found for this supplier.
            </div>
          ) : (
            <div className="max-h-64 overflow-y-auto border rounded-md divide-y">
              {openBills.map((bill) => (
                <div key={bill.id} className="p-3 flex items-center justify-between gap-4 text-xs">
                  <div>
                    <span className="font-mono font-semibold text-primary">{bill.bill_number}</span>
                    {bill.supplier_invoice_number && (
                      <span className="text-muted-foreground ml-1">({bill.supplier_invoice_number})</span>
                    )}
                    <span className="text-muted-foreground ml-2">Due {bill.due_date}</span>
                    <div className="text-muted-foreground mt-0.5">
                      Total: {formatMoneyCents(bill.total_cents)} &bull; Balance Due:{' '}
                      <span className="font-semibold text-foreground font-mono">
                        {formatMoneyCents(bill.amount_due_cents)}
                      </span>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="text-muted-foreground">$</span>
                    <Input
                      type="number"
                      step="0.01"
                      min="0"
                      max={(bill.amount_due_cents / 100).toFixed(2)}
                      placeholder="0.00"
                      value={allocationInputs[bill.id] || ''}
                      onChange={(e) =>
                        setAllocationInputs({
                          ...allocationInputs,
                          [bill.id]: e.target.value,
                        })
                      }
                      className="w-24 h-8 text-xs font-mono"
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        if (!selectedPayment) return;
                        const maxAlloc = Math.min(
                          bill.amount_due_cents,
                          selectedPayment.unallocated_amount_cents
                        );
                        setAllocationInputs({
                          ...allocationInputs,
                          [bill.id]: (maxAlloc / 100).toFixed(2),
                        });
                      }}
                      className="h-8 px-2 text-xs"
                    >
                      Max
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}

          <DialogFooter className="pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setIsAllocateOpen(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={submitting || openBills.length === 0}>
              {submitting ? 'Applying...' : 'Apply Allocations'}
            </Button>
          </DialogFooter>
        </form>
      </Dialog>

      {/* Payment Details Modal */}
      <Dialog open={isDetailOpen} onOpenChange={setIsDetailOpen}>
        <DialogHeader>
          <DialogTitle>Payment Details</DialogTitle>
          <DialogDescription>
            {selectedPayment?.payment_number} &bull; {renderStatusBadge(selectedPayment?.status || 'draft')}
          </DialogDescription>
        </DialogHeader>

        {selectedPayment && (
          <div className="space-y-4 py-2 text-xs">
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 p-3 bg-muted/30 rounded-lg border">
              <div>
                <p className="text-muted-foreground font-medium">Supplier</p>
                <p className="font-semibold text-foreground text-sm">
                  {supplierMap.get(selectedPayment.contact_id) || selectedPayment.contact_id}
                </p>
              </div>
              <div>
                <p className="text-muted-foreground font-medium">Payment Date</p>
                <p className="font-semibold text-foreground">{selectedPayment.payment_date}</p>
              </div>
              <div>
                <p className="text-muted-foreground font-medium">Payment Method</p>
                <p className="font-semibold text-foreground">
                  {METHOD_LABELS[selectedPayment.payment_method] || selectedPayment.payment_method}
                </p>
              </div>
              <div>
                <p className="text-muted-foreground font-medium">Total Amount</p>
                <p className="font-mono font-bold text-foreground text-sm">
                  {formatMoneyCents(selectedPayment.amount_cents, selectedPayment.currency)}
                </p>
              </div>
              <div>
                <p className="text-muted-foreground font-medium">Allocated Amount</p>
                <p className="font-mono text-muted-foreground">
                  {formatMoneyCents(selectedPayment.allocated_amount_cents, selectedPayment.currency)}
                </p>
              </div>
              <div>
                <p className="text-muted-foreground font-medium">Unallocated Debit</p>
                <p className="font-mono font-bold text-sky-600 dark:text-sky-400">
                  {formatMoneyCents(selectedPayment.unallocated_amount_cents, selectedPayment.currency)}
                </p>
              </div>
              {selectedPayment.reference_number && (
                <div className="col-span-2">
                  <p className="text-muted-foreground font-medium">Reference</p>
                  <p className="font-mono text-foreground">{selectedPayment.reference_number}</p>
                </div>
              )}
            </div>

            {/* Existing Allocations */}
            <div>
              <p className="font-semibold text-foreground mb-2">Bill Allocations</p>
              {!selectedPayment.allocations || selectedPayment.allocations.length === 0 ? (
                <p className="text-muted-foreground italic py-2">No bills have been allocated to this payment yet.</p>
              ) : (
                <div className="border rounded-md divide-y">
                  {selectedPayment.allocations.map((alloc) => (
                    <div key={alloc.id} className="p-2.5 flex items-center justify-between">
                      <div>
                        <span className="font-mono font-semibold text-primary">{alloc.payable_id}</span>
                        <span className="text-muted-foreground ml-2 text-2xs">
                          {alloc.allocated_at ? new Date(alloc.allocated_at).toLocaleDateString() : ''}
                        </span>
                      </div>
                      <span className="font-mono font-bold text-foreground">
                        {formatMoneyCents(alloc.amount_cents, selectedPayment.currency)}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <DialogFooter className="pt-2">
              <Button type="button" variant="outline" onClick={() => setIsDetailOpen(false)}>
                Close
              </Button>
            </DialogFooter>
          </div>
        )}
      </Dialog>

      {/* Confirm Post Dialog */}
      <Dialog open={isPostConfirmOpen} onOpenChange={setIsPostConfirmOpen}>
        <DialogHeader>
          <DialogTitle>Post Supplier Payment</DialogTitle>
          <DialogDescription>
            Are you sure you want to post payment{' '}
            <strong className="font-mono text-foreground">{selectedPayment?.payment_number}</strong> for{' '}
            <strong>{formatMoneyCents(selectedPayment?.amount_cents ?? 0)}</strong>?
            This will lock the disbursement and make the debit available for bill allocations.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="pt-4">
          <Button variant="outline" onClick={() => setIsPostConfirmOpen(false)} disabled={submitting}>
            Cancel
          </Button>
          <Button onClick={handleConfirmPost} disabled={submitting}>
            {submitting ? 'Posting...' : 'Confirm Post'}
          </Button>
        </DialogFooter>
      </Dialog>
    </AppLayout>
  );
}
