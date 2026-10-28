---
name: module-scaffold
description: Scaffold boilerplate structure for new NexusERP modules or features following Architecture.md and Rules.md. Use when starting Phase 4 or 5 modules (Purchasing, Sales, Accounting, etc.).
---

# Modular Monolith & Tenancy Scaffolder

Scaffolds a new bounded context under `backend/Modules/{Name}` and `frontend/src/features/{Name}`.

## Structural Requirements

### 1. Backend Module Structure (`backend/Modules/{ModuleName}/`)

Create the following hierarchy:

- `Database/Migrations/` (forward-only tenant migrations using ULIDs and BIGINT `*_cents`)
- `DTOs/` (readonly PHP 8.4 DTOs with typed properties)
- `Enums/` (backed enums)
- `Events/` & `Listeners/`
- `Http/Controllers/` (thin controllers returning `ApiResponse` or `Resource`)
- `Http/Requests/` (FormRequests validating input)
- `Http/Resources/` (JSON resources enforcing API envelope)
- `Models/` (must use `UsesTenantConnection` and `HasUlids` traits)
- `Policies/` (enforce Spatie roles & permissions per PRD §2.1)
- `Providers/{ModuleName}ServiceProvider.php`
- `Routes/api.php` (prefixed with `/api/v1/`)
- `Services/` (business logic wrapped in `DB::connection('tenant')->transaction(..., 3)`)

### 2. Frontend Feature Structure (`frontend/src/features/{ModuleName}/`)

- `api/{module}Api.ts` (typed functions wrapping `shared/api/apiClient.ts`)
- `components/` (feature-specific UI using shadcn components)
- `hooks/` (React hooks and queries)
- `pages/` (Inertia page components)
- `types/{module}.types.ts` (Zod schemas and inferred types)
- `utils/` (formatting and minor-unit conversion helpers)

## Conventions to Enforce

- All database mutations must use `lockForUpdate()` when reading rows that will change.
- Side effects (SSE, notifications, emails) must be queued using `ShouldDispatchAfterCommit`.
- All monetary inputs are parsed to minor-unit integer cents via `MoneyParser`.
