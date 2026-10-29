<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropSalesWebTestTenantDatabase')) {
    function dropSalesWebTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_sales_web_a', 'tenant_sales_web_b'], true)) {
            return;
        }

        try {
            $config = config('database.connections.central');
            /** @var array{host: string, port: int|string, username: string, password: string} $config */
            $pdo = new PDO(
                dsn: sprintf('pgsql:host=%s;port=%s;dbname=postgres', $config['host'], $config['port']),
                username: (string) $config['username'],
                password: (string) $config['password'],
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $pdo->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.
                $pdo->quote($dbName).' AND pid <> pg_backend_pid()');

            $quotedName = '"'.str_replace('"', '""', $dbName).'"';
            $pdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
        } catch (Throwable) {
            // Best-effort cleanup
        }
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-29 12:00:00');
    app('cache')->flush();

    dropSalesWebTestTenantDatabase('tenant_sales_web_a');
    dropSalesWebTestTenantDatabase('tenant_sales_web_b');

    Domain::query()->whereIn('host', ['sales-weba.nexuserp.test', 'sales-webb.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['sales-weba', 'sales-webb'])->delete();

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    // Alpha Tenant
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Sales Web A Corp',
        'slug' => 'sales-weba',
        'database_name' => 'tenant_sales_web_a',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'sales-weba.nexuserp.test',
        'is_primary' => true,
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@sales-weba.nexuserp.test',
        ownerName: 'Sales Web A Owner',
    );

    // Alpha User
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: $this->alphaTenant->slug,
        databaseName: $this->alphaTenant->database_name,
    ));

    $this->alphaUser = TenantUser::where('email', 'owner@sales-weba.nexuserp.test')->firstOrFail();
    $this->alphaUser->update([
        'password' => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    dropSalesWebTestTenantDatabase('tenant_sales_web_a');
    dropSalesWebTestTenantDatabase('tenant_sales_web_b');
    Domain::query()->whereIn('host', ['sales-weba.nexuserp.test', 'sales-webb.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['sales-weba', 'sales-webb'])->delete();
});

function loginAsSalesWebUser(mixed $testCase, string $host, string $email = 'owner@sales-weba.nexuserp.test', string $password = 'Password123!'): string
{
    $testCase->flushSession();

    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

test('unauthenticated user is redirected from /quotations and /invoices to /login', function (): void {
    $resQuotations = $this->get('http://sales-weba.nexuserp.test/quotations');
    $resQuotations->assertRedirect('/login');

    $resInvoices = $this->get('http://sales-weba.nexuserp.test/invoices');
    $resInvoices->assertRedirect('/login');
});

test('authenticated tenant user can access /quotations and /invoices and receives Inertia page response', function (): void {
    $sessionCookie = loginAsSalesWebUser($this, 'sales-weba.nexuserp.test');

    $resQuotations = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://sales-weba.nexuserp.test/quotations');

    $resQuotations->assertOk();
    $resQuotations->assertInertia(fn (AssertableInertia $page) => $page->component('sales/QuotationsIndex'));

    $resInvoices = $this->withCookie(config('session.cookie'), $sessionCookie)
        ->get('http://sales-weba.nexuserp.test/invoices');

    $resInvoices->assertOk();
    $resInvoices->assertInertia(fn (AssertableInertia $page) => $page->component('sales/InvoicesIndex'));
});
