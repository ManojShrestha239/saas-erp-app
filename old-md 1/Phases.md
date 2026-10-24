# Implementation Roadmap & Phases

## Phase 1: Foundation & Multi-Tenancy Infrastructure

- [ ] Set up Docker development environment (PostgreSQL, Redis, Nginx, PHP).
- [ ] Configure Laravel modular monolith structure (`Modules/` and `Shared/`).
- [ ] Implement Central Database schema (`tenants`, `packages`, `subscriptions`).
- [ ] Build `IdentifyTenant` middleware for dynamic database connection switching.

## Phase 2: Authentication & User Provisioning

- [ ] Implement email/password authentication via Laravel Sanctum / Inertia.
- [ ] Integrate Google OAuth provider with robust account linking logic.
- [ ] Configure Spatie Laravel Permission for role-based access control (Super Admin, Tenant Owner, Accountant, Warehouse Manager).

## Phase 3: Core Inventories & Contact Management

- [ ] Build Product Catalog module (SKU, pricing, stock quantities, low-stock threshold).
- [ ] Build Customer & Supplier contact directories with opening balances.
- [ ] Implement real-time stock level tracking and low-stock dashboard alerts.

## Phase 4: Purchasing, Sales, & Invoicing Engines

- [ ] Implement Purchase Orders and Supplier Bills with AP balance tracking.
- [ ] Implement Sales Quotations and Sales Invoices with AR balance tracking.
- [ ] Tie invoice creation to atomic stock decrement and journal entry posting.

## Phase 5: Financial Ledger, VAT, & Adjustments

- [ ] Build Chart of Accounts and General Ledger double-entry posting engine.
- [ ] Implement Credit Notes and Debit Notes management.
- [ ] Build VAT return calculation and reporting module.

## Phase 6: Super Admin Panel, Reports, & Audit Logs

- [ ] Build Super Admin dashboard for managing tenants, packages, and feature toggles.
- [ ] Implement immutable audit logs using Spatie Activitylog.
- [ ] Build Financial Reports (P&L, AR/AP Aging, Stock Valuation) and PDF export tools.
