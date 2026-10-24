# Project Memory & Progress State

> **Agent protocol:** READ this file at the start of every session. UPDATE it at the end of every task (and before the user switches chat/tool). Keep it short and factual. Never paste large code here; reference file paths instead. Keep the Session Log to the latest 10 entries; roll older ones into one summary line.

## Project Snapshot

- **Product:** NexusERP, multi-tenant SaaS ERP for trading/retail/wholesale.
- **Stack:** Laravel 13 (PHP 8.4, modular monolith) · Inertia + React 19 + TypeScript · Tailwind + shadcn/ui · PostgreSQL (database-per-tenant) · Redis + Horizon · Docker.
- **Source-of-truth docs:** `docs/PRD.md`, `docs/Architecture.md`, `docs/Rules.md`, `docs/Phases.md`, `docs/Design.md`.
- **Tooling:** vibe coding with agy CLI.

## Current Status

- **Active Phase:** Phase 1: Foundation & Multi-Tenancy Infrastructure
- **Last Completed Task:** Implemented `IdentifyTenant` middleware with subdomain resolution, central lookup, Redis caching (60s TTL), status gating (403 `TENANT_SUSPENDED` / `TENANT_UNAVAILABLE`), `TenantManager::switchTo()`, and post-request context resetting via `terminate()`. Verified with 11 Pest tests (49 total passed, 152 assertions).
- **Current Focus:** Tenant registration controller + `ProvisionTenantDatabase` job (create DB, migrate, seed roles/CoA/settings).
- **Working branch:** `feat/identify-tenant-middleware`

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
| 8   | Tenants use ULID IDs and no soft deletes                                                          | Time-ordered B-tree indexing, URL-safe identifiers (security comes from authorization); lifecycle managed via `status` without unique constraint collisions |
| 9   | `IdentifyTenant` registered as alias only; resets context in `terminate()`                        | Explicit per-route tenancy assignment without leaking state across persistent cycles |

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

1. Build Tenant Registration Controller and automated database creator job (`ProvisionTenantDatabase`).
2. Add `tenants:migrate` command and tenant-isolation feature tests.
3. Frontend scaffold (Inertia + React 19 + TypeScript + Tailwind + shadcn/ui).

## Key Files Touched (latest)

- `backend/Shared/Http/Middleware/IdentifyTenant.php`
- `backend/bootstrap/app.php`
- `backend/tests/Feature/Shared/IdentifyTenantTest.php`

## Session Log (newest first)

- `2026-10-24`: IdentifyTenant middleware: Created `Shared/Http/Middleware/IdentifyTenant.php` resolving subdomains from request host against central `tenants`/`domains`. Cached in Redis (60s TTL, `tenant:prefixed` key with invalidation). Enforces status rules (404 via `ApiException` for API / Laravel 404 for web, 403 `TENANT_SUSPENDED`, 403 `TENANT_UNAVAILABLE`). Calls `TenantManager::switchTo()` on valid tenant, resets context in `terminate()`. Registered as alias in `bootstrap/app.php`. Added 11 Pest tests (49 total passed, 152 assertions). Pint passed.
- `2026-10-24`: Central schema: Created migrations in `backend/database/migrations/central/` for `tenants` (ULID id, no soft deletes), `domains` (cascade delete FK), `packages` (BIGINT `price_cents`, JSONB `features`), `subscriptions`, `super_admins`, and `global_audit_logs` (append-only, no `updated_at`). All FK and status columns indexed. Added 15 Pest tests (38 total passed, 111 assertions) verifying tables, uniqueness constraints, cascade deletion, and BIGINT integrity. Pint passed.
