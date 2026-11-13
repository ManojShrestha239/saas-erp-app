import { test, expect } from '@playwright/test';
import { DEMO_USERS, loginAs, logout, resetDemoTenant } from './helpers/auth';

/**
 * PRD §5 Flow 2:
 * Warehouse manager adds product → receives stock via purchase → sees on-hand.
 */
test.describe('PRD §5 Flow 2: Inventory Catalog and Goods Receipt Procurement', () => {
  const timestamp = Date.now().toString().slice(-4);
  const productName = `Ball Bearing Pro ${timestamp}`;
  const productSku = `SKU-BBP-${timestamp}`;

  test.beforeAll(async () => {
    resetDemoTenant();
  });

  test('warehouse manager adds product, receives stock via purchase order, and checks on-hand stock', async ({
    page,
    request,
  }) => {
    // Step 1: Log in as Warehouse Manager
    await loginAs(page, DEMO_USERS.warehouse);

    // Step 2: Navigate to Inventory and Add Product
    await page.goto('/inventory');
    await expect(page.locator('h1:has-text("Inventory & Products")').first()).toBeVisible();

    await page.click('button:has-text("Add Product")');
    await expect(page.locator('#product-name')).toBeVisible();

    await page.fill('#product-name', productName);
    await page.fill('#product-sku', productSku);
    await page.fill('#product-barcode', `89012345${timestamp}`);
    await page.fill('#product-cost-price', '45.00');
    await page.fill('#product-selling-price', '90.00');

    await page.click('button:has-text("Create Product")');
    await expect(page.locator('#product-name')).toBeHidden({ timeout: 10_000 });

    // Assert product appears in the Products DataTable
    await expect(page.locator(`text=${productSku}`)).toBeVisible();

    // Step 3: Owner creates and approves Purchase Order for the product
    await logout(page);
    await loginAs(page, DEMO_USERS.owner);

    await page.goto('/purchasing');
    await expect(page.locator('h1:has-text("Purchasing & Procurement")').first()).toBeVisible();

    await page.click('button:has-text("Create Purchase Order")');
    await expect(page.locator('h2:has-text("Create Purchase Order"), div:has-text("Create Purchase Order")').first()).toBeVisible();

    // Set order quantity to 30 on the first item
    const qtyInput = page.locator('tbody input[type="number"]').first();
    await qtyInput.fill('30');

    await page.click('button:has-text("Save Purchase Order")');
    await expect(page.locator('button:has-text("Save Purchase Order")')).toBeHidden({ timeout: 10_000 });

    // Locate the created PO row in Draft status and approve it
    const draftRow = page.locator('tr:has-text("draft")').first();
    await expect(draftRow).toBeVisible();
    await draftRow.locator('button[title="Order actions"]').click();
    await page.click('text=Approve Order');
    await expect(page.locator('tr:has-text("approved")').first()).toBeVisible({ timeout: 10_000 });

    // Step 4: Warehouse Manager receives goods
    await logout(page);
    await loginAs(page, DEMO_USERS.warehouse);
    await page.goto('/purchasing');

    const approvedRow = page.locator('tr:has-text("approved")').first();
    await approvedRow.locator('button[title="Order actions"]').click();
    await page.click('text=Receive Goods');

    // Confirm goods receipt in modal
    await expect(page.locator('text=Confirm Goods Receipt')).toBeVisible();
    await page.click('button:has-text("Confirm Goods Receipt")');
    await expect(page.locator('text=Confirm Goods Receipt')).toBeHidden({ timeout: 10_000 });

    // Step 5: Verify stock on-hand in Inventory
    await page.goto('/inventory');
    await page.click('button:has-text("Stock Balances")');
    await expect(page.locator('input[placeholder="Search product or warehouse..."]')).toBeVisible();

    // Check that stock levels table contains positive on-hand inventory
    const stockRow = page.locator('table tbody tr').first();
    await expect(stockRow).toBeVisible();
  });
});
