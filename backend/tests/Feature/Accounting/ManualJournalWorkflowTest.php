<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Enums\TenantRole;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use PDO;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Tests\TestCase;
use Throwable;

if (! function_exists('dropMjwApiTestTenantDatabase')) {
    function dropMjwApiTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_mjw_api_alpha'], true)) {
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

function loginAsMjwTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
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

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-31 10:00:00');
    app('cache')->flush();

    if (extension_loaded('redis')) {
        try {
            Cache::store('redis')->flush();
        } catch (Throwable) {
        }
    }

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropMjwApiTestTenantDatabase('tenant_mjw_api_alpha');

    Domain::query()->whereIn('host', ['mjwalpha.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['mjwalpha'])->delete();

    // --- Alpha Tenant ---
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'MJW Alpha Corp',
        'slug' => 'mjwalpha',
        'database_name' => 'tenant_mjw_api_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'mjwalpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@mjwalpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // --- Extra users in Alpha ---
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'mjwalpha',
        databaseName: 'tenant_mjw_api_alpha',
    ));

    $this->accountantUser = TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Alpha Accountant',
        'email' => 'accountant@mjwalpha.nexuserp.test',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropMjwApiTestTenantDatabase('tenant_mjw_api_alpha');
});

test('accountant can create a draft journal entry', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsMjwTenant($this, 'mjwalpha.nexuserp.test', 'accountant@mjwalpha.nexuserp.test');

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    $payload = [
        'entry_date' => '2026-10-31',
        'status' => 'draft',
        'description' => 'Draft depreciation journal',
        'currency' => 'USD',
        'lines' => [
            [
                'account_id' => $debitId,
                'debit_cents' => 50000,
                'credit_cents' => 0,
                'description' => 'Cash in',
            ],
            [
                'account_id' => $creditId,
                'debit_cents' => 0,
                'credit_cents' => 50000,
                'description' => 'Revenue earned',
            ],
        ],
    ];

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries', $payload);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.entry_number', null)
        ->assertJsonPath('data.total_debit_cents', 50000)
        ->assertJsonPath('data.total_credit_cents', 50000);
});

test('accountant can update a draft journal entry', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsMjwTenant($this, 'mjwalpha.nexuserp.test', 'accountant@mjwalpha.nexuserp.test');

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    $createResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-31',
            'status' => 'draft',
            'description' => 'Initial draft',
            'currency' => 'USD',
            'lines' => [
                ['account_id' => $debitId, 'debit_cents' => 20000, 'credit_cents' => 0],
                ['account_id' => $creditId, 'debit_cents' => 0, 'credit_cents' => 20000],
            ],
        ]);

    $entryId = $createResponse->json('data.id');

    $updateResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->putJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$entryId}", [
            'entry_date' => '2026-10-31',
            'description' => 'Updated draft note',
            'currency' => 'USD',
            'lines' => [
                ['account_id' => $debitId, 'debit_cents' => 35000, 'credit_cents' => 0],
                ['account_id' => $creditId, 'debit_cents' => 0, 'credit_cents' => 35000],
            ],
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.description', 'Updated draft note')
        ->assertJsonPath('data.total_debit_cents', 35000)
        ->assertJsonPath('data.total_credit_cents', 35000);
});

test('submitting draft sets pending approval and rejects imbalanced', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsMjwTenant($this, 'mjwalpha.nexuserp.test', 'accountant@mjwalpha.nexuserp.test');

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    // Create draft entry in tenant DB
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'mjwalpha',
        databaseName: 'tenant_mjw_api_alpha',
    ));

    $entry = JournalEntry::create([
        'entry_date' => '2026-10-31',
        'status' => JournalEntryStatus::Draft,
        'total_debit_cents' => 10000,
        'total_credit_cents' => 9000,
        'currency' => 'USD',
        'created_by_user_id' => $this->accountantUser->id,
    ]);
    $entry->lines()->create([
        'account_id' => $debitId,
        'line_number' => 1,
        'debit_cents' => 10000,
        'credit_cents' => 0,
    ]);
    $entry->lines()->create([
        'account_id' => $creditId,
        'line_number' => 2,
        'debit_cents' => 0,
        'credit_cents' => 9000,
    ]);

    $tenantManager->reset();

    // 1. Submit imbalanced -> 422
    $submitResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$entry->id}/submit");

    $submitResponse->assertUnprocessable()
        ->assertJsonPath('code', 'IMBALANCED_JOURNAL_ENTRY');

    // 2. Fix balance
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'mjwalpha',
        databaseName: 'tenant_mjw_api_alpha',
    ));

    $entry->update(['total_credit_cents' => 10000]);
    $entry->lines()->where('line_number', 2)->update(['credit_cents' => 10000]);

    $tenantManager->reset();

    // Submit balanced
    $submitResponseSuccess = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$entry->id}/submit");

    $submitResponseSuccess->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');
});

test('approving pending entry assigns gap free number and posts', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsMjwTenant($this, 'mjwalpha.nexuserp.test', 'accountant@mjwalpha.nexuserp.test');

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'mjwalpha',
        databaseName: 'tenant_mjw_api_alpha',
    ));

    $entry = JournalEntry::create([
        'entry_date' => '2026-10-31',
        'status' => JournalEntryStatus::PendingApproval,
        'total_debit_cents' => 10000,
        'total_credit_cents' => 10000,
        'currency' => 'USD',
        'created_by_user_id' => $this->accountantUser->id,
        'submitted_by_user_id' => $this->accountantUser->id,
        'submitted_at' => now(),
    ]);
    $entry->lines()->create([
        'account_id' => $debitId,
        'line_number' => 1,
        'debit_cents' => 10000,
        'credit_cents' => 0,
    ]);
    $entry->lines()->create([
        'account_id' => $creditId,
        'line_number' => 2,
        'debit_cents' => 0,
        'credit_cents' => 10000,
    ]);

    $tenantManager->reset();

    $approveResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$entry->id}/approve");

    $approveResponse->assertOk()
        ->assertJsonPath('data.status', 'posted');

    $entryNumber = $approveResponse->json('data.entry_number');
    expect($entryNumber)->toStartWith('JRN-2026-');
});

test('rejecting pending entry records reason and allows re editing', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsMjwTenant($this, 'mjwalpha.nexuserp.test', 'accountant@mjwalpha.nexuserp.test');

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'mjwalpha',
        databaseName: 'tenant_mjw_api_alpha',
    ));

    $entry = JournalEntry::create([
        'entry_date' => '2026-10-31',
        'status' => JournalEntryStatus::PendingApproval,
        'total_debit_cents' => 10000,
        'total_credit_cents' => 10000,
        'currency' => 'USD',
        'created_by_user_id' => $this->accountantUser->id,
        'submitted_by_user_id' => $this->accountantUser->id,
        'submitted_at' => now(),
    ]);
    $entry->lines()->create([
        'account_id' => $debitId,
        'line_number' => 1,
        'debit_cents' => 10000,
        'credit_cents' => 0,
    ]);
    $entry->lines()->create([
        'account_id' => $creditId,
        'line_number' => 2,
        'debit_cents' => 0,
        'credit_cents' => 10000,
    ]);

    $tenantManager->reset();

    $rejectResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$entry->id}/reject", [
            'rejection_reason' => 'Incorrect account code used for depreciation.',
        ]);

    $rejectResponse->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Incorrect account code used for depreciation.');

    // Edit the rejected entry
    $updateResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->putJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$entry->id}", [
            'entry_date' => '2026-10-31',
            'description' => 'Corrected journal after rejection',
            'currency' => 'USD',
            'lines' => [
                ['account_id' => $debitId, 'debit_cents' => 15000, 'credit_cents' => 0],
                ['account_id' => $creditId, 'debit_cents' => 0, 'credit_cents' => 15000],
            ],
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.description', 'Corrected journal after rejection');
});

test('deleting draft or rejected entry succeeds and deleting posted entry fails', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsMjwTenant($this, 'mjwalpha.nexuserp.test', 'accountant@mjwalpha.nexuserp.test');

    $cashResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $revenueResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://mjwalpha.nexuserp.test/api/v1/accounting/accounts?search=4000');

    $debitId = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: (string) $this->alphaTenant->id,
        slug: 'mjwalpha',
        databaseName: 'tenant_mjw_api_alpha',
    ));

    $draftEntry = JournalEntry::create([
        'entry_date' => '2026-10-31',
        'status' => JournalEntryStatus::Draft,
        'total_debit_cents' => 10000,
        'total_credit_cents' => 10000,
        'currency' => 'USD',
    ]);
    $draftEntry->lines()->create([
        'account_id' => $debitId,
        'line_number' => 1,
        'debit_cents' => 10000,
        'credit_cents' => 0,
    ]);

    $postedEntry = JournalEntry::create([
        'entry_number' => 'JRN-2026-999999',
        'entry_date' => '2026-10-31',
        'posting_date' => now(),
        'status' => JournalEntryStatus::Posted,
        'total_debit_cents' => 10000,
        'total_credit_cents' => 10000,
        'currency' => 'USD',
    ]);

    $tenantManager->reset();

    // 1. Delete draft
    $deleteResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->deleteJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$draftEntry->id}");

    $deleteResponse->assertOk();

    // 2. Delete posted -> forbidden by policy
    $deletePostedResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->deleteJson("http://mjwalpha.nexuserp.test/api/v1/accounting/journal-entries/{$postedEntry->id}");

    $deletePostedResponse->assertForbidden();
});
