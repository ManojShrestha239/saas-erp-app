import { test, expect } from '@playwright/test';
import { DEMO_USERS, loginAs, resetDemoTenant } from './helpers/auth';

/**
 * PRD §5 Flow 5:
 * Accountant runs VAT return and P&L; totals match the ledger.
 */
test.describe('PRD §5 Flow 5: Statutory VAT Return, Trial Balance Equilibrium, and P&L', () => {
  test.beforeAll(async () => {
    resetDemoTenant();
  });

  test('accountant calculates periodic VAT return and verifies balanced ledger and P&L statements', async ({
    page,
  }) => {
    // Step 1: Log in as Accountant
    await loginAs(page, DEMO_USERS.accountant);

    // Step 2: Navigate to Accounting module and calculate VAT Return
    await page.goto('/accounting');
    await page.waitForLoadState('networkidle');

    // Switch to VAT Return & Tax tab
    const vatTabBtn = page.locator('button:has-text("VAT Return & Tax")');
    await expect(vatTabBtn).toBeVisible({ timeout: 10_000 });
    await vatTabBtn.click();
    await expect(page.locator('text=Calculate Return')).toBeVisible({ timeout: 10_000 });

    // Trigger VAT return calculation
    await page.click('button:has-text("Calculate Return")');

    // Assert that calculated return metrics and reconciliation badge appear
    await expect(page.locator('text=Total Output VAT')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('text=Total Input VAT')).toBeVisible();
    await expect(page.locator('text=GL Reconciliation')).toBeVisible();
    await expect(page.locator('text=100% Reconciled')).toBeVisible();

    // Step 3: Navigate to Reports Hub and verify Trial Balance equilibrium
    await page.goto('/reports');
    await expect(page.locator('nav[aria-label="Reports Navigation Tabs"]')).toBeVisible();

    // Trial Balance is the default financial tab
    await expect(page.locator('h2:has-text("Trial Balance")')).toBeVisible();
    await expect(page.locator('text=Total Debits')).toBeVisible();
    await expect(page.locator('text=Total Credits')).toBeVisible();

    // Assert that the double-entry ledger is in perfect equilibrium
    await expect(page.locator('text=Balanced (Net Diff: 0.00)').first()).toBeVisible();

    // Step 4: Verify Profit & Loss statement
    await page.click('button:has-text("Profit & Loss")');
    await expect(page.locator('h2:has-text("Profit & Loss Statement")')).toBeVisible();
    await expect(page.locator('text=Revenue').first()).toBeVisible();
    await expect(page.locator('text=Gross Profit').first()).toBeVisible();

    // Assert P&L financial rows are rendered
    const pnlTable = page.locator('table').first();
    await expect(pnlTable).toBeVisible();
  });
});
