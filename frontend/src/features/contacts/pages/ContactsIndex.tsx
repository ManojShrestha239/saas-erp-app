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
import { CsvImportDialog } from '@/shared/components/CsvImportDialog';
import { useCan } from '@/features/auth/hooks/useCan';
import { contactsApi } from '../api/contactsApi';
import type { ContactItem, ContactFormData, ContactType } from '../types/contacts.types';
import {
  UserPlus,
  RefreshCw,
  Search,
  MoreHorizontal,
  Edit2,
  Trash2,
  Mail,
  Phone,
  Building,
  CheckCircle2,
  AlertCircle,
  FileSpreadsheet,
} from 'lucide-react';

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

export default function ContactsIndex() {
  const canManageContacts = useCan('contacts.manage');
  const canManageCustomers = useCan('customers.manage');
  const canManageSuppliers = useCan('suppliers.manage');
  const hasManageAccess = canManageContacts || canManageCustomers || canManageSuppliers;

  // Table state
  const [contacts, setContacts] = useState<ContactItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [totalCount, setTotalCount] = useState(0);

  // Filters & Pagination
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState<ContactType | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [pagination, setPagination] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 25,
  });
  const [sorting, setSorting] = useState<SortingState>([
    { id: 'name', desc: false },
  ]);

  // Dialog states
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [editingContact, setEditingContact] = useState<ContactItem | null>(null);
  const [isImportOpen, setIsImportOpen] = useState(false);
  const [deletingContact, setDeletingContact] = useState<ContactItem | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Form Fields
  const [formData, setFormData] = useState<{
    name: string;
    type: ContactType;
    tax_id: string;
    email: string;
    phone: string;
    payment_terms_days: number;
    credit_limit: string;
    opening_balance: string;
    is_active: boolean;
    notes: string;
    street: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;
  }>({
    name: '',
    type: 'customer',
    tax_id: '',
    email: '',
    phone: '',
    payment_terms_days: 30,
    credit_limit: '0',
    opening_balance: '0',
    is_active: true,
    notes: '',
    street: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
  });

  const fetchContacts = useCallback(async () => {
    setLoading(true);
    try {
      const sortBy = sorting[0]?.id || 'name';
      const sortDirection = sorting[0]?.desc ? 'desc' : 'asc';

      const response = await contactsApi.getContacts({
        search: search.trim() || undefined,
        type: typeFilter === 'all' ? undefined : typeFilter,
        is_active: statusFilter === 'all' ? undefined : statusFilter === 'active',
        sort_by: sortBy,
        sort_direction: sortDirection,
        page: pagination.pageIndex + 1,
        per_page: pagination.pageSize,
      });

      setContacts(response.data);
      if (response.meta?.total !== undefined) {
        setTotalCount(response.meta.total);
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to fetch contacts';
      setFeedback({ type: 'error', message });
    } finally {
      setLoading(false);
    }
  }, [search, typeFilter, statusFilter, pagination.pageIndex, pagination.pageSize, sorting]);

  useEffect(() => {
    fetchContacts();
  }, [fetchContacts]);

  const handleOpenCreate = () => {
    setEditingContact(null);
    setFormData({
      name: '',
      type: 'customer',
      tax_id: '',
      email: '',
      phone: '',
      payment_terms_days: 30,
      credit_limit: '0',
      opening_balance: '0',
      is_active: true,
      notes: '',
      street: '',
      city: '',
      state: '',
      postal_code: '',
      country: '',
    });
    setFormError(null);
    setIsFormOpen(true);
  };

  const handleOpenEdit = (contact: ContactItem) => {
    setEditingContact(contact);
    setFormData({
      name: contact.name,
      type: contact.type,
      tax_id: contact.tax_id || '',
      email: contact.email || '',
      phone: contact.phone || '',
      payment_terms_days: contact.payment_terms_days,
      credit_limit: (contact.credit_limit_cents / 100).toFixed(2),
      opening_balance: (contact.opening_balance_cents / 100).toFixed(2),
      is_active: contact.is_active,
      notes: contact.notes || '',
      street: (contact.billing_address?.street as string) || '',
      city: (contact.billing_address?.city as string) || '',
      state: (contact.billing_address?.state as string) || '',
      postal_code: (contact.billing_address?.postal_code as string) || '',
      country: (contact.billing_address?.country as string) || '',
    });
    setFormError(null);
    setIsFormOpen(true);
  };

  const handleFormSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      setFormError('Contact name is required.');
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const creditLimitCents = Math.round(parseFloat(formData.credit_limit || '0') * 100);
      const openingBalanceCents = Math.round(parseFloat(formData.opening_balance || '0') * 100);

      const payload: ContactFormData = {
        name: formData.name.trim(),
        type: formData.type,
        tax_id: formData.tax_id.trim() || undefined,
        email: formData.email.trim() || undefined,
        phone: formData.phone.trim() || undefined,
        payment_terms_days: Number(formData.payment_terms_days) || 0,
        credit_limit_cents: creditLimitCents >= 0 ? creditLimitCents : 0,
        opening_balance_cents: openingBalanceCents,
        is_active: formData.is_active,
        notes: formData.notes.trim() || undefined,
        billing_address: {
          street: formData.street.trim() || undefined,
          city: formData.city.trim() || undefined,
          state: formData.state.trim() || undefined,
          postal_code: formData.postal_code.trim() || undefined,
          country: formData.country.trim() || undefined,
        },
      };

      if (editingContact) {
        await contactsApi.updateContact(editingContact.id, payload);
        setFeedback({ type: 'success', message: `Contact "${payload.name}" updated successfully.` });
      } else {
        await contactsApi.createContact(payload);
        setFeedback({ type: 'success', message: `Contact "${payload.name}" created successfully.` });
      }

      setIsFormOpen(false);
      fetchContacts();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to save contact';
      setFormError(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingContact) return;
    setSubmitting(true);
    try {
      await contactsApi.deleteContact(deletingContact.id);
      setFeedback({ type: 'success', message: `Contact "${deletingContact.name}" deleted successfully.` });
      setDeletingContact(null);
      fetchContacts();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to delete contact';
      setFeedback({ type: 'error', message });
    } finally {
      setSubmitting(false);
    }
  };

  const columns = useMemo<ColumnDef<ContactItem>[]>(() => [
    {
      accessorKey: 'code',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Code" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-semibold text-primary px-2 py-0.5 rounded bg-primary/10">
          {row.getValue('code')}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'name',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
      cell: ({ row }) => {
        const contact = row.original;
        return (
          <div className="flex flex-col">
            <span className="font-semibold text-foreground text-sm">{contact.name}</span>
            {contact.tax_id && (
              <span className="text-xs text-muted-foreground">VAT/Tax: {contact.tax_id}</span>
            )}
          </div>
        );
      },
      enableSorting: true,
    },
    {
      accessorKey: 'type',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Type" />,
      cell: ({ row }) => {
        const type = row.getValue('type') as ContactType;
        const badgeClasses = {
          customer: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300 border-blue-200',
          supplier: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300 border-purple-200',
          both: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 border-amber-200',
        }[type];

        return (
          <Badge variant="outline" className={badgeClasses}>
            {row.original.type_label || type}
          </Badge>
        );
      },
      enableSorting: false,
    },
    {
      id: 'contact_info',
      header: 'Contact Details',
      cell: ({ row }) => {
        const contact = row.original;
        return (
          <div className="flex flex-col text-xs text-muted-foreground gap-1">
            {contact.email && (
              <div className="flex items-center gap-1.5">
                <Mail className="h-3.5 w-3.5 text-muted-foreground/70" />
                <a href={`mailto:${contact.email}`} className="hover:underline text-foreground">
                  {contact.email}
                </a>
              </div>
            )}
            {contact.phone && (
              <div className="flex items-center gap-1.5">
                <Phone className="h-3.5 w-3.5 text-muted-foreground/70" />
                <span>{contact.phone}</span>
              </div>
            )}
            {!contact.email && !contact.phone && <span>—</span>}
          </div>
        );
      },
    },
    {
      accessorKey: 'payment_terms_days',
      header: 'Terms',
      cell: ({ row }) => {
        const terms = row.getValue('payment_terms_days') as number;
        return <span className="text-xs font-medium">{terms > 0 ? `${terms} days` : 'Immediate'}</span>;
      },
    },
    {
      accessorKey: 'credit_limit_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Credit Limit" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs">
          {formatMoneyCents(row.getValue('credit_limit_cents'))}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'opening_balance_cents',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Opening Balance" />,
      cell: ({ row }) => (
        <span className="font-mono text-xs font-medium">
          {formatMoneyCents(row.getValue('opening_balance_cents'))}
        </span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'is_active',
      header: 'Status',
      cell: ({ row }) => {
        const active = row.getValue('is_active') as boolean;
        return (
          <Badge variant={active ? 'default' : 'secondary'} className={active ? 'bg-emerald-600' : 'bg-muted'}>
            {active ? 'Active' : 'Inactive'}
          </Badge>
        );
      },
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => {
        const contact = row.original;
        if (!hasManageAccess) return null;

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="h-8 w-8 p-0" aria-label="Contact actions">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-36">
              <DropdownMenuItem onClick={() => handleOpenEdit(contact)}>
                <Edit2 className="mr-2 h-3.5 w-3.5" />
                <span>Edit</span>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="text-destructive focus:text-destructive"
                onClick={() => setDeletingContact(contact)}
              >
                <Trash2 className="mr-2 h-3.5 w-3.5" />
                <span>Delete</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ], [hasManageAccess]);

  return (
    <AppLayout
      title="Contacts"
      activeNav="contacts"
      breadcrumbs={[
        { label: 'Dashboard', href: '/dashboard' },
        { label: 'Contacts' },
      ]}
    >
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
              <Building className="h-6 w-6 text-primary" />
              Contacts Directory
            </h1>
            <p className="text-sm text-muted-foreground">
              Manage commercial customers, suppliers, payment terms, and credit limits.
            </p>
          </div>

          <div className="flex items-center gap-2.5">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsImportOpen(true)}
              className="gap-1.5"
            >
              <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
              <span>Import CSV</span>
            </Button>

            {hasManageAccess && (
              <Button size="sm" onClick={handleOpenCreate} className="gap-1.5">
                <UserPlus className="h-4 w-4" />
                <span>Add Contact</span>
              </Button>
            )}
          </div>
        </div>

        {/* Feedback banners */}
        {feedback && (
          <Alert variant={feedback.type === 'error' ? 'destructive' : 'default'} className="relative">
            {feedback.type === 'error' ? (
              <AlertCircle className="h-4 w-4" />
            ) : (
              <CheckCircle2 className="h-4 w-4 text-emerald-600" />
            )}
            <AlertDescription className="flex items-center justify-between">
              <span>{feedback.message}</span>
              <button
                type="button"
                onClick={() => setFeedback(null)}
                className="text-xs text-muted-foreground hover:text-foreground font-semibold ml-4"
              >
                Dismiss
              </button>
            </AlertDescription>
          </Alert>
        )}

        {/* Filter bar */}
        <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between bg-card p-3 rounded-lg border">
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
            {(
              [
                { label: 'All Contacts', value: 'all' },
                { label: 'Customers', value: 'customer' },
                { label: 'Suppliers', value: 'supplier' },
                { label: 'Both', value: 'both' },
              ] as const
            ).map((tab) => (
              <Button
                key={tab.value}
                variant={typeFilter === tab.value ? 'default' : 'ghost'}
                size="sm"
                className="text-xs h-8"
                onClick={() => {
                  setTypeFilter(tab.value);
                  setPagination((p) => ({ ...p, pageIndex: 0 }));
                }}
              >
                {tab.label}
              </Button>
            ))}
          </div>

          <div className="flex items-center gap-2.5">
            <select
              aria-label="Filter by status"
              className="h-9 px-2.5 rounded-md border text-xs bg-background text-foreground"
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value as 'all' | 'active' | 'inactive');
                setPagination((p) => ({ ...p, pageIndex: 0 }));
              }}
            >
              <option value="all">All Status</option>
              <option value="active">Active Only</option>
              <option value="inactive">Inactive Only</option>
            </select>

            <div className="relative w-full sm:w-60">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search name, code, email..."
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
              onClick={fetchContacts}
              disabled={loading}
              className="h-9 w-9 p-0"
              title="Refresh"
            >
              <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            </Button>
          </div>
        </div>

        {/* Table */}
        <DataTable
          columns={columns}
          data={contacts}
          isLoading={loading}
          serverSide={{
            totalRows: totalCount,
            pagination,
            onPaginationChange: setPagination,
            sorting,
            onSortingChange: setSorting,
          }}
          emptyTitle="No contacts found"
          emptyDescription={
            search || typeFilter !== 'all'
              ? 'Try modifying your filter or search keywords.'
              : 'Create your first commercial partner or import from a CSV sheet.'
          }
        />

        {/* Add/Edit Modal */}
        <Dialog open={isFormOpen} onOpenChange={setIsFormOpen}>
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
            <div className="bg-background rounded-lg border shadow-lg w-full max-w-xl max-h-[90vh] flex flex-col overflow-hidden">
              <DialogHeader className="p-6 pb-4 border-b">
                <DialogTitle>{editingContact ? 'Edit Contact' : 'Create New Contact'}</DialogTitle>
                <DialogDescription>
                  Enter commercial and financial details for this contact entity.
                </DialogDescription>
              </DialogHeader>

              <form onSubmit={handleFormSubmit} className="flex-1 overflow-y-auto p-6 space-y-4">
                {formError && (
                  <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertDescription>{formError}</AlertDescription>
                  </Alert>
                )}

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="sm:col-span-2">
                    <Label htmlFor="contact-name" required>
                      Legal / Trading Name
                    </Label>
                    <Input
                      id="contact-name"
                      required
                      placeholder="e.g. Acme Corp Ltd."
                      value={formData.name}
                      onChange={(e) => setFormData((d) => ({ ...d, name: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-type" required>
                      Contact Classification
                    </Label>
                    <select
                      id="contact-type"
                      className="w-full h-9 px-3 rounded-md border text-sm bg-background"
                      value={formData.type}
                      onChange={(e) => setFormData((d) => ({ ...d, type: e.target.value as ContactType }))}
                    >
                      <option value="customer">Customer (Buyer)</option>
                      <option value="supplier">Supplier (Vendor)</option>
                      <option value="both">Both (Dual Partner)</option>
                    </select>
                  </div>

                  <div>
                    <Label htmlFor="contact-tax">VAT / Tax ID</Label>
                    <Input
                      id="contact-tax"
                      placeholder="e.g. PAN-998877"
                      value={formData.tax_id}
                      onChange={(e) => setFormData((d) => ({ ...d, tax_id: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-email">Email Address</Label>
                    <Input
                      id="contact-email"
                      type="email"
                      placeholder="billing@acme.com"
                      value={formData.email}
                      onChange={(e) => setFormData((d) => ({ ...d, email: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-phone">Phone Number</Label>
                    <Input
                      id="contact-phone"
                      placeholder="+977-1-445566"
                      value={formData.phone}
                      onChange={(e) => setFormData((d) => ({ ...d, phone: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-terms">Payment Terms (Days)</Label>
                    <Input
                      id="contact-terms"
                      type="number"
                      min="0"
                      value={formData.payment_terms_days}
                      onChange={(e) => setFormData((d) => ({ ...d, payment_terms_days: Number(e.target.value) }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-credit">Credit Limit (NPR)</Label>
                    <Input
                      id="contact-credit"
                      type="number"
                      min="0"
                      step="0.01"
                      value={formData.credit_limit}
                      onChange={(e) => setFormData((d) => ({ ...d, credit_limit: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-opening">Opening Balance (NPR)</Label>
                    <Input
                      id="contact-opening"
                      type="number"
                      step="0.01"
                      value={formData.opening_balance}
                      onChange={(e) => setFormData((d) => ({ ...d, opening_balance: e.target.value }))}
                    />
                  </div>

                  <div className="flex items-center gap-2 pt-6">
                    <input
                      id="contact-active"
                      type="checkbox"
                      className="rounded border-input text-primary focus:ring-primary h-4 w-4"
                      checked={formData.is_active}
                      onChange={(e) => setFormData((d) => ({ ...d, is_active: e.target.checked }))}
                    />
                    <Label htmlFor="contact-active" className="cursor-pointer mb-0">
                      Active Account
                    </Label>
                  </div>

                  <div className="sm:col-span-2">
                    <Label htmlFor="contact-street">Billing Street Address</Label>
                    <Input
                      id="contact-street"
                      placeholder="Street address line"
                      value={formData.street}
                      onChange={(e) => setFormData((d) => ({ ...d, street: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-city">City</Label>
                    <Input
                      id="contact-city"
                      placeholder="Kathmandu"
                      value={formData.city}
                      onChange={(e) => setFormData((d) => ({ ...d, city: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="contact-state">State / Province</Label>
                    <Input
                      id="contact-state"
                      placeholder="Bagmati"
                      value={formData.state}
                      onChange={(e) => setFormData((d) => ({ ...d, state: e.target.value }))}
                    />
                  </div>

                  <div className="sm:col-span-2">
                    <Label htmlFor="contact-notes">Notes / Observations</Label>
                    <textarea
                      id="contact-notes"
                      rows={2}
                      className="w-full px-3 py-2 rounded-md border text-sm bg-background"
                      placeholder="Internal commercial terms or contacts..."
                      value={formData.notes}
                      onChange={(e) => setFormData((d) => ({ ...d, notes: e.target.value }))}
                    />
                  </div>
                </div>

                <DialogFooter className="pt-4 border-t gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setIsFormOpen(false)}
                    disabled={submitting}
                  >
                    Cancel
                  </Button>
                  <Button type="submit" disabled={submitting}>
                    {submitting ? 'Saving...' : editingContact ? 'Update Contact' : 'Create Contact'}
                  </Button>
                </DialogFooter>
              </form>
            </div>
          </div>
        </Dialog>

        {/* Delete Confirmation Dialog */}
        <Dialog open={!!deletingContact} onOpenChange={(open) => !open && setDeletingContact(null)}>
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
            <div className="bg-background rounded-lg border shadow-lg w-full max-w-md p-6 space-y-4">
              <DialogHeader>
                <DialogTitle className="text-destructive flex items-center gap-2">
                  <AlertCircle className="h-5 w-5" />
                  Confirm Contact Deletion
                </DialogTitle>
                <DialogDescription>
                  Are you sure you want to delete contact <strong>{deletingContact?.name}</strong> (
                  {deletingContact?.code})? This action cannot be undone if no linked documents exist.
                </DialogDescription>
              </DialogHeader>

              <DialogFooter className="gap-2">
                <Button
                  variant="outline"
                  onClick={() => setDeletingContact(null)}
                  disabled={submitting}
                >
                  Cancel
                </Button>
                <Button variant="destructive" onClick={handleDelete} disabled={submitting}>
                  {submitting ? 'Deleting...' : 'Delete Contact'}
                </Button>
              </DialogFooter>
            </div>
          </div>
        </Dialog>

        {/* CSV Import Dialog */}
        <CsvImportDialog
          open={isImportOpen}
          onOpenChange={setIsImportOpen}
          type="contacts"
          title="Import Contacts"
          description="Upload a CSV file to bulk import customers and suppliers with payment terms and credit limits."
          onImportComplete={fetchContacts}
        />
      </div>
    </AppLayout>
  );
}
