# NexusERP Security Architecture & OWASP Top 10 Compliance Review

> **Document Version:** 1.0  
> **Status:** Active / Production-Ready  
> **Classification:** Security Specification & Audit Report  

---

## 1. Overview & Multi-Tenant Threat Model

NexusERP employs a **database-per-tenant architecture on PostgreSQL**, creating physical isolation boundaries between customer workspaces. Platform control-plane data (tenants, subscriptions, packages, global audit logs) resides in `pgsql_central`, completely segregated from tenant ERP transactional databases (`tenant_<slug>`).

### Tenancy Isolation Guarantee
1. **Perimeter Dynamic Switching:** Incoming requests to `{subdomain}.nexuserp.test` pass through [`IdentifyTenant`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/Shared/Http/Middleware/IdentifyTenant.php), which validates tenant state (`active`) and configures the `tenant` connection dynamically before routing.
2. **Explicit Named Connections:** Central and tenant database connections are explicitly segregated (`central` vs `tenant`). Default connection assumptions are forbidden by Pest Arch tests.
3. **Session Binding Defense:** The [`EnsureTenantSession`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/Modules/Auth/Http/Middleware/EnsureTenantSession.php) middleware validates that the authenticated session's `tenant_id` matches the resolved tenant context. Cross-tenant cookies or forged subdomains trigger immediate session invalidation and HTTP 401 rejection.
4. **Least-Privilege Database Role:** Automated tenant provisioning connects via a dedicated non-superuser role ([`nexuserp_provisioner`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/docker/postgres/01-init-provisioner-role.sql)) with `CREATEDB` privilege only (`NOSUPERUSER`, `NOCREATEROLE`, `NOREPLICATION`).

---

## 2. OWASP Top 10 (2021) Compliance Matrix

| Vulnerability Category | NexusERP Defense Architecture | Verification & Status |
|:---|:---|:---|
| **A01: Broken Access Control** | • Database-per-tenant isolation.<br>• Spatie RBAC policies enforced across every controller.<br>• Subdomain-to-tenant session binding ([`EnsureTenantSession`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/Modules/Auth/Http/Middleware/EnsureTenantSession.php)).<br>• Defense-in-depth on signed URLs: export download checks cryptographic signature **and** asserts `tenants/{tenant_id}/` storage path matching active tenant context. | **PASS** — Covered by Pest Arch tests and Feature tests. |
| **A02: Cryptographic Failures** | • Passwords hashed via Bcrypt (work factor 12).<br>• Password reset and email verification tokens hashed via SHA-256 (60-minute expiry).<br>• Temporary signed URLs generated with HMAC-SHA256 ([`URL::temporarySignedRoute`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/Modules/Reports/Http/Controllers/ReportExportController.php)).<br>• Strict integer minor units (`*_cents` BIGINT) preventing float precision truncation exploits. | **PASS** — Enforced by unit and feature tests. |
| **A03: Injection** | • Parameterized SQL queries across Eloquent and query builders; raw SQL forbidden in controllers.<br>• Database identifiers validated via strict regex `^[a-z0-9_-]+$` and safely quoted.<br>• Strict parameter casting and typed DTOs. | **PASS** — Enforced by static analysis (Larastan Level 8) and Arch tests. |
| **A04: Insecure Design** | • Double-entry General Ledger with mandatory balancing ($total\_debits = total\_credits$).<br>• Posted commercial and financial documents are strictly immutable (corrections via reversals, credit notes, debit notes).<br>• Negative stock prevention enforced server-side inside `DB::transaction(..., 3)` under row locks (`lockForUpdate`). | **PASS** — Tested across Sales, Purchasing, Inventory, and Accounting test suites. |
| **A05: Security Misconfiguration** | • Dedicated non-superuser role with `CREATEDB` only for provisioning.<br>• Security headers enforced globally via [`SecurityHeadersMiddleware`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/Shared/Http/Middleware/SecurityHeadersMiddleware.php).<br>• Debug tools (`Telescope`, `Debugbar`) strictly disabled in production. | **PASS** — Verified in `ProvisionerRoleTest.php`. |
| **A06: Vulnerable Components** | • Lockfiles committed for Composer and NPM.<br>• Minimal dependencies: Pure-Laravel FCM helper (HTTP client) and pure-Laravel PDF Browsershot with no third-party Firebase SDKs. | **PASS** — Verified via `composer audit` and `npm audit`. |
| **A07: Identification & Auth** | • Host-only session cookies (`SESSION_DOMAIN=null`).<br>• Rate limiting on login (5 attempts/min), password reset (5/min), and email verification.<br>• Password change revokes all active sessions across devices.<br>• Google OAuth links only pre-verified emails belonging to existing active tenant users. | **PASS** — Verified in `LoginTest.php`, `PasswordResetAndVerificationTest.php`, and `GoogleAuthTest.php`. |
| **A08: Software & Data Integrity** | • Cryptographically signed URLs for export file downloads expiring in 60 minutes.<br>• Forward-only database migrations.<br>• Model-level rejection of modifications to posted journals, filed VAT returns, and audit logs. | **PASS** — Verified across domain service tests. |
| **A09: Logging & Monitoring** | • Immutable dual-tier audit trail: Spatie Activitylog in tenant DB + `global_audit_logs` in central DB.<br>• Model mutation events (`updating`, `deleting`) on audit logs throw `400 AUDIT_LOG_IMMUTABLE`.<br>• Structured application logs tagged with `tenant_id` and `request_id`. | **PASS** — Verified in `AuditLogTest.php` and `GlobalAuditLogTest.php`. |
| **A10: SSRF** | • Browsershot Chromium isolated in Docker with `--no-sandbox`, `--disable-dev-shm-usage`, rendering pre-compiled Blade templates with zero external URL fetching.<br>• FCM outbound push notifications restricted strictly to Google FCM endpoints. | **PASS** — Validated in `PdfRendererService` and `FcmService`. |

---

## 3. Global Security Headers

Configured via [`SecurityHeadersMiddleware`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/Shared/Http/Middleware/SecurityHeadersMiddleware.php):

```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 0
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none';
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload (HTTPS requests)
```

---

## 4. Rate Limiting Boundaries

Configured in [`AppServiceProvider`](file:///Users/manojshrestha/own-projects/vibeCoding/SaaSERP-app/backend/app/Providers/AppServiceProvider.php):

| Rate Limiter | Limit | Key Resolution | Protection Target |
|:---|:---|:---|:---|
| `auth` | 5 req / min | Client IP + Email | Brute force login, password reset flood |
| `tenants` | 5 req / min | Client IP | Denial-of-Service / Spam tenant registrations |
| `api` | 120 req / min | `tenant:{tenant_id}` or Client IP | API endpoint abuse |
| `downloads` | 60 req / min | Client IP | Bulk report / export scraping |

---

## 5. Production Release Security Checklist

- [x] Dedicated PostgreSQL user with `CREATEDB` only (no superuser privileges).
- [x] Host-only session cookie domain (`SESSION_DOMAIN=null`).
- [x] Global security headers middleware active.
- [x] Rate limiting active across all authentication and public endpoints.
- [x] Temporary signed URLs enforced for all file downloads with tenant boundary validation.
- [x] Audit logs immutable and protected against tampering.
- [x] Debug tools (`Telescope`, `Debugbar`) disabled when `APP_ENV=production`.
