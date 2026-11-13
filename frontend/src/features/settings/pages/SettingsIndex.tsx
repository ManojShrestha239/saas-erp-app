import React, { useState, useEffect } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { settingsApi } from '../api/settingsApi';
import type { TenantSettingsDictionary, SettingsTabId } from '../types/settings.types';
import {
  Building2,
  DollarSign,
  Package,
  FileText,
  Save,
  CheckCircle2,
  AlertCircle,
  Loader2,
  ShieldAlert,
} from 'lucide-react';

interface SettingsIndexProps {
  settings?: TenantSettingsDictionary;
}

export default function SettingsIndex({ settings: initialSettings = {} }: SettingsIndexProps) {
  const [formData, setFormData] = useState<TenantSettingsDictionary>({
    company_name: initialSettings.company_name ?? '',
    company_tax_id: initialSettings.company_tax_id ?? initialSettings.tax_number ?? '',
    company_email: initialSettings.company_email ?? '',
    company_phone: initialSettings.company_phone ?? '',
    company_address: initialSettings.company_address ?? '',
    bank_details: initialSettings.bank_details ?? '',
    currency: initialSettings.currency ?? 'USD',
    timezone: initialSettings.timezone ?? 'UTC',
    date_format: initialSettings.date_format ?? 'Y-m-d',
    fiscal_year_start: initialSettings.fiscal_year_start ?? '01-01',
    allow_negative_stock: initialSettings.allow_negative_stock === '1' || initialSettings.allow_negative_stock === true,
    low_stock_threshold_default: initialSettings.low_stock_threshold_default ?? '10',
    invoice_prefix: initialSettings.invoice_prefix ?? 'INV',
    bill_prefix: initialSettings.bill_prefix ?? 'BILL',
    quotation_prefix: initialSettings.quotation_prefix ?? 'QUO',
    po_prefix: initialSettings.po_prefix ?? 'PO',
  });

  const [activeTab, setActiveTab] = useState<SettingsTabId>('company');
  const [saving, setSaving] = useState(false);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // If initialSettings were empty on load, fetch from API
  useEffect(() => {
    if (Object.keys(initialSettings).length === 0) {
      settingsApi.getSettings().then((res) => {
        if (res.data) {
          setFormData((prev) => ({
            ...prev,
            ...res.data,
            allow_negative_stock: res.data.allow_negative_stock === '1' || res.data.allow_negative_stock === true,
          }));
        }
      }).catch(() => {
        // Fallback silently if props already present
      });
    }
  }, [initialSettings]);

  const handleChange = (key: keyof TenantSettingsDictionary, value: string | boolean | number) => {
    setFormData((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setFeedback(null);

    try {
      const payload: TenantSettingsDictionary = {
        ...formData,
        allow_negative_stock: formData.allow_negative_stock ? '1' : '0',
      };

      const res = await settingsApi.updateSettings(payload);
      if (res.data) {
        setFormData((prev) => ({
          ...prev,
          ...res.data,
          allow_negative_stock: res.data.allow_negative_stock === '1' || res.data.allow_negative_stock === true,
        }));
      }
      setFeedback({ type: 'success', message: 'Tenant settings saved successfully.' });
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to save settings. Please verify inputs.';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSaving(false);
    }
  };

  const tabs: Array<{ id: SettingsTabId; label: string; icon: React.ComponentType<{ className?: string }> }> = [
    { id: 'company', label: 'Company Profile', icon: Building2 },
    { id: 'finance', label: 'Financial & Localization', icon: DollarSign },
    { id: 'inventory', label: 'Inventory Policies', icon: Package },
    { id: 'documents', label: 'Document Sequences', icon: FileText },
  ];

  return (
    <AppLayout
      title="Settings"
      breadcrumbs={[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }]}
      activeNav="settings"
    >
      <div className="space-y-6 max-w-5xl">
        {/* Navigation Tabs Bar */}
        <div className="border-b border-border bg-card/60 backdrop-blur-sm -mt-6 -mx-6 px-6 pt-3">
          <nav className="flex space-x-2 overflow-x-auto pb-2" aria-label="Settings Navigation Tabs">
            {tabs.map((tab) => {
              const Icon = tab.icon;
              const isActive = activeTab === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setActiveTab(tab.id)}
                  className={`flex items-center gap-2 px-3.5 py-2 text-xs font-semibold rounded-md transition-all whitespace-nowrap ${
                    isActive
                      ? 'bg-primary text-primary-foreground shadow-sm'
                      : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  <span>{tab.label}</span>
                </button>
              );
            })}
          </nav>
        </div>

        {feedback && (
          <Alert variant={feedback.type === 'error' ? 'destructive' : 'default'} className="mt-4">
            {feedback.type === 'error' ? (
              <AlertCircle className="h-4 w-4" />
            ) : (
              <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
            )}
            <AlertDescription className="ml-2">{feedback.message}</AlertDescription>
          </Alert>
        )}

        <form onSubmit={handleSave} className="space-y-6">
          {/* Tab 1: Company Profile */}
          {activeTab === 'company' && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold">Company Identity & Details</CardTitle>
                <CardDescription>
                  These credentials appear directly on customer invoices, supplier purchase orders, and PDF statements.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label htmlFor="company_name">Company Legal Name</Label>
                    <Input
                      id="company_name"
                      value={String(formData.company_name ?? '')}
                      onChange={(e) => handleChange('company_name', e.target.value)}
                      placeholder="e.g. Acme Trading Pvt. Ltd."
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="company_tax_id">Tax / VAT Registration Number</Label>
                    <Input
                      id="company_tax_id"
                      value={String(formData.company_tax_id ?? '')}
                      onChange={(e) => handleChange('company_tax_id', e.target.value)}
                      placeholder="e.g. 109283746"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="company_email">Billing / Official Email</Label>
                    <Input
                      id="company_email"
                      type="email"
                      value={String(formData.company_email ?? '')}
                      onChange={(e) => handleChange('company_email', e.target.value)}
                      placeholder="billing@company.com"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="company_phone">Official Telephone</Label>
                    <Input
                      id="company_phone"
                      value={String(formData.company_phone ?? '')}
                      onChange={(e) => handleChange('company_phone', e.target.value)}
                      placeholder="+1 (555) 019-2834"
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="company_address">Registered Office Address</Label>
                  <textarea
                    id="company_address"
                    rows={3}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    value={String(formData.company_address ?? '')}
                    onChange={(e) => handleChange('company_address', e.target.value)}
                    placeholder="Street, City, Postal Code, Country"
                  />
                </div>
              </CardContent>
            </Card>
          )}

          {/* Tab 2: Financial & Localization */}
          {activeTab === 'finance' && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold">Financial & Localization Configuration</CardTitle>
                <CardDescription>
                  Configure base currency, legal fiscal year boundary, and invoice settlement remittance details.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label htmlFor="currency">Base Currency (ISO 4217)</Label>
                    <Input
                      id="currency"
                      maxLength={3}
                      className="uppercase font-mono"
                      value={String(formData.currency ?? '')}
                      onChange={(e) => handleChange('currency', e.target.value.toUpperCase())}
                      placeholder="USD, NPR, EUR, GBP"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="fiscal_year_start">Fiscal Year Start (MM-DD)</Label>
                    <Input
                      id="fiscal_year_start"
                      className="font-mono"
                      value={String(formData.fiscal_year_start ?? '')}
                      onChange={(e) => handleChange('fiscal_year_start', e.target.value)}
                      placeholder="01-01 or 07-16"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="timezone">Default Timezone</Label>
                    <Input
                      id="timezone"
                      value={String(formData.timezone ?? '')}
                      onChange={(e) => handleChange('timezone', e.target.value)}
                      placeholder="UTC, Asia/Kathmandu, America/New_York"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="date_format">Date Display Format</Label>
                    <select
                      id="date_format"
                      className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                      value={String(formData.date_format ?? 'Y-m-d')}
                      onChange={(e) => handleChange('date_format', e.target.value)}
                    >
                      <option value="Y-m-d">YYYY-MM-DD (2026-11-13)</option>
                      <option value="d/m/Y">DD/MM/YYYY (13/11/2026)</option>
                      <option value="m/d/Y">MM/DD/YYYY (11/13/2026)</option>
                    </select>
                  </div>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="bank_details">Remittance / Bank Account Details</Label>
                  <textarea
                    id="bank_details"
                    rows={3}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    value={String(formData.bank_details ?? '')}
                    onChange={(e) => handleChange('bank_details', e.target.value)}
                    placeholder="Bank Name, Account Holder, Account Number, SWIFT / IBAN / Routing"
                  />
                </div>
              </CardContent>
            </Card>
          )}

          {/* Tab 3: Inventory Policies */}
          {activeTab === 'inventory' && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold">Inventory & Stock Controls</CardTitle>
                <CardDescription>
                  Define stock deduction policies and default shortage notification thresholds.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-start gap-3 rounded-lg border border-border p-4 bg-muted/20">
                  <input
                    id="allow_negative_stock"
                    type="checkbox"
                    className="mt-1 h-4 w-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                    checked={Boolean(formData.allow_negative_stock)}
                    onChange={(e) => handleChange('allow_negative_stock', e.target.checked)}
                  />
                  <div className="space-y-1">
                    <Label htmlFor="allow_negative_stock" className="font-medium cursor-pointer">
                      Allow Negative Stock Balances
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      When enabled, sales invoices and outbound goods delivery can post even if on-hand physical inventory is zero or insufficient. When disabled, transactions without available stock fail with HTTP 400 Insufficient Stock.
                    </p>
                  </div>
                </div>

                <div className="space-y-1.5 max-w-sm">
                  <Label htmlFor="low_stock_threshold_default">Default Low Stock Alert Threshold</Label>
                  <Input
                    id="low_stock_threshold_default"
                    type="number"
                    min="0"
                    value={String(formData.low_stock_threshold_default ?? '10')}
                    onChange={(e) => handleChange('low_stock_threshold_default', e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Products without a custom reorder threshold will trigger SSE and FCM alerts when available units drop below this quantity.
                  </p>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Tab 4: Document Sequences */}
          {activeTab === 'documents' && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-semibold">Document Sequence Prefixes</CardTitle>
                <CardDescription>
                  Prefixes used by the gap-free document numbering engine for statutory and commercial instruments.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label htmlFor="invoice_prefix">Sales Invoice Prefix</Label>
                    <Input
                      id="invoice_prefix"
                      maxLength={10}
                      className="font-mono uppercase"
                      value={String(formData.invoice_prefix ?? 'INV')}
                      onChange={(e) => handleChange('invoice_prefix', e.target.value.toUpperCase())}
                      placeholder="INV"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="bill_prefix">Supplier Bill Prefix</Label>
                    <Input
                      id="bill_prefix"
                      maxLength={10}
                      className="font-mono uppercase"
                      value={String(formData.bill_prefix ?? 'BILL')}
                      onChange={(e) => handleChange('bill_prefix', e.target.value.toUpperCase())}
                      placeholder="BILL"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="quotation_prefix">Sales Quotation Prefix</Label>
                    <Input
                      id="quotation_prefix"
                      maxLength={10}
                      className="font-mono uppercase"
                      value={String(formData.quotation_prefix ?? 'QUO')}
                      onChange={(e) => handleChange('quotation_prefix', e.target.value.toUpperCase())}
                      placeholder="QUO"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="po_prefix">Purchase Order Prefix</Label>
                    <Input
                      id="po_prefix"
                      maxLength={10}
                      className="font-mono uppercase"
                      value={String(formData.po_prefix ?? 'PO')}
                      onChange={(e) => handleChange('po_prefix', e.target.value.toUpperCase())}
                      placeholder="PO"
                    />
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          <div className="flex items-center justify-between pt-2">
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <ShieldAlert className="h-4 w-4" />
              <span>Only authorized Tenant Owners with <code>settings.manage</code> permission can modify operational policies.</span>
            </div>
            <Button type="submit" disabled={saving} className="gap-2">
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
              Save Settings
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
