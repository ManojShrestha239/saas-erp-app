# Project Memory & Progress State

> **Agent protocol:** READ this file at the start of every session. UPDATE it at the end of every task (and before the user switches chat/tool). Keep it short and factual. Never paste large code here; reference file paths instead. Keep the Session Log to the latest 10 entries; roll older ones into one summary line.

## Project Snapshot

- **Product:** NexusERP, multi-tenant SaaS ERP for trading/retail/wholesale.
- **Stack:** Laravel 13 (PHP 8.4, modular monolith) · Inertia + React 19 + TypeScript · Tailwind + shadcn/ui · PostgreSQL (database-per-tenant) · Redis + Horizon · Docker.
- **Source-of-truth docs:** `docs/PRD.md`, `docs/Architecture.md`, `docs/Rules.md`, `docs/Phases.md`, `docs/Design.md`.
- **Tooling:** vibe coding with agy CLI.

## Current Status

- **Active Phase:** Phase 1: Foundation & Multi-Tenancy Infrastructure
- **Last Completed Task:** Built Shared kernel (`HttpStatus`, `ApiResponse`, `ApiException`, `ApiExceptionRenderer`, `EnsureJsonRequest`) with Pest feature tests covering envelope responses, validation, domain errors, and 406/415 negotiation cases.
- **Current Focus:** Implement Tenancy foundations (`UsesTenantConnection` trait, `TenantContext`, `TenantManager`).
- **Working branch:** `feat/shared-kernel-api-responses`

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

1. Implement Tenancy connections and context (`UsesTenantConnection` trait, `TenantContext`, `TenantManager`).
2. Create central schema (`tenants`, `domains`, `packages`, `subscriptions`, `super_admins`, `global_audit_logs`).
3. Implement `IdentifyTenant` middleware and tenant connection switching tests.
4. Build Tenant Registration Controller and automated database creator job (`ProvisionTenantDatabase`).

## Key Files Touched (latest)

- `backend/Shared/Utils/HttpStatus.php`
- `backend/Shared/Responses/ApiResponse.php`
- `backend/Shared/Exceptions/ApiException.php`
- `backend/Shared/Exceptions/ApiExceptionRenderer.php`
- `backend/Shared/Http/Middleware/EnsureJsonRequest.php`
- `backend/bootstrap/app.php`
- `backend/routes/api.php`
- `backend/tests/Pest.php`
- `backend/tests/Feature/Shared/ApiResponseTest.php`
- `backend/tests/Feature/Shared/EnsureJsonRequestTest.php`

## Session Log (newest first)

- `2026-10-24`: Shared kernel: Implemented `HttpStatus` backed enum, `ApiResponse` strict envelope, `ApiException` base, `ApiExceptionRenderer` registered in `bootstrap/app.php`, and `EnsureJsonRequest` middleware. Added 15 Pest feature tests (17 total tests passed, 47 assertions) covering success, validation, domain error, 406, and 415 scenarios. Pint passed.
- `2026-10-24`: Scaffolding, Docker & Pest setup: Created Docker Compose with PHP 8.4-FPM, Nginx, PostgreSQL 17, Redis 7, PgAdmin, and Node 22. Configured Laravel 13 modular monolith autoloading for `Modules\` and `Shared\`. Added `central` and `tenant` database configurations. All containers healthy; Pint passed; installed `pestphp/pest` (^4.7) and `pestphp/pest-plugin-laravel` (^4.1); ran Pest test suite (2 tests, 2 passed, 2 assertions).
