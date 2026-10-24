# AI Coding Rules & Boundaries

## 1. Strict Typing & Code Quality

- **Backend:** All PHP files must declare strict types (`declare(strict_types=1);`) with explicit property and return type-hinting.
- **Frontend:** All components, hooks, and API clients must use strict TypeScript interfaces. Never use `any`.

## 2. Financial Precision & Transactions

- **Currency Math:** Never use floating-point numbers for financial calculations. Store monetary values as integers (cents) or use precise calculation libraries.
- **Database Transactions:** All business mutations (e.g., creating an invoice that updates stock and posts journal entries) must be wrapped inside `DB::transaction(fn() => { ... }, 3)` with deadlock retry logic.

## 3. Separation of Concerns

- **Backend:** Controllers must remain thin. They validate incoming requests, map data to DTOs, invoke Domain Services, and return JSON/Inertia responses. Never write raw SQL inside controllers.
- **Frontend:** Components must never contain database queries or direct business logic. All server communication must go through typed API functions in feature `api/` folders.

## 4. Prohibited Patterns

- Do not mix tenant databases without explicit central connection scopes.
- Do not bypass Form Requests for input validation.
- Do not use raw CSS files; use Tailwind utility classes and shadcn/ui component styling exclusively.
