#!/usr/bin/env bash

# ==============================================================================
# Local CI Quality Gates Runner
# Runs Pint (--test), Larastan (level 8), Pest (Unit, Feature, Arch), and Frontend CI
# ==============================================================================

set -euo pipefail

echo "=================================================="
echo " Starting NexusERP CI Quality Gates"
echo "=================================================="

# Check if docker containers are running
if ! docker compose ps --services --filter "status=running" | grep -q "app"; then
    echo "Starting Docker Compose services..."
    docker compose up -d
fi

echo ""
echo "--- 1. Backend: Laravel Pint (code style check) ---"
docker compose exec -T app ./vendor/bin/pint --test

echo ""
echo "--- 2. Backend: Larastan (static analysis at level 8) ---"
docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=512M

echo ""
echo "--- 3. Backend: Pest Test Suite (Unit, Feature, Arch) ---"
docker compose exec -T app ./vendor/bin/pest

echo ""
echo "--- 4. Frontend: CI Scripts (ESLint, TypeScript, Vitest) ---"
docker compose exec -T node npm run ci

echo ""
echo "=================================================="
echo " All CI quality gates passed successfully!"
echo "=================================================="
