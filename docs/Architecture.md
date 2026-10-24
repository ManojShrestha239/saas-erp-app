# Technical Architecture: NexusERP

> **Agent note:** Follow this document for HOW the system is structured. Do not introduce libraries or patterns not listed here without asking first. Every new dependency must be recorded in `Memory.md` → Decision Log.

## 1. Technology Stack

| Layer | Choice | Role |
|---|---|---|
| Backend | **Laravel 13**, PHP 8.4 (`strict_types`), Modular Monolith | Domain modules under `Modules/` |
| Auth | Laravel Sanctum + session (Inertia), Google OAuth (Socialite) | SPA/session auth + token API |
| Frontend | **Inertia.js + React 19 + TypeScript (strict)** | Server-driven pages, SPA feel, HMR |
| UI | Tailwind CSS + **shadcn/ui** | Accessible primitives |
| Forms | React Hook Form + Zod | Type-safe client validation (server is still the authority) |
| Tables | TanStack Table | Headless sorting/filter/pagination |
| Database | **PostgreSQL**, database-per-tenant | Isolation, ACID, JSONB |
| Cache/Queues | Redis + Laravel Horizon | Caching, jobs, monitoring |
| Real-time | Server-Sent Events (SSE) | One-way live updates |
| RBAC | Spatie Laravel Permission | Roles & permissions |
| Audit | Spatie Laravel Activitylog | Change trail |
| Money | `moneyphp/money` (backend), `currency.js` + `Intl` (frontend) | Integer-cent math & formatting |
| Documents | Spatie Browsershot (PDF), Maatwebsite Excel | Exports |
| Push | Pure-Laravel FCM helper (HTTP client) | Notifications, no extra SDK |
| Storage | Laravel local disk (tenant-scoped paths) | Uploads |
| API docs | Laravel Scribe | OpenAPI + HTML reference |
| Dev tools | Debugbar, Telescope (dev only) | Profiling |
| Testing | Pest, Pest Arch, JSON Schema assertions, Vitest, RTL, Playwright | Full pyramid |
| Infra | Docker: Nginx, PHP-FPM, Composer, Supervisor, PostgreSQL, PgAdmin, Redis, Node | Local + deploy parity |

## 2. Multi-Tenancy (Database-per-Tenant)

**Central database** (`pgsql_central`): `tenants`, `domains`, `packages`, `subscriptions`, `super_admins`, `feature_flags`, `global_audit_logs`, jobs/failed_jobs.

**Tenant database** (`tenant_<slug>`): users, roles/permissions, contacts, products, stock movements, documents, payments, accounts, journals, tax, settings, tenant audit log.

**Request flow**
```
Request (acme.nexuserp.test)
 → IdentifyTenant middleware
     1. read subdomain
     2. look up tenant in CENTRAL (cached in Redis, short TTL)
     3. reject unknown/suspended tenants
     4. purge + reconfigure the `tenant` connection (database = tenant_acme)
     5. bind TenantContext (id, slug, settings) into the container
 → Auth → Authorization → Controller → Service → DB (tenant connection)
```

**Hard rules**
- Two named connections: `central` and `tenant`. Central models set `$connection = 'central'`; tenant models use `tenant` via a `UsesTenantConnection` trait. Never rely on the default connection.
- Queued jobs carry `tenant_id` and re-initialize tenancy in `handle()` (use a `TenantAware` job trait/middleware). Cache keys, file paths, and broadcast channels are prefixed with the tenant ID.
- Migrations are split: `database/migrations/central/` vs each module's `Database/Migrations/` (tenant). `php artisan tenants:migrate` iterates all tenants.
- **Provisioning job** (idempotent): create DB → migrate → seed roles + default Chart of Accounts + settings → create owner → mark `active`. Failure → `provisioning_failed` with reason, retryable.
- Super Admin routes run on the central domain with no tenant bound.

## 3. Backend Architecture (Modular Monolith)

```
backend/
├── Modules/
│   └── {ModuleName}/
│       ├── Database/Migrations/       (+ Seeders/, Factories/)
│       ├── DTOs/                      readonly value objects
│       ├── Enums/
│       ├── Events/ & Listeners/
│       ├── Http/{Controllers,Requests,Resources}/
│       ├── Models/
│       ├── Policies/
│       ├── Providers/{ModuleName}ServiceProvider.php
│       ├── Routes/{api.php,web.php}
│       └── Services/                  business logic lives here
├── Shared/
│   ├── Exceptions/{ApiException.php,ApiExceptionRenderer.php}
│   ├── Http/Middleware/{EnsureJsonRequest.php,IdentifyTenant.php}
│   ├── Responses/ApiResponse.php
│   ├── Tenancy/                       TenantContext, TenantManager, traits
│   ├── Money/                         MoneyFactory, casts
│   └── Utils/HttpStatus.php
├── bootstrap/{app.php,providers.php}
├── config/ · routes/api.php · tests/{Unit,Feature,Arch}/
└── composer.json                      PSR-4: Modules\ , Shared\
```

**Planned modules:** `Tenancy`, `SuperAdmin`, `Auth`, `Contacts`, `Inventory`, `Purchasing`, `Sales`, `Accounting`, `Tax`, `Reports`, `Audit`.

**Layering (one-way dependencies)**
`Controller → FormRequest → DTO → Service → Model/Repository`
- Controllers are thin: validate (FormRequest), build DTO, call one Service, return Resource/`ApiResponse`/`Inertia::render`.
- Services own business rules and transactions. A Service may call another module's **public Service or Contract**, never its Models or tables directly.
- Cross-module side effects use **domain events** (e.g., `InvoicePosted` → stock listener, ledger listener) executed inside the same transaction when they must be atomic; otherwise queued.
- Pest Arch tests enforce: no `DB::` in controllers, strict types everywhere, Modules don't import other Modules' Models, no `float` in money classes.

**API contract** (all JSON endpoints, versioned `/api/v1`)
```json
{ "success": true, "message": "…", "data": { }, "meta": { "page": 1, "per_page": 25, "total": 240 } }
{ "success": false, "message": "…", "errors": { "field": ["…"] }, "code": "VALIDATION_FAILED" }
```
Errors are thrown as `ApiException` and rendered centrally by `ApiExceptionRenderer`. Lists are paginated server-side with filter/sort whitelists.

## 4. Frontend Architecture (Feature-Driven)

```
frontend/src/
├── app.tsx                            Inertia bootstrap
├── components/ui/                     shadcn primitives only (generic)
├── features/{ModuleName}/
│   ├── api/        {module}Api.ts     only place that calls the server
│   ├── components/                    feature UI
│   ├── hooks/                         queries/mutations/state
│   ├── pages/                         Inertia page components
│   ├── types/      {module}.types.ts
│   └── utils/                         Zod schemas, helpers
├── layouts/                           AppLayout, AuthLayout, AdminLayout
└── shared/{api/apiClient.ts,api/ApiError.ts,types/apiResponse.ts,money/,hooks/}
```

**Hybrid data approach (decision):** Inertia renders pages and supplies initial props and navigation; interactive data (tables, search, mutations, autosave) goes through typed functions in `features/*/api/` using `shared/api/apiClient.ts` against `/api/v1`. Components never call `fetch`/`axios` directly.

**State:** server state via TanStack Query (or Inertia props for page data); local UI state via hooks; no global store unless justified in the Decision Log.

## 5. Financial Engine

- All money is **integer minor units** (`*_cents` BIGINT). Never `float`/`double`/`decimal` math in PHP or JS. Use `moneyphp/money` server-side and `currency.js` client-side; rounding mode is explicit (half-up) and tested.
- Tax amounts are computed server-side and stored per line; the client only previews.
- **Posting engine** (`Accounting` module): `PostJournalEntry` accepts balanced lines; rejects imbalance; stores `source_type/source_id`; posted entries are immutable (correct via reversal).
- **Invoice posting transaction** (atomic, `DB::transaction(..., 3)`):
  1. lock products/stock rows (`lockForUpdate`) → 2. validate stock → 3. write stock movements → 4. post journal (AR, Revenue, Output VAT, COGS/Inventory) → 5. update document status and balances → 6. dispatch post-commit events (SSE, low-stock check, audit).
- Document numbering uses a per-tenant sequence table with row lock; numbers are gap-free for posted documents.
- Stock on-hand = sum of append-only `stock_movements`; a cached `stock_levels` table is updated in the same transaction for fast reads.

## 6. Real-Time, Queues, Files, Docs
- **SSE** endpoint `/api/v1/stream` (tenant + user scoped, heartbeat, reconnect with `Last-Event-ID`); events published via Redis. Frontend `useEventStream` hook.
- **Horizon queues:** `provisioning`, `notifications`, `reports`, `default`. Jobs are idempotent, tenant-aware, with retry/backoff.
- **PDF/Excel:** generated on the `reports` queue, stored under `storage/app/tenants/{id}/exports/`, served via signed, expiring URLs.
- **Uploads:** tenant-scoped paths, MIME/size validated.

## 7. Security Checklist
Tenant-scoped everything · Policies on every controller action · Form Requests for all input · rate limits on auth/export · CSRF on session routes · signed download URLs · no secrets in repo · prod: Telescope/Debugbar disabled · audit every create/update/delete/post/void.

## 8. Environments (Docker)
Services: `nginx`, `app` (php-fpm), `queue` (Horizon via Supervisor), `scheduler`, `postgres`, `pgadmin`, `redis`, `node` (Vite HMR). Wildcard local domain `*.nexuserp.test`. `.env.example` is the source of truth for config keys.

## 9. Testing Strategy
| Level | Tool | Must cover |
|---|---|---|
| Unit | Pest | Money math, DTOs, posting rules |
| Feature | Pest + JSON Schema assertions | Every endpoint: auth, validation, tenant isolation, response shape |
| Architecture | Pest Arch | Layering, strict types, forbidden functions |
| Frontend | Vitest + RTL | Forms, tables, hooks |
| E2E | Playwright | The 5 flows in PRD §5 |

CI gate: Pint/Larastan (level 8), ESLint + `tsc --noEmit`, all tests green.
