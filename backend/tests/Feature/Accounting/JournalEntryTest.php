<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Events\JournalEntryPosted;
use Modules\Accounting\Events\JournalEntryReversed;
use Modules\Accounting\Models\Account;
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

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

if (! function_exists('dropJeApiTestTenantDatabase')) {
    function dropJeApiTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_je_api_alpha', 'tenant_je_api_beta'], true)) {
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

function loginAsJeTenant(mixed $testCase, string $host, string $email, string $password = 'Password123!'): string
{
    $testCase->flushSession();

    $response = $testCase->postJson("http://{$host}/api/v1/auth/login", [
        'email'    => $email,
        'password' => $password,
    ]);

    $response->assertStatus(200);

    $cookie = $response->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

// ---------------------------------------------------------------------------
// Tenant scaffolding (runs before every test in this file)
// ---------------------------------------------------------------------------

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
        '--path'     => 'database/migrations/central',
        '--force'    => true,
    ]);

    dropJeApiTestTenantDatabase('tenant_je_api_alpha');
    dropJeApiTestTenantDatabase('tenant_je_api_beta');

    Domain::query()->whereIn('host', ['jealpha.nexuserp.test', 'jebeta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['jealpha', 'jebeta'])->delete();

    // --- Alpha Tenant ---
    $alphaId = (string) Str::ulid();
    $this->alphaTenant = Tenant::on('central')->create([
        'id'            => $alphaId,
        'name'          => 'JE Alpha Corp',
        'slug'          => 'jealpha',
        'database_name' => 'tenant_je_api_alpha',
        'status'        => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host'      => 'jealpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@jealpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // --- Beta Tenant ---
    $betaId = (string) Str::ulid();
    $this->betaTenant = Tenant::on('central')->create([
        'id'            => $betaId,
        'name'          => 'JE Beta Corp',
        'slug'          => 'jebeta',
        'database_name' => 'tenant_je_api_beta',
        'status'        => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host'      => 'jebeta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@jebeta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // --- Extra users in Alpha ---
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(
        id: $alphaId,
        slug: 'jealpha',
        databaseName: 'tenant_je_api_alpha',
    ));

    // Accountant (has accounting.manage + reports.financial)
    $this->accountantUser = TenantUser::create([
        'id'                => (string) Str::ulid(),
        'name'              => 'Alpha Accountant',
        'email'             => 'accountant@jealpha.nexuserp.test',
        'password'          => Hash::make('Password123!'),
        'status'            => 'active',
        'email_verified_at' => now(),
    ]);
    $this->accountantUser->syncRoles([TenantRole::Accountant->value]);

    // Warehouse manager (no accounting.manage)
    $this->warehouseUser = TenantUser::create([
        'id'                => (string) Str::ulid(),
        'name'              => 'Alpha Warehouse',
        'email'             => 'warehouse@jealpha.nexuserp.test',
        'password'          => Hash::make('Password123!'),
        'status'            => 'active',
        'email_verified_at' => now(),
    ]);
    $this->warehouseUser->syncRoles([TenantRole::WarehouseManager->value]);

    // --- Beta owner password reset ---
    $tenantManager->switchTo(new TenantContext(
        id: $betaId,
        slug: 'jebeta',
        databaseName: 'tenant_je_api_beta',
    ));
    TenantUser::where('email', 'owner@jebeta.nexuserp.test')->update([
        'password'          => Hash::make('Password123!'),
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropJeApiTestTenantDatabase('tenant_je_api_alpha');
    dropJeApiTestTenantDatabase('tenant_je_api_beta');
});

// ---------------------------------------------------------------------------
// Helper: retrieve two system accounts (asset + revenue) for the alpha tenant
// ---------------------------------------------------------------------------

/**
 * Returns [debitAccountId, creditAccountId] for a balanced journal entry.
 * Uses accounts already seeded by ChartOfAccountsSeeder (1100 Cash, 4000 Revenue).
 */
function getAlphaAccountPair(mixed $testCase, string $sessionCookie): array
{
    /** @var TestCase $testCase */
    $cashResponse = $testCase->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://jealpha.nexuserp.test/api/v1/accounting/accounts?search=1100');
    $cashResponse->assertOk();

    $revenueResponse = $testCase->withCookie(config('session.cookie'), $sessionCookie)
        ->getJson('http://jealpha.nexuserp.test/api/v1/accounting/accounts?search=4000');
    $revenueResponse->assertOk();

    $debitId  = $cashResponse->json('data.0.id');
    $creditId = $revenueResponse->json('data.0.id');

    expect($debitId)->not->toBeNull('Cash account (1100) must exist after provisioning');
    expect($creditId)->not->toBeNull('Revenue account (4000) must exist after provisioning');

    return [$debitId, $creditId];
}

// ===========================================================================
// Seam 1 — POST journal entry: balanced happy path
// ===========================================================================

test('accountant can post a balanced journal entry and receives 201 with entry_number and posted status', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $cookie);

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date'  => '2026-10-31',
            'description' => 'Cash sale revenue recognition',
            'currency'    => 'USD',
            'lines'       => [
                ['account_id' => $debitId,  'debit_cents'  => 100_00],
                ['account_id' => $creditId, 'credit_cents' => 100_00],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_debit_cents', 10000)
        ->assertJsonPath('data.total_credit_cents', 10000)
        ->assertJsonPath('data.description', 'Cash sale revenue recognition')
        ->assertJsonPath('data.currency', 'USD');

    $entryNumber = $response->json('data.entry_number');
    expect($entryNumber)->toBeString()->not->toBeEmpty();

    // Two lines must be persisted
    expect($response->json('data.lines'))->toHaveCount(2);
});

// ===========================================================================
// Seam 2 — POST journal entry: imbalanced debits ≠ credits
// ===========================================================================

test('posting an imbalanced journal entry is rejected with 422 IMBALANCED_JOURNAL_ENTRY', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $cookie);

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-31',
            'currency'   => 'USD',
            'lines'      => [
                ['account_id' => $debitId,  'debit_cents'  => 150_00],
                ['account_id' => $creditId, 'credit_cents' => 100_00], // 50_00 short
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'IMBALANCED_JOURNAL_ENTRY');
});

// ===========================================================================
// Seam 3 — POST journal entry: inactive account is rejected
// ===========================================================================

test('posting to an inactive account is rejected with 400 ACCOUNT_INACTIVE', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $cookie);

    // Deactivate the debit account directly in the tenant DB
    /** @var TenantManager $tm */
    $tm = app(TenantManager::class);
    $tm->switchTo(new TenantContext(
        id: $this->alphaTenant->id,
        slug: 'jealpha',
        databaseName: 'tenant_je_api_alpha',
    ));
    Account::where('id', $debitId)->update(['is_active' => false]);
    $tm->reset();

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-31',
            'currency'   => 'USD',
            'lines'      => [
                ['account_id' => $debitId,  'debit_cents'  => 50_00],
                ['account_id' => $creditId, 'credit_cents' => 50_00],
            ],
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});

// ===========================================================================
// Seam 4 — POST reverse: reversal creates mirror entry
// ===========================================================================

test('accountant can reverse a posted journal entry and the reversal has swapped lines', function (): void {
    /** @var TestCase $this */
    Event::fake([JournalEntryReversed::class]);

    $cookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $cookie);

    // 1. Post original entry
    $postResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date'  => '2026-10-31',
            'description' => 'Original entry to reverse',
            'currency'    => 'USD',
            'lines'       => [
                ['account_id' => $debitId,  'debit_cents'  => 75_00],
                ['account_id' => $creditId, 'credit_cents' => 75_00],
            ],
        ]);
    $postResponse->assertStatus(201);
    $originalId = $postResponse->json('data.id');

    // 2. Reverse it
    $reverseResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://jealpha.nexuserp.test/api/v1/accounting/journal-entries/{$originalId}/reverse", [
            'reason' => 'Posted in error',
        ]);

    $reverseResponse->assertStatus(201)
        ->assertJsonPath('data.reversal_reason', 'Posted in error');

    $reversalId = $reverseResponse->json('data.id');
    expect($reversalId)->not->toEqual($originalId);

    // 3. Original entry must now show reversed_by_id
    $showOriginal = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson("http://jealpha.nexuserp.test/api/v1/accounting/journal-entries/{$originalId}");
    $showOriginal->assertOk()
        ->assertJsonPath('data.status', 'reversed');

    // 4. Reversal entry lines must be swapped
    $reversalLines = $reverseResponse->json('data.lines');
    expect($reversalLines)->toHaveCount(2);

    $debitLine  = collect($reversalLines)->firstWhere('account_id', $debitId);
    $creditLine = collect($reversalLines)->firstWhere('account_id', $creditId);

    // Original had debit on $debitId → reversal has credit on $debitId
    expect($debitLine['credit_cents'])->toBe(7500);
    expect($debitLine['debit_cents'])->toBe(0);

    // Original had credit on $creditId → reversal has debit on $creditId
    expect($creditLine['debit_cents'])->toBe(7500);
    expect($creditLine['credit_cents'])->toBe(0);

    Event::assertDispatched(JournalEntryReversed::class);
});

// ===========================================================================
// Seam 5 — POST reverse: reversing an already-reversed entry is rejected
// ===========================================================================

test('reversing an already-reversed journal entry is rejected with 400 ALREADY_REVERSED', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $cookie);

    // Post + reverse once
    $postResponse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-31',
            'currency'   => 'USD',
            'lines'      => [
                ['account_id' => $debitId,  'debit_cents'  => 20_00],
                ['account_id' => $creditId, 'credit_cents' => 20_00],
            ],
        ]);
    $postResponse->assertStatus(201);
    $originalId = $postResponse->json('data.id');

    $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://jealpha.nexuserp.test/api/v1/accounting/journal-entries/{$originalId}/reverse", [
            'reason' => 'First reversal',
        ])
        ->assertStatus(201);

    // Attempt second reversal on the same original
    $secondReverse = $this->withCookie(config('session.cookie'), $cookie)
        ->postJson("http://jealpha.nexuserp.test/api/v1/accounting/journal-entries/{$originalId}/reverse", [
            'reason' => 'Duplicate reversal attempt',
        ]);

    $secondReverse->assertStatus(422)
        ->assertJsonPath('code', 'JOURNAL_ALREADY_REVERSED');
});

// ===========================================================================
// Seam 6 — RBAC: warehouse manager cannot access journal-entry endpoints
// ===========================================================================

test('warehouse manager is denied access to journal entry endpoints with 403', function (): void {
    /** @var TestCase $this */

    // 1. Get account IDs while authenticated as accountant (do this FIRST before warehouse login)
    $accountantCookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');
    [$debitId, $creditId] = getAlphaAccountPair($this, $accountantCookie);

    // 2. Now log in as warehouse manager
    $warehouseCookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'warehouse@jealpha.nexuserp.test');

    // 3. Warehouse should be denied listing
    $listResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->getJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries');
    $listResponse->assertStatus(403);

    // 4. Warehouse should be denied posting
    $postResponse = $this->withCookie(config('session.cookie'), $warehouseCookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-31',
            'currency'   => 'USD',
            'lines'      => [
                ['account_id' => $debitId,  'debit_cents'  => 10_00],
                ['account_id' => $creditId, 'credit_cents' => 10_00],
            ],
        ]);
    $postResponse->assertStatus(403);
});

// ===========================================================================
// Seam 7 — Multi-tenant isolation: Alpha entries are invisible to Beta
// ===========================================================================

test('journal entries in Tenant Alpha are not visible to Tenant Beta', function (): void {
    /** @var TestCase $this */
    $alphaCookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $alphaCookie);

    // Post an entry as Alpha
    $postResponse = $this->withCookie(config('session.cookie'), $alphaCookie)
        ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
            'entry_date'  => '2026-10-31',
            'description' => 'Alpha-only confidential entry',
            'currency'    => 'USD',
            'lines'       => [
                ['account_id' => $debitId,  'debit_cents'  => 99_99],
                ['account_id' => $creditId, 'credit_cents' => 99_99],
            ],
        ]);
    $postResponse->assertStatus(201);
    $alphaEntryId = $postResponse->json('data.id');

    // Beta cannot list or show Alpha's entry
    $betaCookie = loginAsJeTenant($this, 'jebeta.nexuserp.test', 'owner@jebeta.nexuserp.test');

    $betaListResponse = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson('http://jebeta.nexuserp.test/api/v1/accounting/journal-entries');
    $betaListResponse->assertOk();
    expect($betaListResponse->json('data'))->toBeEmpty();

    $betaShowResponse = $this->withCookie(config('session.cookie'), $betaCookie)
        ->getJson("http://jebeta.nexuserp.test/api/v1/accounting/journal-entries/{$alphaEntryId}");
    $betaShowResponse->assertStatus(404);
});

// ===========================================================================
// Seam 8 — GET list: pagination meta is correct
// ===========================================================================

test('listing journal entries returns correct pagination meta', function (): void {
    /** @var TestCase $this */
    $cookie = loginAsJeTenant($this, 'jealpha.nexuserp.test', 'accountant@jealpha.nexuserp.test');

    [$debitId, $creditId] = getAlphaAccountPair($this, $cookie);

    // Post 15 entries (more than the default per_page=10 we will request)
    for ($i = 1; $i <= 15; $i++) {
        $this->withCookie(config('session.cookie'), $cookie)
            ->postJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries', [
                'entry_date' => '2026-10-31',
                'currency'   => 'USD',
                'lines'      => [
                    ['account_id' => $debitId,  'debit_cents'  => $i * 100],
                    ['account_id' => $creditId, 'credit_cents' => $i * 100],
                ],
            ])->assertStatus(201);
    }

    $response = $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('http://jealpha.nexuserp.test/api/v1/accounting/journal-entries?per_page=10&page=2');

    $response->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 10);

    expect($response->json('data'))->toHaveCount(5); // 15 total − 10 on page 1 = 5 on page 2
});
