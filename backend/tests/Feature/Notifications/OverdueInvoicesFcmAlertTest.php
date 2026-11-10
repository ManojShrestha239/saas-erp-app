<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\FcmDeviceToken;
use Modules\Auth\Models\TenantUser;
use Modules\Contacts\Models\Contact;
use Modules\Sales\Commands\SendOverdueInvoicesFcmAlertCommand;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Jobs\SendOverdueInvoicesFcmAlertJob;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Services\OverdueInvoiceAlertService;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

if (! function_exists('dropOverdueFcmTestDatabase')) {
    function dropOverdueFcmTestDatabase(string $dbName): void
    {
        if ($dbName !== 'tenant_fcm_overdue') {
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
    app('cache')->flush();
    try {
        Cache::store('redis')->flush();
    } catch (Throwable) {
        // Redis store might not be available
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropOverdueFcmTestDatabase('tenant_fcm_overdue');
    Domain::query()->where('host', 'overdue-fcm.nexuserp.test')->delete();
    Tenant::query()->where('slug', 'overdue-fcm')->delete();

    $tenantId = (string) Str::ulid();
    $tenant = Tenant::on('central')->create([
        'id' => $tenantId,
        'name' => 'Overdue FCM Corp',
        'slug' => 'overdue-fcm',
        'database_name' => 'tenant_fcm_overdue',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $tenantId,
        'host' => 'overdue-fcm.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $tenantId,
        ownerEmail: 'owner@overdue-fcm.nexuserp.test',
        ownerName: 'Overdue Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $tenant->id,
        slug: $tenant->slug,
        databaseName: $tenant->database_name,
    ));

    TenantUser::where('email', 'owner@overdue-fcm.nexuserp.test')->update([
        'password' => Hash::make('Password12345!'),
        'email_verified_at' => now(),
    ]);

    $this->owner = TenantUser::where('email', 'owner@overdue-fcm.nexuserp.test')->firstOrFail();

    // Create Accountant user
    $this->accountant = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'FCM Accountant',
        'email' => 'accountant@overdue-fcm.nexuserp.test',
        'password' => Hash::make('Password12345!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountant->assignRole(TenantRole::Accountant->value);

    // Create Customer
    $this->customer = Contact::create([
        'id' => (string) Str::ulid(),
        'type' => 'customer',
        'code' => 'CUST-0001',
        'name' => 'Acme Supplies',
        'is_active' => true,
    ]);

    $this->tenant = $tenant;
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropOverdueFcmTestDatabase('tenant_fcm_overdue');
});

test('OverdueInvoiceAlertService notifies accountants and owners of overdue sales invoices', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
        'services.fcm.api_url' => 'https://fcm.googleapis.com/v1/projects',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/ok_msg',
        ], 200),
    ]);

    // Register tokens for Accountant and Owner
    FcmDeviceToken::create([
        'user_id' => $this->accountant->id,
        'token' => 'accountant_token_123',
        'platform' => 'web',
        'last_used_at' => now(),
    ]);
    FcmDeviceToken::create([
        'user_id' => $this->owner->id,
        'token' => 'owner_token_456',
        'platform' => 'ios',
        'last_used_at' => now(),
    ]);

    // Create Overdue Invoice: due 10 days ago, $500 total, $200 paid, $300 due
    SalesInvoice::create([
        'id' => (string) Str::ulid(),
        'invoice_number' => 'INV-2026-000001',
        'customer_id' => $this->customer->id,
        'status' => SalesInvoiceStatus::PartiallyPaid,
        'issue_date' => Carbon::today()->subDays(40),
        'due_date' => Carbon::today()->subDays(10),
        'currency' => 'USD',
        'subtotal_cents' => 45000,
        'tax_cents' => 5000,
        'total_cents' => 50000,
        'amount_paid_cents' => 20000,
        'amount_due_cents' => 30000,
        'posted_at' => Carbon::today()->subDays(40),
    ]);

    // Create Current (Not Overdue) Invoice: due in 15 days
    SalesInvoice::create([
        'id' => (string) Str::ulid(),
        'invoice_number' => 'INV-2026-000002',
        'customer_id' => $this->customer->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => Carbon::today(),
        'due_date' => Carbon::today()->addDays(15),
        'currency' => 'USD',
        'subtotal_cents' => 20000,
        'tax_cents' => 0,
        'total_cents' => 20000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 20000,
        'posted_at' => Carbon::today(),
    ]);

    /** @var OverdueInvoiceAlertService $service */
    $service = app(OverdueInvoiceAlertService::class);
    $result = $service->sendOverdueAlerts();

    expect($result['sent'])->toBeTrue()
        ->and($result['overdue_count'])->toBe(1)
        ->and($result['total_overdue_cents'])->toBe(30000);

    Http::assertSent(function ($request): bool {
        return str_contains($request['message']['notification']['title'], 'Overdue Invoices Alert')
            && str_contains($request['message']['notification']['body'], '1 customer invoice(s) totaling $300.00 are currently overdue.')
            && $request['message']['data']['type'] === 'overdue_invoices_alert'
            && in_array($request['message']['token'], ['accountant_token_123', 'owner_token_456'], true);
    });
});

test('alerts:send-overdue-invoices console command runs and notifies target tenant', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
        'services.fcm.api_url' => 'https://fcm.googleapis.com/v1/projects',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/ok_msg',
        ], 200),
    ]);

    FcmDeviceToken::create([
        'user_id' => $this->accountant->id,
        'token' => 'accountant_token_789',
        'platform' => 'web',
        'last_used_at' => now(),
    ]);

    SalesInvoice::create([
        'id' => (string) Str::ulid(),
        'invoice_number' => 'INV-2026-000099',
        'customer_id' => $this->customer->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => Carbon::today()->subDays(30),
        'due_date' => Carbon::today()->subDays(5),
        'currency' => 'USD',
        'subtotal_cents' => 10000,
        'tax_cents' => 0,
        'total_cents' => 10000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 10000,
        'posted_at' => Carbon::today()->subDays(30),
    ]);

    $exitCode = Artisan::call(SendOverdueInvoicesFcmAlertCommand::class, [
        '--tenant' => $this->tenant->slug,
    ]);

    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Sent overdue alert for 1 invoice(s)');

    Http::assertSent(function ($request): bool {
        return $request['message']['token'] === 'accountant_token_789';
    });
});

test('SendOverdueInvoicesFcmAlertJob runs asynchronously within tenant context', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
        'services.fcm.api_url' => 'https://fcm.googleapis.com/v1/projects',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/ok_msg',
        ], 200),
    ]);

    FcmDeviceToken::create([
        'user_id' => $this->owner->id,
        'token' => 'owner_job_token',
        'platform' => 'web',
        'last_used_at' => now(),
    ]);

    SalesInvoice::create([
        'id' => (string) Str::ulid(),
        'invoice_number' => 'INV-2026-000088',
        'customer_id' => $this->customer->id,
        'status' => SalesInvoiceStatus::Posted,
        'issue_date' => Carbon::today()->subDays(20),
        'due_date' => Carbon::today()->subDays(2),
        'currency' => 'USD',
        'subtotal_cents' => 25000,
        'tax_cents' => 0,
        'total_cents' => 25000,
        'amount_paid_cents' => 0,
        'amount_due_cents' => 25000,
        'posted_at' => Carbon::today()->subDays(20),
    ]);

    // Dispatch job synchronously
    SendOverdueInvoicesFcmAlertJob::dispatchSync(
        tenantId: $this->tenant->id,
        tenantSlug: $this->tenant->slug,
        tenantDatabaseName: $this->tenant->database_name,
    );

    Http::assertSent(function ($request): bool {
        return $request['message']['token'] === 'owner_job_token'
            && str_contains($request['message']['notification']['body'], '$250.00');
    });
});
