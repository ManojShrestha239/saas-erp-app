# Technical Architecture Document

## 1. Technology Stack

- **Backend:** Laravel (Modular Monolith pattern) with PHP 8.4 strict typing.
- **Frontend:** Inertia.js + React 19, TypeScript, Tailwind CSS, and shadcn/ui primitives.
- **Database:** PostgreSQL (Database-per-tenant strategy for maximum data isolation and security).
- **Caching & Queues:** Redis with Laravel Horizon for background job processing (tenant provisioning, notifications, report generation).
- **Environment:** Fully containerized via Docker (Nginx, PHP-FPM, PostgreSQL, Redis, Node).

## 2. Multi-Tenancy Architecture (Database-per-Tenant)

- **Central Database:** Stores global entities (`tenants`, `packages`, `subscriptions`, `super_admins`, `global_audit_logs`).
- **Tenant Databases:** Each active tenant has an isolated PostgreSQL database (e.g., `tenant_acme_corp`) containing their transactional records (invoices, products, ledger entries).
- **Middleware Resolution:** `IdentifyTenant.php` inspects the subdomain on incoming requests, queries the central registry, and switches Laravel's default runtime database connection dynamically.

## 3. Directory Structure

- **Backend:** Modules live in `backend/Modules/{ModuleName}/` (containing Database, DTOs, Http, Models, Routes, Services). Shared utilities reside in `backend/Shared/`.
- **Frontend:** Feature components live in `frontend/src/features/{ModuleName}/` (containing api, components, hooks, pages, types, utils). Reusable UI primitives reside in `frontend/src/components/ui/`.
