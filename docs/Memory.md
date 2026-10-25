# Project Memory & Progress State

> **Agent protocol:** READ this file at the start of every session. UPDATE it at the end of every task (and before the user switches chat/tool). Keep it short and factual. Never paste large code here; reference file paths instead. Keep the Session Log to the latest 10 entries; roll older ones into one summary line.

## Project Snapshot

- **Product:** NexusERP, multi-tenant SaaS ERP for trading/retail/wholesale.
- **Stack:** Laravel 13 (PHP 8.4, modular monolith) · Inertia + React 19 + TypeScript · Tailwind + shadcn/ui · PostgreSQL (database-per-tenant) · Redis + Horizon · Docker.
- **Source-of-truth docs:** `docs/PRD.md`, `docs/Architecture.md`, `docs/Rules.md`, `docs/Phases.md`, `docs/Design.md`.
- **Tooling:** vibe coding with agy CLI.

## Current Status

- **Active Phase:** Phase 1: Foundation & Multi-Tenancy Infrastructure
- **Last Completed Task:** Phase 1 Exit Criterion: Two tenants on two subdomains see isolated data (`tests/Feature/Tenancy/Phase1ExitIsolationTest.php`) — provisioned alpha and beta via `ProvisionTenantDatabase::dispatchSync`, registered test-only route behind `identify.tenant` returning slug, settings_count, users_count, marker, and users. Proved data isolation: alpha marker only visible via alpha host, beta marker only visible via beta host, alpha owner not visible from beta host and vice versa. Suspending beta returns 403 `TENANT_SUSPENDED` while alpha continues returning its own data. `TenantContext::get()` is null after every request. All 72 Pest tests pass (312 assertions). Sentinel check confirmed development database `pgsql_central` remained untouched. (Note: Phase 1 exit criterion tick in `docs/Phases.md` follows merge to `develop` and `composer ci` passing on `develop`).
- **Current Focus:** Merge `test/phase1-exit-isolation` into `develop`, verify `composer ci`, then Phase 2 (Authentication & User Provisioning).
- **Working branch:** `test/phase1-exit-isolation`

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
| 10  | Chart of Accounts seeder deferred to Phase 5                                                      | CoA structure requires the full Accounting module (accounts, hierarchies, system flags); premature seeding would couple provisioning to unbuilt code |
| 11  | Separate `pgsql_testing` database configured via `phpunit.xml` (`DB_DATABASE` / `DB_CENTRAL_DATABASE`) | Keeps test execution completely isolated from development database |
| 12  | Larastan level 8 + Pest Arch tests enforcing strict typing, no DB in controllers, module boundary isolation, no float in money | Static and architectural guarantees enforced in CI |
| 13  | Provisioning database user requires only `CREATEDB` privilege in production (tied to Phase 7 deployment checklist) | Prevents superuser exposure and enforces least privilege; database creation succeeds without granting full administrative privileges; verification of dedicated non-superuser role added to Phase 7 release checklist |

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

1. Merge `test/phase1-exit-isolation` to `develop`, run `composer ci`, and tick Phase 1 exit criteria in `docs/Phases.md`.
2. Phase 2: Authentication & User Provisioning.

## Key Files Touched (latest)

- `backend/tests/Feature/Tenancy/Phase1ExitIsolationTest.php`

## Session Log (newest first)

- `2026-10-25`: Phase 1 exit criteria isolation test: Created `tests/Feature/Tenancy/Phase1ExitIsolationTest.php` proving Phase 1 exit criterion ("two tenants on two subdomains see isolated data") using real HTTP requests. Provisioned `alpha` and `beta` via `ProvisionTenantDatabase::dispatchSync`. Registered test-only route behind `identify.tenant` returning `slug`, `settings_count`, `users_count`, `marker`, and `users`. Inserted `isolation.marker` = `alpha` into `tenant_alpha` only: confirmed visible on `alpha.nexuserp.test` (settings_count=7, marker=alpha) and invisible on `beta.nexuserp.test` (settings_count=6, marker=null). Proved reverse by inserting `isolation.marker` = `beta` into `tenant_beta` only. Verified owner user isolation (`owner@alpha.nexuserp.test` invisible from beta host and vice versa). Suspended beta: confirmed `beta.nexuserp.test` returns 403 `TENANT_SUSPENDED` while `alpha.nexuserp.test` continues serving its own data with 200 OK. Confirmed `TenantContext::get()` is null after every request. Verified sentinel row in `pgsql_central` survived untouched across full test execution. 72 Pest tests passed (312 assertions). Phase 1 exit criterion in `docs/Phases.md` kept unticked until branch is merged to `develop` and `composer ci` passes on `develop`.

- `2026-10-25`: Frontend scaffold & tooling: Set up Inertia with React 19 and TypeScript (`strict: true`, no `any`, no `@ts-ignore`) in `frontend/`. Mapped Design.md §2 light mode color tokens into `app.css` via Tailwind `@theme`. Created Architecture §4 structure (`components/ui/`, `features/`, `layouts/`, `shared/`). Implemented typed `apiClient` with envelope parsing and `ApiError` propagation (code, message, errors, status, field helpers). Built `AppLayout` (256px royal blue sidebar, topbar, 1440px container), `AuthLayout`, `AdminLayout`, and `PlaceholderDashboard`. Wired `npm run ci` to ESLint, `tsc --noEmit`, and Vitest. Added 6 unit tests for `apiClient`/`ApiError` and 1 integration test for `PlaceholderDashboard` (all 7 passed). Playwright deferred to Phase 7 unticked. Zero backend files modified.

- `2026-10-25`: `tenants:migrate` command: Created `php artisan tenants:migrate` running module tenant migrations (`Modules/*/Database/Migrations`) against active tenants via `TenantManager::switchTo()`. Skips inactive tenants (`suspended`, `provisioning`, `provisioning_failed`) with console output. Non-zero exit on failure while continuing to remaining tenants. Supports `--tenant=<slug>`, disables `--fresh`, requires `--force` in production. Added 6 Pest tests covering multi-tenant migration, skipping, error resilience, and slug filtering. `composer ci` passed with 71 tests (260 assertions). Ticked Phase 1 item.

- `2026-10-25`: Merged `feat/tenant-provisioning-job` and `chore/tooling-quality-gates` into `develop`. Verified tenant provisioning pipeline and database isolation via sentinel-row experiment and dedicated `DatabaseIsolationTest`. `composer ci` passed completely with 65 tests (221 assertions), 0 Pint issues, 0 PHPStan errors. Ticked backend tooling and tenant provisioning items in `docs/Phases.md`.
- `2026-10-24`: Tooling & Quality Gates: Separated testing database via `phpunit.xml` (`DB_DATABASE=pgsql_testing` / `DB_CENTRAL_DATABASE=pgsql_testing`) ensuring zero contamination of development database. Installed Larastan v3.13 / PHPStan 2.3 at level 8 (0 errors). Added Pest Arch tests (strict types, no `DB::` in controllers, module model encapsulation, no float in money classes). Added `composer ci` script (Pint --test, Larastan level 8, Pest 64 tests). Added frontend `npm run ci` script (gracefully reports skipped uninstalled tools). Added GitHub Actions workflow (`.github/workflows/ci.yml`) and local runner (`./scripts/ci.sh`). Ticked Phase 1 tooling item.
- `2026-10-24`: Tenant provisioning: Created `Modules/Tenancy/` module — `POST /api/v1/tenants` (Form Request: name/slug/owner_email, rate limited at 5 req/min, creates central `tenants` + `domains`, dispatches `ProvisionTenantDatabase`). Job is idempotent+retry-safe: creates PostgreSQL DB via PDO if absent, runs tenant migrations (`migrate --database=tenant`), seeds 5 roles + 6 settings via `insertOrIgnore`, creates owner user with owner role, marks tenant `active`. On failure: sets `provisioning_failed` + error message, rethrows. Chart of Accounts seeder deferred to Phase 5. Added midway migration failure retry test. 11 Pest tests (60 total, 208 assertions). Pint clean.
- `2026-10-24`: IdentifyTenant middleware: Created `Shared/Http/Middleware/IdentifyTenant.php` resolving subdomains from request host against central `tenants`/`domains`. Cached in Redis (60s TTL, `tenant:prefixed` key with invalidation). Enforces status rules (404 via `ApiException` for API / Laravel 404 for web, 403 `TENANT_SUSPENDED`, 403 `TENANT_UNAVAILABLE`). Calls `TenantManager::switchTo()` on valid tenant, resets context in `terminate()`. Registered as alias in `bootstrap/app.php`. Added 11 Pest tests (49 total passed, 152 assertions). Pint passed.
- `2026-10-24`: Central schema: Created migrations in `backend/database/migrations/central/` for `tenants` (ULID id, no soft deletes), `domains` (cascade delete FK), `packages` (BIGINT `price_cents`, JSONB `features`), `subscriptions`, `super_admins`, and `global_audit_logs` (append-only, no `updated_at`). All FK and status columns indexed. Added 15 Pest tests (38 total passed, 111 assertions) verifying tables, uniqueness constraints, cascade deletion, and BIGINT integrity. Pint passed.
