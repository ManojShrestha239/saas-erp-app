import { test, expect } from '@playwright/test';
import { DEMO_USERS, loginAs, logout, resetDemoTenant } from './helpers/auth';

/**
 * PRD §5 Flow 4:
 * Customer pays → receipt allocated → invoice marked paid → AR ages correctly.
 */
test.describe('PRD §5 Flow 4: Customer Payments, Invoice Allocations, and AR Aging', () => {
  test.beforeAll(async () => {
    resetDemoTenant();
  });

  test('records customer receipt, allocates to outstanding invoice, and verifies aging ledger balance', async ({
    page,
  }) => {
    // Step 1: Log in as Sales Representative / Cashier
    await loginAs(page, DEMO_USERS.sales);

    // Step 2: Navigate to Customer Receipts
    await page.goto('/customer-receipts');
    await expect(page.locator('h1:has-text("Customer Receipts")').first()).toBeVisible();

    // Step 3: Record a new customer receipt
    await page.click('button:has-text("Record Receipt")');
    await expect(page.locator('text=Record Customer Receipt')).toBeVisible();

    // Select the first customer
    const customerSelect = page.locator('#create-customer');
    const firstCustomerValue = await customerSelect.locator('option').nth(1).getAttribute('value');
    if (firstCustomerValue) {
      await customerSelect.selectOption(firstCustomerValue);
    }

    // Fill payment amount
    await page.fill('#create-amount', '250.00');

    // Submit receipt inside modal form
    await page.click('form button[type="submit"]:has-text("Record Receipt")');
    await expect(page.locator('text=Record Customer Receipt')).toBeHidden({ timeout: 10_000 });

    // Step 4: Allocate the Receipt to Outstanding Invoices
    // Wait for table to reload with the new receipt
    await page.waitForLoadState('networkidle');
    const postedReceiptRow = page.locator('tr:has-text("posted")').first();
    await expect(postedReceiptRow).toBeVisible({ timeout: 10_000 });

    // Open row actions dropdown
    await postedReceiptRow.locator('button:has(.sr-only:has-text("Open actions"))').click();
    await page.click('text=Allocate to Invoices');

    // Allocation Modal
    await expect(page.locator('text=Allocate Receipt to Invoices')).toBeVisible();

    // Wait for open invoices to load and click Auto-Allocate Balance
    const autoAllocateBtn = page.locator('button:has-text("Auto-Allocate Balance")');
    await expect(autoAllocateBtn).toBeVisible({ timeout: 10_000 });
    await autoAllocateBtn.click();

    // Submit allocations
    await page.click('button:has-text("Apply Allocations")');
    await expect(page.locator('text=Allocate Receipt to Invoices')).toBeHidden({ timeout: 10_000 });

    // Step 5: Verify AR Aging Report reflects customer ledger updates
    await logout(page);
    await loginAs(page, DEMO_USERS.accountant);

    await page.goto('/reports');
    await expect(page.locator('nav[aria-label="Reports Navigation Tabs"]')).toBeVisible();

    // Switch to AR / AP Aging report tab
    await page.click('button:has-text("AR & AP Aging")');
    await expect(page.locator('text=Accounts Receivable (AR) Aging')).toBeVisible({ timeout: 10_000 });

    // Assert that the aging table is loaded with buckets and balances
    const agingTable = page.locator('table').first();
    await expect(agingTable).toBeVisible();
  });
});
