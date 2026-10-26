# Project Memory & Progress State

> **Agent protocol:** READ this file at the start of every session. UPDATE it at the end of every task (and before the user switches chat/tool). Keep it short and factual. Never paste large code here; reference file paths instead. Keep the Session Log to the latest 10 entries; roll older ones into one summary line.

## Project Snapshot

- **Product:** NexusERP, multi-tenant SaaS ERP for trading/retail/wholesale.
- **Stack:** Laravel 13 (PHP 8.4, modular monolith) · Inertia + React 19 + TypeScript · Tailwind + shadcn/ui · PostgreSQL (database-per-tenant) · Redis + Horizon · Docker.
- **Source-of-truth docs:** `docs/PRD.md`, `docs/Architecture.md`, `docs/Rules.md`, `docs/Phases.md`, `docs/Design.md`.
- **Tooling:** vibe coding with agy CLI.

## Current Status

- **Active Phase:** Phase 2: Authentication & User Provisioning
- **Last Completed Task:** `feat/password-reset-verification`: Implemented password reset and email verification for tenants. Created `POST /api/v1/auth/password/email` (anti-enumeration with identical response, throttled per tenant/IP), `POST /api/v1/auth/password/reset` (uses shared `PasswordRule` with 12+ chars, 1 letter, 1 number; single-use hashed token; revokes active sessions; does not auto-login), `POST /api/v1/auth/email/verify` (sets `email_verified_at`, single-use hashed token), and `POST /api/v1/auth/email/resend` (throttled per tenant/IP). Hashed tokens stored on tenant connection (`database/migrations/tenant/`). Queued jobs `SendPasswordResetEmail` and `SendEmailVerificationEmail` carry tenant context. Enforced email verification requirement on login when configured. 102 Pest tests pass (440 assertions), 0 Pint issues, 0 PHPStan errors at Level 8, `composer ci` green.
- **Current Focus:** User review of `feat/password-reset-verification`, followed by approval to commit and merge to `develop`.
- **Working branch:** `feat/password-reset-verification`

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
| 14  | Tenant-scoped session auth: host-only cookie (`SESSION_DOMAIN=null`), `web` guard resolves `tenant_users` via `UsesTenantConnection`, `tenant_id` session binding with `EnsureTenantSession` rejecting cross-tenant sessions | Defense-in-depth against cross-tenant session hijacking; browser enforces origin separation; middleware guarantees session validity across subdomains |
| 15  | Password reset tokens and email verification tokens hashed (SHA-256) in tenant DB; defaults: 60-minute token expiry, email verification required before login for tenant users (`auth.require_email_verification`), password reset revokes all active sessions across the session driver store, and mail is logged to file in development (`MAIL_MAILER=log`). | High entropy single-use tokens prevent token theft and replay; cross-device session destruction terminates active access upon credential changes; development file logging prevents accidental dispatch. |

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

1. Await user review and approval to commit and merge `feat/auth-email-password`.
2. Continue Phase 2: Password reset & email verification or Google OAuth / Spatie RBAC.

## Key Files Touched (latest)

- `backend/Modules/Auth/Models/TenantUser.php`
- `backend/Modules/Auth/Http/Controllers/AuthController.php`
- `backend/Modules/Auth/Http/Requests/LoginRequest.php`
- `backend/Modules/Auth/Http/Resources/UserResource.php`
- `backend/Modules/Auth/Http/Middleware/EnsureTenantSession.php`
- `backend/Modules/Auth/Providers/AuthServiceProvider.php`
- `backend/Modules/Auth/Routes/api.php`
- `backend/config/auth.php`
- `backend/config/session.php`
- `backend/phpstan.neon`
- `backend/tests/Feature/Auth/LoginTest.php`

## Session Log (newest first)

- `2026-10-25`: `feat/password-reset-verification`: Implemented password reset and email verification for tenant users. Added `POST /api/v1/auth/password/email` with generic response (anti-enumeration) and tenant/IP throttling (`password_reset:{tenant_id}:{ip}`). Created `POST /api/v1/auth/password/reset` validating via `Shared\Rules\PasswordRule` (min 12 chars, letters, numbers), single-use hashed token deletion, session invalidation via `auth_password_hash` check in `EnsureTenantSession`, and no auto-login. Added `POST /api/v1/auth/email/verify` setting `email_verified_at` and `POST /api/v1/auth/email/resend` with throttling (`email_verify_resend:{tenant_id}:{ip}`). Added tenant migrations `2026_10_25_000001_create_tenant_password_reset_tokens_table.php` and `2026_10_25_000002_create_tenant_email_verification_tokens_table.php` in `backend/database/migrations/tenant/` and wired `ProvisionTenantDatabase` and `TenantsMigrateCommand` to execute them. Created queued jobs `SendPasswordResetEmail` and `SendEmailVerificationEmail` carrying tenant context and mailables `ResetPasswordMail` and `VerifyEmailMail`. Added unverified email login blocking (403 `EMAIL_NOT_VERIFIED`) when `auth.require_email_verification` is enabled. Added 13 feature tests in `PasswordResetAndVerificationTest.php` and 5 unit tests in `PasswordRuleTest.php`. Full test suite passes with 102 tests (440 assertions), Pint clean, PHPStan level 8 0 errors, `composer ci` green.

- `2026-10-25`: `feat/auth-email-password`: Built tenant email/password authentication in `Modules/Auth` with stateful session auth. Added `TenantUser` model implementing `AuthenticatableContract` using `UsesTenantConnection` on `users` table with hidden password. Added `tenant_users` provider and wired `web` and `tenant` guards in `config/auth.php` (ensures `Auth::guard()` never touches central DB). Configured `SESSION_DOMAIN=null` (host-only cookie). Implemented `POST /api/v1/auth/login` (validated by `LoginRequest` with 12+ chars min length and regex enforcing at least one letter and one number; tenant-scoped throttling `login:{tenant_id}:{email}:{ip}` with lowercased email returning 429 `TOO_MANY_ATTEMPTS`; generic 401 `INVALID_CREDENTIALS` for unknown email and wrong password; session regeneration destroying prior session id on success; `Auth::login()`; records `tenant_id` in session; standard `ApiResponse` envelope with `UserResource`). Implemented `POST /api/v1/auth/logout` (invalidates session, regenerates CSRF token). Added `EnsureTenantSession` middleware ensuring replayed cross-tenant sessions are rejected with 401 `UNAUTHENTICATED`. Added 11 Pest feature tests (`tests/Feature/Auth/LoginTest.php`) using real tenant subdomains (`alpha.nexuserp.test`, `beta.nexuserp.test`) covering valid login, enumeration protection, throttling, tenant isolation of limits, case-insensitive email limit sharing, clearing failures on success, session fixation prevention, post-logout cookie invalidation, complexity validation, and cross-tenant replay rejection. All 84 Pest tests pass (376 assertions). 0 Pint issues, 0 PHPStan errors at level 8. Phase 2 checklist item in `docs/Phases.md` kept unchecked until merge to `develop` and CI passes.

- `2026-10-25`: Frontend scaffold & tooling: Set up Inertia with React 19 and TypeScript (`strict: true`, no `any`, no `@ts-ignore`) in `frontend/`. Mapped Design.md §2 light mode color tokens into `app.css` via Tailwind `@theme`. Created Architecture §4 structure (`components/ui/`, `features/`, `layouts/`, `shared/`). Implemented typed `apiClient` with envelope parsing and `ApiError` propagation (code, message, errors, status, field helpers). Built `AppLayout` (256px royal blue sidebar, topbar, 1440px container), `AuthLayout`, `AdminLayout`, and `PlaceholderDashboard`. Wired `npm run ci` to ESLint, `tsc --noEmit`, and Vitest. Added 6 unit tests for `apiClient`/`ApiError` and 1 integration test for `PlaceholderDashboard` (all 7 passed). Playwright deferred to Phase 7 unticked. Zero backend files modified.

- `2026-10-25`: `tenants:migrate` command: Created `php artisan tenants:migrate` running module tenant migrations (`Modules/*/Database/Migrations`) against active tenants via `TenantManager::switchTo()`. Skips inactive tenants (`suspended`, `provisioning`, `provisioning_failed`) with console output. Non-zero exit on failure while continuing to remaining tenants. Supports `--tenant=<slug>`, disables `--fresh`, requires `--force` in production. Added 6 Pest tests covering multi-tenant migration, skipping, error resilience, and slug filtering. `composer ci` passed with 71 tests (260 assertions). Ticked Phase 1 item.

- `2026-10-25`: Merged `feat/tenant-provisioning-job` and `chore/tooling-quality-gates` into `develop`. Verified tenant provisioning pipeline and database isolation via sentinel-row experiment and dedicated `DatabaseIsolationTest`. `composer ci` passed completely with 65 tests (221 assertions), 0 Pint issues, 0 PHPStan errors. Ticked backend tooling and tenant provisioning items in `docs/Phases.md`.
- `2026-10-24`: Tooling & Quality Gates: Separated testing database via `phpunit.xml` (`DB_DATABASE=pgsql_testing` / `DB_CENTRAL_DATABASE=pgsql_testing`) ensuring zero contamination of development database. Installed Larastan v3.13 / PHPStan 2.3 at level 8 (0 errors). Added Pest Arch tests (strict types, no `DB::` in controllers, module model encapsulation, no float in money classes). Added `composer ci` script (Pint --test, Larastan level 8, Pest 64 tests). Added frontend `npm run ci` script (gracefully reports skipped uninstalled tools). Added GitHub Actions workflow (`.github/workflows/ci.yml`) and local runner (`./scripts/ci.sh`). Ticked Phase 1 tooling item.
- `2026-10-24`: Tenant provisioning: Created `Modules/Tenancy/` module — `POST /api/v1/tenants` (Form Request: name/slug/owner_email, rate limited at 5 req/min, creates central `tenants` + `domains`, dispatches `ProvisionTenantDatabase`). Job is idempotent+retry-safe: creates PostgreSQL DB via PDO if absent, runs tenant migrations (`migrate --database=tenant`), seeds 5 roles + 6 settings via `insertOrIgnore`, creates owner user with owner role, marks tenant `active`. On failure: sets `provisioning_failed` + error message, rethrows. Chart of Accounts seeder deferred to Phase 5. Added midway migration failure retry test. 11 Pest tests (60 total, 208 assertions). Pint clean.
- `2026-10-24`: IdentifyTenant middleware: Created `Shared/Http/Middleware/IdentifyTenant.php` resolving subdomains from request host against central `tenants`/`domains`. Cached in Redis (60s TTL, `tenant:prefixed` key with invalidation). Enforces status rules (404 via `ApiException` for API / Laravel 404 for web, 403 `TENANT_SUSPENDED`, 403 `TENANT_UNAVAILABLE`). Calls `TenantManager::switchTo()` on valid tenant, resets context in `terminate()`. Registered as alias in `bootstrap/app.php`. Added 11 Pest tests (49 total passed, 152 assertions). Pint passed.
- `2026-10-24`: Central schema: Created migrations in `backend/database/migrations/central/` for `tenants` (ULID id, no soft deletes), `domains` (cascade delete FK), `packages` (BIGINT `price_cents`, JSONB `features`), `subscriptions`, `super_admins`, and `global_audit_logs` (append-only, no `updated_at`). All FK and status columns indexed. Added 15 Pest tests (38 total passed, 111 assertions) verifying tables, uniqueness constraints, cascade deletion, and BIGINT integrity. Pint passed.
