---
name: browser-automation
description: Automates browser navigation, UI inspection, login, and visual verification for NexusERP multi-tenant flows using Playwright.
---

# NexusERP Browser Automation Skill

Use this skill to navigate tenant subdomains, verify Inertia/React UI states, test form inputs, and capture screenshots.

## Project Environment & Context

- Central Domain: `http://nexuserp.test` or `http://localhost:8000`
- Demo Tenant Domain: `http://demo.nexuserp.test` or tenant subdomains
- Test Accounts:
  - Super Admin: `admin@nexuserp.test` / `SuperAdminSecret123!`
  - Tenant Owner: `owner@demo.nexuserp.test` / `TenantAdmin123!`
  - Tenant Admin: `admin@demo.nexuserp.test` / `TenantAdmin123!`
  - Demo Staff: `TenantUser123!`

## Common Workflows

### 1. Inspect a URL / Extract Text

```bash
node .agents/skills/browser-automation/scripts/browse.js get "[http://demo.nexuserp.test/login](http://demo.nexuserp.test/login)"
```
