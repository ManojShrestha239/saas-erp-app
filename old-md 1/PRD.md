# Project Requirements Document (PRD): Enterprise SaaS ERP

## 1. Executive Summary

NexusERP is a multi-tenant, cloud-based Enterprise Resource Planning (ERP) web application designed for trading, retail, and wholesale businesses. It automates inventory control, purchasing, sales, VAT tax compliance, and double-entry general ledger accounting within a secure database-per-tenant architecture.

## 2. Target Users

- **Platform Super Admin:** Manages SaaS subscriptions, pricing packages, tenant provisioning, system health, and global feature toggles.
- **Tenant Owner / Admin:** Configures company profile, tax settings, user roles, and warehouse locations.
- **Accountant / Finance Manager:** Manages invoices, purchase bills, payments, credit/debit notes, VAT returns, and general ledger reports.
- **Inventory / Warehouse Manager:** Tracks stock levels, manages products, handles stock adjustments, and monitors low-stock alerts.
- **Sales Representative:** Generates quotations, sales invoices, and processes customer payments.

## 3. Core Functional Modules

1. **Super Admin & Tenant Provisioning Module:** Tenant registration, automated database provisioning, subscription package gating, and global feature toggles.
2. **Authentication & Authorization Module:** Secure email/password login and Google OAuth login with automatic account linking. Role-Based Access Control (RBAC) via Spatie.
3. **Contact Management Module:** Customer and supplier directories, dynamic ledger balances, and AR/AP aging buckets.
4. **Inventory & Stock Module:** Product catalog (SKUs, barcodes, pricing), multi-warehouse support, real-time stock updates, manual adjustments, and automated low-stock alerts.
5. **Purchasing & Payables Module:** Purchase orders, supplier bills, accounts payable tracking, and payment allocations.
6. **Sales & Receivables Module:** Sales invoices, quotations, customer receivables tracking, and payment allocations.
7. **Financial & Tax Accounting Module:** Chart of Accounts, General Ledger journal postings, Credit Notes, Debit Notes, and VAT return calculations.
8. **Reports & Audit Logs Module:** Profit & Loss statements, stock valuation, exportable financial summaries, and immutable audit logs.
