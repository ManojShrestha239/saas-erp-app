# AI Coding Rules & Boundaries

> **Agent note:** These rules are mandatory. If a task requires breaking one, stop and ask the user. Never silently work around a rule.

## 0. Session Protocol (do this every session)

1. **Start:** read `Memory.md` first, then `Phases.md` (active phase only). Read `PRD.md`, `Architecture.md`, `Design.md` only for the parts relevant to the task. Do not re-scan the whole codebase.
2. When executing destructive or external shell actions, always wrap the command inside `agy-guard '<command>'` instead of running directly.
3. **Work on one task at a time**, from the active phase. Do not start the next phase until the current phase's exit criteria are met and the user confirms.
4. **Before coding:** state your plan in 3–6 bullets (files you will create/edit). For anything touching money, tenancy, or auth, wait for approval.
5. **After coding:** run tests/linters, then update `Memory.md` (status, decisions, blockers, next steps) and tick items in `Phases.md`.
6. **Never invent** requirements, endpoints, columns, or packages. If something is unspecified, ask or list it in `PRD.md` → Open Questions.
7. Keep changes small and reviewable: one concern per change, no drive-by refactors.

## 1. Strict Typing & Code Quality

**Backend (PHP 8.4)**

- Every PHP file starts with `declare(strict_types=1);`.
- Explicit parameter, property, and return types. Use `readonly` DTOs, backed enums, `final` classes by default.
- Follow PSR-12 via Laravel Pint; static analysis with Larastan level 8 must pass.
- No `dd()`, `dump()`, `var_dump()`, `env()` outside config files.

**Frontend (TypeScript)**

- `strict: true`. **Never use `any`**, `@ts-ignore`, or non-null `!` without a justifying comment. Prefer `unknown` + narrowing.
- Typed props, hooks, API functions, and Zod schemas (infer types from schemas with `z.infer`).
- Functional components and hooks only. ESLint and `tsc --noEmit` must pass.

## 2. Financial Precision & Transactions

- **Never use floats for money.** Store integer minor units (`*_cents` BIGINT). PHP: `moneyphp/money`. JS: `currency.js` / integer cents + `Intl.NumberFormat` for display only.
- Rounding is explicit (half-up) and covered by unit tests. Tax/total calculations happen on the server; the client only previews.
- Every business mutation (invoice, bill, payment, adjustment, note, journal) runs inside `DB::transaction(fn () => { ... }, 3)` (3 deadlock retries). Use `lockForUpdate()` when reading rows you will change (stock, balances, numbering sequences).
- Journal entries must balance (debits = credits) or throw. Posted documents and journals are **immutable**; fix mistakes with reversals, credit notes, or debit notes.
- Side effects that must not run on rollback (notifications, SSE, emails) use `DB::afterCommit` / `ShouldDispatchAfterCommit`.

## 3. Separation of Concerns

**Backend**

- Controllers are thin: authorize → validate (Form Request) → map to DTO → call one Service → return Resource / `ApiResponse` / `Inertia::render`.
- Business logic lives in Services. No raw SQL or query-builder chains in controllers. Prefer Eloquent; raw SQL only inside dedicated query/report classes with bindings.
- A module may use another module's public Service or Contract, never its Models or tables.
- Use API Resources for every response shape; never return Eloquent models directly.

**Frontend**

- Components contain presentation and UI state only. No business rules, no `fetch`/`axios` calls inside components.
- All server communication goes through typed functions in `features/{Module}/api/` using `shared/api/apiClient.ts`; hooks wrap those functions.
- Feature code never imports from another feature's internals; shared code goes in `shared/`.

## 4. Multi-Tenancy & Security (non-negotiable)

- Never mix tenant databases. Central data uses the `central` connection explicitly; tenant data uses the `tenant` connection. No cross-tenant joins.
- Every queued job, cache key, file path, and SSE channel is tenant-scoped. Jobs re-initialize tenancy from `tenant_id`.
- Every controller action is protected by a Policy or permission check. Frontend hiding is cosmetic only.
- Never bypass Form Requests. Never trust client-calculated totals, prices, or tenant IDs.
- Mass assignment: define `$fillable`; never `$guarded = []`.
- Secrets live in `.env` only. Never commit credentials or print them in logs.
- Log and audit all create/update/delete/post/void actions (Spatie Activitylog). Audit logs are append-only.

## 5. Database & Migrations

- Tenant migrations live in each module's `Database/Migrations/`; central ones in `database/migrations/central/`. Migrations are forward-only; never edit a migration that has been merged, add a new one.
- Foreign keys, indexes on filter/sort columns, `NOT NULL` by default, `timestamps`, soft deletes only where the PRD allows.
- Money columns: `bigInteger('*_cents')`. Quantities: use integer or fixed-scale integer (e.g., thousandths); never float.
- Seeders and factories must exist for every model used in tests.

## 6. API & Validation Rules

- All JSON routes under `/api/v1`, responses use the standard envelope from `Architecture.md`.
- Throw `ApiException` subclasses for domain errors; never `return response()->json(['error' => ...])` ad hoc.
- Lists: server-side pagination (default 25, max 100), whitelist sortable/filterable fields.
- Validate with Form Requests on the server and Zod on the client; they must agree.

## 7. Prohibited Patterns

- Floats for money; business logic in controllers, models' accessors, or React components.
- Raw SQL in controllers; `DB::` calls outside Services/Query classes.
- Writing raw CSS files or inline styles. Use Tailwind utilities and shadcn/ui variants. _(Single exception: the one `app.css` that declares Tailwind and the design tokens from `Design.md`.)_
- Adding npm/composer packages not listed in `Architecture.md` without approval.
- `any`, `@ts-ignore`, `eslint-disable` without a reason comment.
- Editing or deleting tests to make them pass. Fix the code or ask.
- Large unrelated refactors, renaming public APIs, or changing folder structure without approval.
- Disabling CSRF, policies, tenant middleware, or audit logging "temporarily".

## 8. Testing & Definition of Done

A task is done only when:

- [ ] Feature works against the PRD acceptance criteria.
- [ ] Pest tests added (unit for logic, feature for endpoints incl. **auth, validation, and tenant-isolation cases**); Vitest/RTL tests for non-trivial UI.
- [ ] Pest Arch, Larastan, Pint, ESLint, and `tsc --noEmit` pass.
- [ ] No `any`, no floats for money, no leftover debug code.
- [ ] Migrations run clean on a fresh tenant database.
- [ ] `Memory.md` and `Phases.md` updated.

## 9. Error Handling

- Backend: fail loudly and early with typed exceptions; never swallow exceptions; log with `tenant_id` and `request_id`.
- Frontend: all API errors surface as `ApiError`; show field errors inline, general errors via toast; every async view has loading, empty, and error states.

## 10. Naming & Style

- PHP classes `PascalCase`, methods `camelCase`, DB columns `snake_case`, routes `kebab-case`.
- One action = one invokable controller or a small resource controller; Services named by use case (`PostSalesInvoiceService`).
- React components `PascalCase.tsx`, hooks `useThing.ts`, API files `thingApi.ts`, types `thing.types.ts`.
- Commit messages: Conventional Commits (`feat(sales): post invoice atomically`).
