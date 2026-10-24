# Project Memory & Progress State

> **Agent protocol:** READ this file at the start of every session. UPDATE it at the end of every task (and before the user switches chat/tool). Keep it short and factual. Never paste large code here; reference file paths instead. Keep the Session Log to the latest 10 entries; roll older ones into one summary line.

## Project Snapshot
- **Product:** NexusERP, multi-tenant SaaS ERP for trading/retail/wholesale.
- **Stack:** Laravel 13 (PHP 8.4, modular monolith) · Inertia + React 19 + TypeScript · Tailwind + shadcn/ui · PostgreSQL (database-per-tenant) · Redis + Horizon · Docker.
- **Source-of-truth docs:** `docs/PRD.md`, `docs/Architecture.md`, `docs/Rules.md`, `docs/Phases.md`, `docs/Design.md`.
- **Tooling:** vibe coding with agy CLI.

## Current Status
- **Active Phase:** Phase 1: Foundation & Multi-Tenancy Infrastructure
- **Last Completed Task:** Initialized directory structure and Docker containers.
- **Current Focus:** Implementing `IdentifyTenant` middleware and the central tenant migration schema.
- **Working branch:** `main` (update when branching)

## Architectural Decisions (Decision Log)
| # | Decision | Reason |
|---|---|---|
| 1 | Database-per-tenant on PostgreSQL, connection switched dynamically by `IdentifyTenant` middleware | Maximum isolation and security |
| 2 | Money stored as integers (`integer('total_amount_cents')` → use BIGINT for new columns) | No floating-point errors |
| 3 | Hybrid data flow: Inertia renders pages; typed API (`/api/v1`) for interactive data and mutations | Keeps `features/*/api` contract + SPA feel |
| 4 | Two named connections: `central` and `tenant`; no reliance on default connection | Prevents cross-tenant leakage |
| 5 | Append-only stock movements + cached stock levels, updated in the same transaction | Auditability + read speed |

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
1. Finish writing `IdentifyTenant.php` and test connection switching.
2. Build Tenant Registration Controller and automated database creator job (`ProvisionTenantDatabase`).
3. Add `tenants:migrate` command and tenant-isolation feature tests.

## Key Files Touched (latest)
- (none yet; add paths here as they are created)

## Session Log (newest first)
- `YYYY-MM-DD`: *template*: what was done · files changed · tests run/result · what is next.
