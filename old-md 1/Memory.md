# Project Memory & Progress State

## Current Status

- **Active Phase:** Phase 1 (Foundation & Multi-Tenancy Infrastructure)
- **Last Completed Task:** Initialized directory structure and Docker containers.
- **Current Focus:** Implementing `IdentifyTenant` middleware and central tenant migration schema.

## Recent Architectural Decisions

- Decided on database-per-tenant architecture using PostgreSQL schemas/databases dynamically switched via middleware.
- Enforcing integer-based currency storage in database migrations (`integer('total_amount_cents')`).

## Pending Bugs & Blockers

- None currently.

## Next Steps

1. Finish writing `IdentifyTenant.php` and test connection switching.
2. Build Tenant Registration Controller and automated database creator job.
