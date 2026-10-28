---
name: ci-gate
description: Run strict backend and frontend CI checks, including Pint, Larastan Level 8, Pest Unit/Feature/Arch tests, ESLint, Vitest, and TypeScript type checking. Use whenever finalizing a feature, before updating Memory.md, or before committing code.
---

# CI Quality & Architecture Gate

Enforces the Definition of Done (Rules.md §8 and Architecture.md §9).

## Prerequisites to Check

1. Strict types: Every PHP file starts with `declare(strict_types=1);`.
2. Financial integrity: Verify no `float` is used for money calculations (must use integer cents with `moneyphp/money` or `currency.js`).
3. No forbidden tokens: Ensure no `any`, `@ts-ignore`, or `dd()` exist.

## Execution Sequence

Run the complete test and linting pipeline:

### 1. Backend Verification

```bash
# Code style
./vendor/bin/pint --test

# Static Analysis (Level 8)
./vendor/bin/phpstan analyse --level=8

# Pest Architecture, Unit, and Feature Tests
./vendor/bin/pest
```
