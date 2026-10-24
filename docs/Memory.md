# Project Memory & Progress State

> **Agent protocol:** READ this file at the start of every session. UPDATE it at the end of every task (and before the user switches chat/tool). Keep it short and factual. Never paste large code here; reference file paths instead. Keep the Session Log to the latest 10 entries; roll older ones into one summary line.

## Project Snapshot

- **Product:** NexusERP, multi-tenant SaaS ERP for trading/retail/wholesale.
- **Stack:** Laravel 13 (PHP 8.4, modular monolith) · Inertia + React 19 + TypeScript · Tailwind + shadcn/ui · PostgreSQL (database-per-tenant) · Redis + Horizon · Docker.
- **Source-of-truth docs:** `docs/PRD.md`, `docs/Architecture.md`, `docs/Rules.md`, `docs/Phases.md`, `docs/Design.md`.
- **Tooling:** vibe coding with agy CLI.

## Current Status

- **Active Phase:** Phase 1: Foundation & Multi-Tenancy Infrastructure
- **Last Completed Task:** Configured named `central` and `tenant` database connections in `config/database.php`, built `UsesTenantConnection` trait, `TenantContext` readonly singleton, and `TenantManager` with purge/reconnect switching. Verified with 5 Pest tests (23 total passed, 84 assertions).
- **Current Focus:** Central schema migrations (`tenants`, `domains`, `packages`, `subscriptions`, `super_admins`, `global_audit_logs`).
- **Working branch:** `feat/tenancy-db-connections`

## Architectural Decisions (Decision Log)

| #   | Decision                                                                                          | Reason                                     |
| --- | ------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| 1   | Database-per-tenant on PostgreSQL, connection switched dynamically by `IdentifyTenant` middleware | Maximum isolation and security             |
| 2   | Money stored as integers (`integer('total_amount_cents')` → use BIGINT for new columns)           | No floating-point errors                   |
| 3   | Hybrid data flow: Inertia renders pages; typed API (`/api/v1`) for interactive data and mutations | Keeps `features/*/api` contract + SPA feel |
| 4   | Two named connections: `central` and `tenant`; no reliance on default connection                  | Prevents cross-tenant leakage              |
| 5   | Append-only stock movements + cached stock levels, updated in the same transaction                | Auditability + read speed                  |
| 6   | Automated module discovery via `SharedServiceProvider` and `ModuleServiceProvider` base class     | Zero-config module registration            |
| 7   | Central exception rendering via `ApiExceptionRenderer` in `bootstrap/app.php` and strict envelope | Uniform API contract without ad hoc errors |

## Conventions Locked In

- Namespaces: `Modules\{Name}\…`, `Shared\…`. Module folders as in `Architecture.md` §3.
- API envelope: `{ success, message, data, meta | errors, code }`.
- Document numbers: `PREFIX-YYYY-000000`, gap-free per tenant.
- Branch/commit style: Conventional Commits.

## Pending Bugs & Blockers

- None currently.

## Open Questions (need user's answer)

- VAT regime and rates at launch? Inclusive or exclusive pricing?
- Costing method: weighted average (assumed) or FIFO?
- Multi-currency in v1?

## Next Steps

1. Create central schema (`tenants`, `domains`, `packages`, `subscriptions`, `super_admins`, `global_audit_logs`).
2. Implement `IdentifyTenant` middleware and tenant connection switching tests.
3. Build Tenant Registration Controller and automated database creator job (`ProvisionTenantDatabase`).
4. Add `tenants:migrate` command and tenant-isolation feature tests.

## Key Files Touched (latest)

- `backend/config/database.php`
- `backend/.env.example`
- `backend/.env`
- `backend/phpunit.xml`
- `backend/Shared/Tenancy/UsesTenantConnection.php`
- `backend/Shared/Tenancy/TenantContext.php`
- `backend/Shared/Tenancy/TenantManager.php`
- `backend/Shared/Providers/SharedServiceProvider.php`
- `backend/tests/Feature/Shared/TenancyDbConnectionsTest.php`

## Session Log (newest first)

- `2026-10-24`: Tenancy DB connections & context: Configured central (pgsql_central) and tenant (dynamic) named PostgreSQL connections. Implemented `UsesTenantConnection` trait, `TenantContext` readonly singleton value object, and `TenantManager` with purge/reconnect logic. Added 5 Pest tests (23 total passed, 84 assertions) verifying connection existence, switching, model connection reporting, and isolation between switches. Pint passed.
