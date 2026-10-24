# Product Requirements Document (PRD): NexusERP

> **Agent note:** This file defines WHAT we are building and for WHOM. If a request conflicts with this file, stop and ask. Build only what the active phase in `Phases.md` requires.

## 1. Executive Summary

NexusERP is a multi-tenant, cloud-based ERP web application for **trading, retail, and wholesale businesses**. It automates inventory control, purchasing, sales, VAT compliance, and double-entry accounting. Every customer company (tenant) gets its own isolated PostgreSQL database.

**Goals**
1. One system for stock, purchasing, sales, and books, with no double entry.
2. Every sale or purchase automatically updates stock and posts balanced journal entries.
3. Bank-grade tenant isolation and a complete audit trail.
4. A premium, fast UI that non-accountants can use.

**Non-goals (v1)**: payroll/HR, manufacturing/BOM, POS hardware integration, bank-feed reconciliation, multi-currency FX revaluation, native mobile apps, dark mode, public marketplace/integrations.

## 2. Target Users & Roles

| Role | Scope | Primary jobs |
|---|---|---|
| **Platform Super Admin** | Central panel only | Manage tenants, packages, subscriptions, feature toggles, system health |
| **Tenant Owner / Admin** | One tenant | Company profile, tax settings, users & roles, warehouses |
| **Accountant / Finance Manager** | One tenant | Invoices, bills, payments, credit/debit notes, VAT returns, ledger, reports |
| **Warehouse Manager** | One tenant | Products, stock adjustments, transfers, low-stock monitoring |
| **Sales Representative** | One tenant | Quotations, sales invoices, customer payments |

### Permission matrix (v1 baseline; enforced via Spatie Permission)

| Capability | Owner | Accountant | Warehouse | Sales |
|---|:-:|:-:|:-:|:-:|
| Manage users/roles/settings | ✅ | ❌ | ❌ | ❌ |
| Products & stock (view) | ✅ | ✅ | ✅ | ✅ |
| Products & stock (create/edit/adjust) | ✅ | ❌ | ✅ | ❌ |
| Customers/Suppliers (manage) | ✅ | ✅ | ❌ | ✅ (customers only) |
| Quotations & sales invoices | ✅ | ✅ | ❌ | ✅ |
| Purchase orders & supplier bills | ✅ | ✅ | ✅ (PO draft only) | ❌ |
| Payments & allocations | ✅ | ✅ | ❌ | ✅ (customer receipts only) |
| Chart of accounts, journals, notes, VAT | ✅ | ✅ | ❌ | ❌ |
| Reports (financial) | ✅ | ✅ | ❌ | ❌ |
| Reports (stock) | ✅ | ✅ | ✅ | ❌ |
| View audit logs | ✅ | ✅ (read) | ❌ | ❌ |

## 3. Functional Modules & Requirements

Priority: **P0** = required for v1 launch, **P1** = should have, **P2** = later.

### 3.1 Super Admin & Tenant Provisioning
- P0: Tenant registration (company name, subdomain, owner email) creates a central `tenants` row and dispatches a queued job that creates the tenant database, runs tenant migrations, seeds roles and a default Chart of Accounts, and creates the owner user.
- P0: Subscription **packages** with limits (users, warehouses, invoices/month) and feature flags; limits are enforced server-side.
- P0: Suspend/reactivate tenant; suspended tenants see a locked-state page.
- P1: Global feature toggles; impersonate-tenant (read-only, fully audited).
- **Acceptance:** provisioning is idempotent and retry-safe; a failed job leaves the tenant in `provisioning_failed` with a visible reason and a retry action.

### 3.2 Authentication & Authorization
- P0: Email/password login, password reset, email verification (Sanctum + Inertia session auth).
- P0: Google OAuth with **automatic account linking** by verified email (never link on an unverified email).
- P0: RBAC via Spatie; permissions checked in Policies/Form Requests, never only in the UI.
- P1: Two-factor authentication; session/device management; login throttling and lockout.
- **Acceptance:** a user from tenant A can never authenticate on tenant B's subdomain.

### 3.3 Contact Management (Customers & Suppliers)
- P0: CRUD with code, legal name, tax/VAT number, addresses, payment terms, credit limit, opening balance.
- P0: Live balance per contact derived from ledger (AR/AP), statement view.
- P0: AR/AP aging buckets: Current, 1–30, 31–60, 61–90, 90+.
- P1: Import from CSV; merge duplicates.

### 3.4 Inventory & Stock
- P0: Product catalog: SKU (unique), barcode, name, category, unit of measure, cost price, selling price, tax category, low-stock threshold, active flag.
- P0: Multi-warehouse stock; every change is a row in an append-only **stock movements** table (type, qty, warehouse, source document, user, timestamp). On-hand = sum of movements.
- P0: Manual stock adjustments with mandatory reason; adjustments post a journal entry.
- P0: Low-stock alerts on dashboard and via notification when on-hand falls to/below threshold.
- P1: Warehouse-to-warehouse transfers; stock take; batch/serial tracking (P2).
- **Acceptance:** stock can never go negative unless the tenant setting `allow_negative_stock` is on; concurrent invoices cannot oversell (row-level locking).

### 3.5 Purchasing & Payables
- P0: Purchase Orders (draft → approved → partially received → received → closed/cancelled).
- P0: Goods receipt increases stock; Supplier Bills post AP and input VAT.
- P0: Payments with allocation across one or many bills; unallocated payments become supplier credit.
- **Acceptance:** a posted bill is immutable; corrections are made with Debit Notes.

### 3.6 Sales & Receivables
- P0: Quotations (draft → sent → accepted/rejected/expired) convertible to invoices.
- P0: Sales Invoices (draft → posted → partially paid → paid → void via credit note). Posting atomically decrements stock, posts AR, revenue, output VAT, and COGS.
- P0: Customer receipts with allocation; unallocated receipts become customer credit.
- P0: Sequential, gap-free, per-tenant document numbers (e.g., `INV-2026-000123`).
- P1: Recurring invoices; payment reminders.

### 3.7 Financial & Tax Accounting
- P0: Chart of Accounts (hierarchical; types: asset, liability, equity, income, expense; system accounts protected).
- P0: General Ledger double-entry engine: every journal balances (debits = credits) or it is rejected. Source-linked, immutable once posted; reversals only.
- P0: Credit Notes (sales returns) and Debit Notes (purchase returns) with stock and ledger effects.
- P0: VAT return: output VAT − input VAT per period, by rate, with drill-down to documents.
- P1: Fiscal periods with lock/close; manual journals with approval.

### 3.8 Reports & Audit Logs
- P0: Profit & Loss, Balance Sheet, Trial Balance, AR/AP Aging, Stock Valuation, Stock Movement, VAT Return.
- P0: Export to PDF (Browsershot) and Excel (Maatwebsite).
- P0: Immutable audit log (Spatie Activitylog): who, what, before/after, IP, timestamp. Central `global_audit_logs` for super-admin actions.
- P1: Scheduled report emails.

### 3.9 Cross-cutting
- P0: Real-time dashboard updates via SSE (stock alerts, new invoice totals).
- P0: Global search, sortable/filterable/paginated tables (TanStack Table), saved column visibility.
- P1: FCM push notifications for low-stock and overdue invoices.

## 4. Non-Functional Requirements

| Area | Requirement |
|---|---|
| **Security** | Database-per-tenant isolation; OWASP Top 10 mitigations; CSRF, rate limiting, signed URLs for downloads; secrets only in `.env`; all uploads validated |
| **Data integrity** | Integer money (cents); every mutation in a DB transaction with deadlock retry; posted documents immutable |
| **Performance** | List pages < 300 ms server time at 100k rows (indexed, server-side pagination); report generation > 5 s runs on queue |
| **Reliability** | Idempotent jobs; tenant backups nightly; restore tested |
| **Accessibility** | WCAG 2.1 AA; full keyboard navigation; visible focus rings |
| **Observability** | Telescope/Debugbar in dev only; structured logs with `tenant_id` and `request_id` |
| **Testing** | Pest (unit/feature/arch), Vitest + RTL, Playwright E2E for critical flows; no phase is done without tests |
| **Compliance** | Audit trail retained ≥ 7 years; per-tenant data export |

## 5. Key User Flows (must be covered by E2E tests)
1. Tenant signs up → database provisioned → owner logs in.
2. Warehouse manager adds product → receives stock via purchase → sees on-hand.
3. Sales rep creates invoice → stock decrements → AR and journal post → PDF downloaded.
4. Customer pays → receipt allocated → invoice marked paid → AR ages correctly.
5. Accountant runs VAT return and P&L; totals match the ledger.

## 6. Success Metrics
- 100% of posted journals balance (enforced + tested).
- Zero cross-tenant data access in security tests.
- Invoice creation (10 lines) < 1 s p95.

## 7. Open Questions (answer before the relevant phase starts)
- Which VAT regime(s) and rates at launch (single rate vs multiple, inclusive vs exclusive pricing)?
- Inventory costing method: weighted average (default) or FIFO?
- Base currency per tenant only, or multi-currency documents in v1?
- Subscription billing handled in-app or by an external provider?
