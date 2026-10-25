# Implementation Roadmap & Phases

> **Agent note:** Work only on the **active phase** (see `Memory.md`). Tick items as they are completed and tested. A phase is finished only when every item AND the exit criteria are met, then wait for the user to approve moving on.
> Legend: `[x]` done · `[ ]` to do · every code item implies its tests.

## Phase 1: Foundation & Multi-Tenancy Infrastructure

- [x] Initialize repo structure (`backend/`, `frontend/`, `docs/`).
- [x] Docker environment: Nginx, PHP-FPM, Composer, PostgreSQL, PgAdmin, Redis, Supervisor, Node.
- [x] Configure Laravel 13 modular monolith: PSR-4 for `Modules\` and `Shared\`, module service provider loading, module route mounting.
- [x] Shared kernel: `ApiResponse`, `ApiException` + `ApiExceptionRenderer`, `EnsureJsonRequest`, `HttpStatus`.
- [x] Two DB connections (`central`, `tenant`) + `UsesTenantConnection` trait + `TenantContext`.
- [x] Central schema: `tenants`, `domains`, `packages`, `subscriptions`, `super_admins`, `global_audit_logs`.
- [x] `IdentifyTenant` middleware: subdomain → tenant → runtime connection switch (cached, rejects unknown/suspended).
- [x] Tenant registration controller + `ProvisionTenantDatabase` job (create DB, migrate, seed roles/settings; CoA seeder deferred to Phase 5).
- [x] `tenants:migrate` command to run tenant migrations for all tenants.
- [x] Frontend scaffold: Inertia + React 19 + TS strict + Tailwind + shadcn/ui, `apiClient`, `ApiError`, app layouts.
- [x] Backend tooling: Pest + Pest Arch, Larastan (level 8), Pint, composer ci script, isolated testing DB.
- [x] Frontend tooling: ESLint, Vitest, npm run ci (Playwright deferred to Phase 7).
- **Exit criteria:** two tenants on two subdomains see isolated data; provisioning is retry-safe; arch + isolation tests pass.

## Phase 2: Authentication & User Provisioning

- [ ] Email/password login, logout, password reset, email verification (Sanctum + Inertia).
- [ ] Login throttling and session handling per tenant.
- [ ] Google OAuth with safe account linking (verified email only).
- [ ] Spatie Laravel Permission: roles (Super Admin, Tenant Owner, Accountant, Warehouse Manager, Sales Rep) and the PRD permission matrix.
- [ ] Tenant user management UI (invite, role assign, deactivate) with package user-limit enforcement.
- [ ] Super Admin authentication on the central domain.
- [ ] Frontend: auth pages, protected routes, permission-aware navigation (`useCan`).
- **Exit criteria:** user of tenant A cannot log into tenant B; every route has a policy test.

## Phase 3: Core Inventory & Contact Management

- [ ] Product catalog: SKU, barcode, category, UoM, cost/sell price (cents), tax category, low-stock threshold.
- [ ] Warehouses; append-only `stock_movements` + `stock_levels`.
- [ ] Manual stock adjustments with reason (ledger posting hook stubbed until Phase 5).
- [ ] Customer & Supplier directories with opening balances, payment terms, credit limit.
- [ ] Reusable `DataTable` (TanStack): server-side sort/filter/paginate, column visibility, sticky header.
- [ ] Low-stock detection, dashboard alerts, SSE stream endpoint and `useEventStream` hook.
- [ ] CSV import for products and contacts (queued).
- **Exit criteria:** stock never goes negative (unless allowed); concurrent adjustments are safe; tables handle 100k rows.

## Phase 4: Purchasing, Sales & Invoicing Engines

- [ ] Gap-free per-tenant document numbering service.
- [ ] Purchase Orders → goods receipt → Supplier Bills with AP balance tracking.
- [ ] Sales Quotations → conversion → Sales Invoices with AR balance tracking.
- [ ] Payments and allocations (receipts and supplier payments), unallocated credit handling.
- [ ] Atomic invoice posting: stock decrement + (stubbed) journal event inside one `DB::transaction(..., 3)`.
- [ ] Document PDFs via Browsershot (invoice, quotation, PO).
- [ ] AR/AP aging buckets on contact statements.
- **Exit criteria:** oversell race test passes; posted documents immutable; invoice flow E2E green.

## Phase 5: Financial Ledger, VAT & Adjustments

- [ ] Chart of Accounts (hierarchy, system accounts protected) and default CoA seeder.
- [ ] General Ledger posting engine (balanced-or-reject, source-linked, reversal-only corrections).
- [ ] Wire Phase 3/4 documents to real journal postings (AR, revenue, output/input VAT, COGS, inventory, AP).
- [ ] Inventory costing (weighted average unless Open Questions say otherwise).
- [ ] Credit Notes and Debit Notes (stock + ledger + balance effects).
- [ ] Fiscal periods with lock/close; manual journals.
- [ ] VAT return calculation by period and rate with document drill-down.
- **Exit criteria:** trial balance always zero-sum; VAT return reconciles to ledger; sub-ledgers reconcile to control accounts.

## Phase 6: Super Admin Panel, Reports & Audit Logs

- [ ] Super Admin dashboard: tenants, packages, subscriptions, feature toggles, suspend/reactivate, provisioning retry.
- [ ] Package limit and feature-flag enforcement middleware.
- [ ] Immutable audit logs (Spatie Activitylog) in tenant DB + central `global_audit_logs`; viewer UI with filters.
- [ ] Reports: P&L, Balance Sheet, Trial Balance, AR/AP Aging, Stock Valuation, Stock Movement.
- [ ] PDF (Browsershot) and Excel (Maatwebsite) exports on the `reports` queue with signed URLs.
- [ ] FCM push helper for low-stock and overdue alerts.
- **Exit criteria:** report totals match ledger; exports are queued and tenant-scoped; audit log cannot be edited.

## Phase 7: Hardening, Docs & Release

- [ ] Dedicated non-superuser PostgreSQL role with CREATEDB-only privilege for tenant provisioning.
- [ ] Security review (OWASP checklist, rate limits, signed URLs, headers).
- [ ] Performance pass (indexes, N+1 audit with Telescope, query budgets).
- [ ] Scribe API docs published; seed/demo tenant.
- [ ] Backups and restore drill; production Docker/Supervisor config; Telescope/Debugbar off in prod.
- [ ] Full Playwright suite for all PRD §5 flows.
- **Exit criteria:** all CI gates green; restore drill succeeded; launch checklist signed off by the user.
