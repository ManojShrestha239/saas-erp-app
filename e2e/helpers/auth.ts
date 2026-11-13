import { Page, expect } from '@playwright/test';
import { execSync } from 'child_process';

export const DEMO_USERS = {
  owner: 'owner@demo.nexuserp.test',
  admin: 'admin@demo.nexuserp.test',
  accountant: 'accountant@demo.nexuserp.test',
  warehouse: 'warehouse@demo.nexuserp.test',
  sales: 'sales@demo.nexuserp.test',
  password: 'Demo12345!',
};

export const EXEC_ENV = {
  ...process.env,
  PATH: `/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:${process.env.PATH || ''}`,
};

/**
 * Resets the demo tenant database to a clean seed state.
 */
export function resetDemoTenant(): void {
  try {
    execSync('docker compose exec -T app php artisan tenants:seed-demo --fresh', {
      env: EXEC_ENV,
      stdio: 'pipe',
    });
  } catch (err) {
    console.error('Failed to reset demo tenant:', err);
    throw err;
  }
}

/**
 * Executes pending queued jobs in the container.
 */
export function runQueuedJobs(count = 1): void {
  for (let i = 0; i < count; i++) {
    try {
      execSync('docker compose exec -T app php artisan queue:work --once', {
        env: EXEC_ENV,
        stdio: 'pipe',
      });
    } catch {
      // queue empty
      break;
    }
  }
}

/**
 * Prepares page routes, mock-handling long-lived SSE streams so they don't block workers.
 */
export async function setupPage(page: Page): Promise<void> {
  await page.route('**/api/v1/stream*', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'text/event-stream',
      body: 'id: test\nevent: connected\ndata: {"status":"connected"}\n\n',
    });
  });
}

/**
 * Logs in via the tenant login UI form and waits for redirect to /dashboard.
 */
export async function loginAs(page: Page, email: string, password = DEMO_USERS.password): Promise<void> {
  await setupPage(page);
  await page.goto('/login');
  await expect(page.locator('#email')).toBeVisible();
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard', { timeout: 15_000 });
  await expect(page.locator('text=Dashboard').first()).toBeVisible();
}

/**
 * Logs out the current user via UI or API.
 */
export async function logout(page: Page): Promise<void> {
  await page.evaluate(async () => {
    try {
      await fetch('/api/v1/auth/logout', { method: 'POST' });
    } catch {
      // ignore
    }
  });
  await page.context().clearCookies();
}
