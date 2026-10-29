<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

function dropPaymentWebTestTenantDatabase(string $dbName): void
{
    $config = config('database.connections.central');
    if (is_array($config)) {
        try {
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname=postgres";
            $pdo = new PDO(
                $dsn,
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

function loginAsPaymentWebTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
{
    $testCase->flushSession();

    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    if ($cookie === null) {
        throw new \RuntimeException('Session cookie not found in response');
    }

    return $cookie->getValue();
}

final class PaymentWebRouteTest extends TestCase
{
    private Tenant $tenant;

    private string $ownerCookie;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-29 12:00:00');
        app('cache')->flush();

        dropPaymentWebTestTenantDatabase('tenant_payment_web');

        Domain::query()->whereIn('host', ['web-pay.nexuserp.test'])->delete();
        Tenant::query()->whereIn('slug', ['web-pay'])->delete();

        Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--realpath' => false,
            '--force' => true,
        ]);

        $tenantId = (string) Str::ulid();
        $this->tenant = Tenant::on('central')->create([
            'id' => $tenantId,
            'name' => 'Web Payment Corp',
            'slug' => 'web-pay',
            'database_name' => 'tenant_payment_web',
            'status' => 'provisioning',
        ]);
        Domain::on('central')->create([
            'tenant_id' => $tenantId,
            'host' => 'web-pay.nexuserp.test',
            'is_primary' => true,
        ]);
        ProvisionTenantDatabase::dispatchSync(
            tenantId: $tenantId,
            ownerEmail: 'owner@web-pay.nexuserp.test',
            ownerName: 'Web Pay Owner',
        );

        $tenantManager = app(TenantManager::class);
        $tenantManager->switchTo(new TenantContext(
            id: $this->tenant->id,
            slug: $this->tenant->slug,
            databaseName: $this->tenant->database_name,
        ));

        $owner = TenantUser::where('email', 'owner@web-pay.nexuserp.test')->firstOrFail();
        $owner->update([
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);

        $tenantManager->reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        dropPaymentWebTestTenantDatabase('tenant_payment_web');
        Domain::query()->whereIn('host', ['web-pay.nexuserp.test'])->delete();
        Tenant::query()->whereIn('slug', ['web-pay'])->delete();
        parent::tearDown();
    }

    public function test_unauthenticated_user_is_redirected_from_payment_routes_to_login(): void
    {
        $responseReceipts = $this->get('http://web-pay.nexuserp.test/customer-receipts');
        $responseReceipts->assertRedirect('/login');

        $responsePayments = $this->get('http://web-pay.nexuserp.test/supplier-payments');
        $responsePayments->assertRedirect('/login');
    }

    public function test_authenticated_tenant_user_can_access_payment_pages_and_renders_inertia(): void
    {
        $ownerCookie = loginAsPaymentWebTenant($this, 'web-pay.nexuserp.test', 'owner@web-pay.nexuserp.test');

        $responseReceipts = $this->withCookie(config('session.cookie'), $ownerCookie)
            ->get('http://web-pay.nexuserp.test/customer-receipts');
        $responseReceipts->assertOk();
        $responseReceipts->assertInertia(fn (Assert $page) => $page->component('payments/CustomerReceiptsIndex'));

        $responsePayments = $this->withCookie(config('session.cookie'), $ownerCookie)
            ->get('http://web-pay.nexuserp.test/supplier-payments');
        $responsePayments->assertOk();
        $responsePayments->assertInertia(fn (Assert $page) => $page->component('payments/SupplierPaymentsIndex'));
    }
}
