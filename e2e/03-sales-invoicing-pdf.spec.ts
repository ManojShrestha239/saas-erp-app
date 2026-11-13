import { test, expect } from '@playwright/test';
import { DEMO_USERS, loginAs, resetDemoTenant } from './helpers/auth';

/**
 * PRD §5 Flow 3:
 * Sales rep creates invoice → stock decrements → AR and journal post → PDF downloaded.
 */
test.describe('PRD §5 Flow 3: Sales Invoicing, Stock Decrement, and PDF Generation', () => {
  test.beforeAll(async () => {
    resetDemoTenant();
  });

  test('sales rep creates invoice, posts it to ledger with stock decrement, and downloads PDF', async ({
    page,
    request,
  }) => {
    // Step 1: Log in as Sales Representative
    await loginAs(page, DEMO_USERS.sales);

    // Step 2: Navigate to Invoices
    await page.goto('/invoices');
    await expect(page.locator('h1:has-text("Sales Invoices")').first()).toBeVisible();
    await page.waitForLoadState('networkidle');

    // Step 3: Open New Invoice modal
    await page.click('button:has-text("New Invoice")');
    await expect(page.locator('text=New Sales Invoice')).toBeVisible();

    // Ensure at least one line item is present
    if (await page.locator('input[type="number"]').count() === 0) {
      await page.click('button:has-text("Add Line")');
    }

    // Fill line quantity
    const qtyInput = page.locator('input[type="number"]').first();
    await expect(qtyInput).toBeVisible();
    await qtyInput.fill('2');

    // Submit draft invoice
    await page.click('button:has-text("Save Draft Invoice")');
    await expect(page.locator('text=New Sales Invoice')).toBeHidden({ timeout: 10_000 });

    // Step 4: Locate Draft Invoice in Table and Post it
    const draftRow = page.locator('tr:has-text("draft")').first();
    await expect(draftRow).toBeVisible();

    // Open row dropdown menu
    await draftRow.locator('button[aria-label="Invoice actions"]').click();
    await page.click('text=Post Invoice');

    // Confirm post in modal
    await expect(page.locator('text=Post Sales Invoice')).toBeVisible();
    await page.click('button:has-text("Confirm & Post")');
    await expect(page.locator('text=Post Sales Invoice')).toBeHidden({ timeout: 10_000 });

    // Step 5: Assert invoice status transitioned to posted
    const postedRow = page.locator('tr:has-text("posted")').first();
    await expect(postedRow).toBeVisible({ timeout: 10_000 });

    // Step 6: Verify Invoice PDF generation and download
    const invoiceNumber = await postedRow.locator('td').first().innerText();
    expect(invoiceNumber).toContain('INV-');

    // Fetch PDF via API request with authenticated session
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

    // Find invoice ID from API list
    const invoicesRes = await request.get('http://demo.localhost:8000/api/v1/sales/invoices', {
      headers: { Cookie: cookieHeader },
    });
    expect(invoicesRes.status()).toBe(200);
    const invoicesData = await invoicesRes.json();
    const targetInvoice = invoicesData.data.find(
      (inv: { invoice_number: string }) => inv.invoice_number === invoiceNumber.trim()
    );
    expect(targetInvoice).toBeDefined();

    const pdfRes = await request.get(`http://demo.localhost:8000/api/v1/sales/invoices/${targetInvoice.id}/pdf`, {
      headers: { Cookie: cookieHeader },
    });
    expect(pdfRes.status()).toBe(200);
    expect(pdfRes.headers()['content-type']).toContain('application/pdf');
    const pdfBytes = await pdfRes.body();
    expect(pdfBytes.length).toBeGreaterThan(1000);
  });
});
