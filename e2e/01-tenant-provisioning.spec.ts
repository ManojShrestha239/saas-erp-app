import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { EXEC_ENV, runQueuedJobs } from './helpers/auth';

/**
 * PRD §5 Flow 1:
 * Tenant signs up → database provisioned → owner logs in.
 */
test.describe('PRD §5 Flow 1: Tenant Provisioning and Owner Login', () => {
  const timestamp = Date.now();
  const slug = `corp${timestamp.toString().slice(-6)}`;
  const companyName = `Apex Global ${timestamp.toString().slice(-4)}`;
  const ownerEmail = `owner.${slug}@gmail.com`;
  const ownerPassword = 'ValidPassword123!';

  test.afterAll(async () => {
    // Clean up provisioned tenant database and central record
    try {
      execSync(
        `docker compose exec -T app php artisan tinker --execute="
          \\$t = \\Modules\\Tenancy\\Models\\Tenant::on('central')->where('slug', '${slug}')->first();
          if (\\$t) {
            \\Illuminate\\Support\\Facades\\DB::connection('central')->statement('DROP DATABASE IF EXISTS ' . \\$t->database_name);
            \\Modules\\Tenancy\\Models\\Domain::on('central')->where('tenant_id', \\$t->id)->delete();
            \\$t->delete();
          }
        "`,
        { env: EXEC_ENV, stdio: 'pipe' }
      );
    } catch {
      // ignore cleanup errors
    }
  });

  test('signs up new tenant, provisions database, and owner logs in', async ({ page, request }) => {
    // Step 1: Sign up new tenant via central registration API
    const response = await request.post('http://localhost:8000/api/v1/tenants', {
      data: {
        name: companyName,
        slug: slug,
        owner_email: ownerEmail,
      },
    });

    expect(response.status()).toBe(202);
    const body = await response.json();
    expect(body.success).toBe(true);
    expect(body.data.slug).toBe(slug);
    expect(body.data.status).toBe('provisioning');

    // Step 2: Run queued provisioning job
    runQueuedJobs(1);

    // Step 3: Verify tenant status is active and set owner password in tenant DB
    execSync(
      `docker compose exec -T app php artisan tinker --execute="
        \\$t = \\Modules\\Tenancy\\Models\\Tenant::on('central')->where('slug', '${slug}')->first();
        if (!\\$t || \\$t->status !== 'active') {
          throw new \\RuntimeException('Tenant not active');
        }
        \\Illuminate\\Support\\Facades\\DB::connection('central')->table('domains')->insertOrIgnore([
          'tenant_id' => \\$t->id,
          'host' => '${slug}.localhost',
          'is_primary' => false,
          'created_at' => now(),
          'updated_at' => now(),
        ]);
        \\Shared\\Http\\Middleware\\IdentifyTenant::invalidateCache('${slug}');
        \\Shared\\Http\\Middleware\\IdentifyTenant::invalidateCache('${slug}.localhost');
        \\$manager = app(\\Shared\\Tenancy\\TenantManager::class);
        \\$manager->switchTo(new \\Shared\\Tenancy\\TenantContext(\\$t->id, \\$t->slug, \\$t->database_name));
        \\Illuminate\\Support\\Facades\\DB::connection('tenant')->table('users')->where('email', '${ownerEmail}')->update([
          'password' => \\Illuminate\\Support\\Facades\\Hash::make('${ownerPassword}'),
          'email_verified_at' => now(),
          'status' => 'active',
        ]);
        \\$manager->reset();
      "`,
      { env: EXEC_ENV, stdio: 'pipe' }
    );

    // Step 4: Owner logs into their tenant portal on {slug}.localhost:8000
    const tenantLoginUrl = `http://${slug}.localhost:8000/login`;
    await page.goto(tenantLoginUrl);
    await expect(page.locator('#email')).toBeVisible();

    await page.fill('#email', ownerEmail);
    await page.fill('#password', ownerPassword);
    await page.click('button[type="submit"]');

    // Step 5: Verify owner is successfully redirected to their tenant dashboard
    await page.waitForURL(`http://${slug}.localhost:8000/dashboard`, { timeout: 15_000 });
    await expect(page.locator('text=Dashboard').first()).toBeVisible();
  });
});
