# NexusERP Production Deployment & Operations Guide

This guide specifies the production topology, container orchestration, process supervision, backup strategies, and disaster recovery procedures for NexusERP.

---

## 1. Production Architecture Overview

NexusERP operates as a **database-per-tenant modular monolith**:
- **Application Runtime (`app`):** PHP 8.4-FPM container running with OPcache (timestamps disabled, JIT enabled) and Chromium (for vector PDF generation).
- **Process Supervision (`supervisord`):** Supervises PHP-FPM, Laravel Horizon queue workers (handling `default`, `reports`, and `notifications` queues), and the scheduled task runner.
- **Web Server (`nginx`):** Reverse proxy terminating TLS, serving static immutable Vite assets (`/build/`) and Scribe docs (`/docs/`), enforcing security headers, and forwarding PHP requests via FastCGI.
- **Database Engine (`postgres`):** PostgreSQL 17 managing `pgsql_central` (tenants, domains, packages, global audit logs) and individual tenant databases (`tenant_{slug}`).
- **In-Memory Store (`redis`):** Redis 7 with password authentication, providing session storage, tenant-isolated cache (`spatie.permission.cache:{tenant_id}`), queue driver, and low-stock SSE real-time broadcasting.

---

## 2. Production Docker & Container Orchestration

### 2.1 Multi-Stage Production Dockerfile (`docker/production/Dockerfile`)

1. **Stage 1 (`frontend-builder`):** Node 22 Alpine builds the React 19 / TypeScript single-page application and outputs production chunks and manifest to `backend/public/build`.
2. **Stage 2 (`vendor-builder`):** Composer latest installs production PHP packages (`composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts`).
3. **Stage 3 (`runtime`):** PHP 8.4-FPM Alpine minimal image with:
   - System packages: `postgresql-client` (for `pg_dump`/`psql`), `supervisor`, `chromium` (for Browsershot).
   - PHP extensions: `pdo_pgsql`, `pgsql`, `redis`, `bcmath`, `intl`, `zip`, `pcntl`, `opcache`, `gd`.
   - OPcache tuned for production (`opcache.validate_timestamps=0`, `opcache.memory_consumption=256`).

### 2.2 Process Supervision (`docker/production/supervisor/supervisord.conf`)

Supervisor manages three essential long-running processes:
1. `php-fpm`: Handles FastCGI web traffic from Nginx.
2. `laravel-worker`: Runs `php artisan queue:work --queue=default,reports,notifications --sleep=3 --tries=3 --max-time=3600` under `www-data` with automatic restarts.
3. `laravel-scheduler`: Runs `php artisan schedule:run` every 60 seconds (for overdue invoice alerts, recurring subscriptions, and metric aggregation).

### 2.3 Production Compose Configuration (`docker-compose.prod.yml`)

```bash
# Start production stack
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

---

## 3. Multi-Tenant Backup & Disaster Recovery

NexusERP provides built-in Artisan CLI commands for multi-tenant backups, restores, and non-destructive recovery drills using PostgreSQL client utilities (`pg_dump`, `psql`, `gzip`).

### 3.1 Backing Up Databases

All backups are stored as compressed `.sql.gz` archives in `storage/app/backups/tenants/` (or a custom `--path` mount, e.g., an S3/NFS volume).

```bash
# 1. Backup all databases (central + all active tenants)
php artisan tenants:backup --all

# 2. Backup a specific tenant
php artisan tenants:backup --tenant=demo

# 3. Backup only the central control plane
php artisan tenants:backup --central

# 4. Backup to a custom path or mounted backup volume
php artisan tenants:backup --all --path=/mnt/backups/daily
```

### 3.2 Automated Disaster Recovery Restore Drill

To prove disaster recovery readiness without affecting production data, run an automated restore drill. The drill:
1. Backs up the live tenant database to a temporary compressed archive.
2. Creates an isolated ephemeral database (`tenant_drill_{slug}_{timestamp}`).
3. Restores the backup into the ephemeral database.
4. Verifies public schema tables (40+ tables) and records.
5. Cleanly drops the ephemeral database and cleans up temporary files.

```bash
# Execute automated drill on demo tenant
php artisan tenants:restore-drill --tenant=demo
```

### 3.3 Point-in-Time Database Restoration

In disaster recovery scenarios, restore a specific archive into a target database:

```bash
# Restore an archive into a target database (with safety prompt)
php artisan tenants:restore --file=/path/to/tenant_demo_2026-11-10.sql.gz --target-db=tenant_demo

# Force restore non-interactively (e.g. in automated CI/CD or DR scripts)
php artisan tenants:restore --file=/path/to/tenant_demo_2026-11-10.sql.gz --target-db=tenant_demo --force
```

---

## 4. Diagnostics & Production Hardening

### 4.1 Health Check Endpoints

- **Fast Container Probe (`GET /up`):**
  Built-in Laravel health check for container orchestrators (Kubernetes, AWS ECS, Docker Swarm) returning HTTP 200.
- **Deep Component Health Check (`GET /api/v1/health`):**
  Unauthenticated diagnostics endpoint evaluating:
  - Central database connection (`SELECT 1`).
  - Redis connectivity (`PING`).
  - Storage directory writeability (`storage/framework/cache`, `storage/logs`).
  - OPcache status.
  - Production safeguards (confirms `APP_DEBUG=false` and `TELESCOPE_ENABLED=false`).

Sample Response:
```json
{
  "success": true,
  "message": "System is healthy",
  "data": {
    "status": "healthy",
    "environment": "production",
    "debug": false,
    "checks": {
      "central_database": "ok",
      "redis": "ok",
      "storage": "ok",
      "opcache": "active",
      "debug_mode": "disabled",
      "telescope": "disabled"
    },
    "timestamp": "2026-11-10T11:00:00Z"
  }
}
```

### 4.2 Production Security Gating

`AppServiceProvider::enforceProductionSafeguards` forces:
- `telescope.enabled = false`
- `debugbar.enabled = false`
Whenever `app()->isProduction()` evaluates to true, preventing accidental profiling leaks or performance degradation in live environments.

---

## 5. Production Rollout Runbook

```bash
# 1. Pull latest code & build image
docker compose -f docker-compose.prod.yml build

# 2. Run central database migrations
docker exec nexuserp-production-app php artisan migrate --database=central --force

# 3. Run tenant migrations across all active tenants
docker exec nexuserp-production-app php artisan tenants:migrate --force

# 4. Cache configuration, events, and routes for maximum performance
docker exec nexuserp-production-app php artisan config:cache
docker exec nexuserp-production-app php artisan route:cache
docker exec nexuserp-production-app php artisan view:cache
docker exec nexuserp-production-app php artisan event:cache

# 5. Gracefully restart queue workers
docker exec nexuserp-production-app php artisan queue:restart

# 6. Verify health
curl -f http://localhost:8000/api/v1/health
```
