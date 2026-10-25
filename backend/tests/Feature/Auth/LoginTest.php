<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;

if (! function_exists('dropAuthTestTenantDatabase')) {
    function dropAuthTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha', 'tenant_beta'], true)) {
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
    Cache::store('redis')->flush();
    RateLimiter::clear('login:*');

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropAuthTestTenantDatabase('tenant_alpha');
    dropAuthTestTenantDatabase('tenant_beta');

    Domain::query()->whereIn('host', ['alpha.nexuserp.test', 'beta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha', 'beta'])->delete();

    // 1. Provision alpha tenant
    $alphaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha Corp',
        'slug' => 'alpha',
        'database_name' => 'tenant_alpha',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision beta tenant
    $betaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta Corp',
        'slug' => 'beta',
        'database_name' => 'tenant_beta',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    // 3. Set known passwords for testing on tenant databases
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    $alphaContext = new TenantContext(id: $alphaId, slug: 'alpha', databaseName: 'tenant_alpha');
    $tenantManager->switchTo($alphaContext);
    DB::connection('tenant')->table('users')->where('email', 'owner@alpha.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
    ]);
    // Also insert a cross-tenant user with the same email on alpha
    DB::connection('tenant')->table('users')->insert([
        'id' => (string) Str::ulid(),
        'name' => 'Shared User',
        'email' => 'user@example.com',
        'password' => Hash::make('AlphaPass12345'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $betaContext = new TenantContext(id: $betaId, slug: 'beta', databaseName: 'tenant_beta');
    $tenantManager->switchTo($betaContext);
    DB::connection('tenant')->table('users')->where('email', 'owner@beta.nexuserp.test')->update([
        'password' => Hash::make('BetaSecret123!'),
    ]);
    // Insert the same email on beta with different password
    DB::connection('tenant')->table('users')->insert([
        'id' => (string) Str::ulid(),
        'name' => 'Shared User',
        'email' => 'user@example.com',
        'password' => Hash::make('BetaPass12345!'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantManager->reset();
    TenantContext::set(null);
});

afterEach(function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->reset();

    dropAuthTestTenantDatabase('tenant_alpha');
    dropAuthTestTenantDatabase('tenant_beta');

    Domain::query()->whereIn('host', ['alpha.nexuserp.test', 'beta.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha', 'beta'])->delete();

    TenantContext::set(null);
    app('cache')->flush();
    Cache::store('redis')->flush();
});

test('successful tenant login returns 200 with standard envelope and sets session cookie', function (): void {
    $response = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'user' => [
                'email' => 'owner@alpha.nexuserp.test',
                'name' => 'Alpha Owner',
            ],
        ],
    ]);

    $sessionCookie = $response->getCookie(config('session.cookie'));
    expect($sessionCookie)->not->toBeNull();
});

test('unknown email and wrong password return identical 401 INVALID_CREDENTIALS responses to prevent user enumeration', function (): void {
    $wrongPasswordResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'WrongPassword123!',
    ]);

    $unknownEmailResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'nonexistent@alpha.nexuserp.test',
        'password' => 'WrongPassword123!',
    ]);

    $wrongPasswordResponse->assertStatus(401);
    $unknownEmailResponse->assertStatus(401);

    expect($wrongPasswordResponse->json())->toBe([
        'success' => false,
        'message' => 'Invalid credentials.',
        'errors' => [],
        'code' => 'INVALID_CREDENTIALS',
    ]);

    // Exact match between wrong password and unknown email responses
    expect($wrongPasswordResponse->json())->toEqual($unknownEmailResponse->json());
    expect($wrongPasswordResponse->status())->toBe($unknownEmailResponse->status());
});

test('login requests are throttled after repeated failures with 429 TOO_MANY_ATTEMPTS', function (): void {
    // 5 failed attempts
    for ($i = 0; $i < 5; $i++) {
        $res = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])
            ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
                'email' => 'owner@alpha.nexuserp.test',
                'password' => 'WrongPassword123!',
            ]);
        $res->assertStatus(401);
    }

    // 6th attempt must be throttled
    $throttledResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
            'email' => 'owner@alpha.nexuserp.test',
            'password' => 'WrongPassword123!',
        ]);

    $throttledResponse->assertStatus(429);
    $throttledResponse->assertJson([
        'success' => false,
        'message' => 'Too many login attempts. Please try again later.',
        'errors' => [],
        'code' => 'TOO_MANY_ATTEMPTS',
    ]);
    expect($throttledResponse->headers->has('Retry-After'))->toBeTrue();
});

test('login throttling is isolated per tenant so lock on alpha does not affect beta', function (): void {
    // Lock user@example.com on alpha
    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
                'email' => 'user@example.com',
                'password' => 'WrongPassword123!',
            ])->assertStatus(401);
    }

    // 6th attempt on alpha is throttled
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'WrongPassword123!',
        ])->assertStatus(429);

    // The SAME email and SAME IP on beta is NOT throttled and can log in with beta credentials
    $betaResponse = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->postJson('http://beta.nexuserp.test/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'BetaPass12345!',
        ]);

    $betaResponse->assertStatus(200);
    $betaResponse->assertJson([
        'success' => true,
        'message' => 'Login successful.',
    ]);
});

test('suspended tenant cannot log in and returns 403 TENANT_SUSPENDED before auth runs', function (): void {
    Tenant::on('central')->where('slug', 'beta')->update(['status' => 'suspended']);

    $response = $this->postJson('http://beta.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@beta.nexuserp.test',
        'password' => 'BetaSecret123!',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'code' => 'TENANT_SUSPENDED',
    ]);
});

test('login validation fails with 422 VALIDATION_FAILED when password does not meet complexity rules', function (): void {
    // 1. Password less than 12 characters
    $shortResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'Short123',
    ]);
    $shortResponse->assertStatus(422);
    $shortResponse->assertJson([
        'success' => false,
        'code' => 'VALIDATION_FAILED',
    ]);
    expect($shortResponse->json('errors.password'))->not->toBeEmpty();

    // 2. Password 12+ characters with letters only (missing number)
    $lettersOnlyResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'LettersOnlyNoDigits',
    ]);
    $lettersOnlyResponse->assertStatus(422);
    $lettersOnlyResponse->assertJson([
        'success' => false,
        'code' => 'VALIDATION_FAILED',
    ]);
    expect($lettersOnlyResponse->json('errors.password'))->not->toBeEmpty();

    // 3. Password 12+ characters with numbers only (missing letter)
    $numbersOnlyResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => '1234567890123',
    ]);
    $numbersOnlyResponse->assertStatus(422);
    $numbersOnlyResponse->assertJson([
        'success' => false,
        'code' => 'VALIDATION_FAILED',
    ]);
    expect($numbersOnlyResponse->json('errors.password'))->not->toBeEmpty();
});

test('session ID changes on login to prevent session fixation', function (): void {
    // 1. Get an initial session cookie before authenticating
    $preResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'WrongPassword123!',
    ]);
    $preCookie = $preResponse->getCookie(config('session.cookie'));
    expect($preCookie)->not->toBeNull();

    // 2. Perform successful login presenting the pre-existing session cookie
    $loginResponse = $this->withUnencryptedCookie(config('session.cookie'), $preCookie->getValue())
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
            'email' => 'owner@alpha.nexuserp.test',
            'password' => 'AlphaSecret123!',
        ]);

    $loginResponse->assertStatus(200);
    $postCookie = $loginResponse->getCookie(config('session.cookie'));
    expect($postCookie)->not->toBeNull();

    // The session cookie must have regenerated on login (different value and different session ID)
    expect($postCookie->getValue())->not->toBe($preCookie->getValue());
});

test('the old session cookie is rejected after logout', function (): void {
    // 1. Log in on alpha
    $loginResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);

    $loginResponse->assertStatus(200);
    $cookie = $loginResponse->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    // 2. Log out using the active session cookie
    $logoutResponse = $this->withCookie(config('session.cookie'), $cookie->getValue())
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/logout');

    $logoutResponse->assertStatus(200);
    $logoutResponse->assertJson([
        'success' => true,
        'message' => 'Logged out successfully.',
    ]);

    // 3. Subsequent request with the now-invalidated old session cookie is rejected
    $replayResponse = $this->withCookie(config('session.cookie'), $cookie->getValue())
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/logout');

    $replayResponse->assertStatus(401);
    $replayResponse->assertJson([
        'success' => false,
        'code' => 'UNAUTHENTICATED',
    ]);
});

test('email throttle key is case-insensitive so Ann@ and ann@ share the same limit', function (): void {
    // 3 failed attempts with uppercase/mixed case
    for ($i = 0; $i < 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.100.25'])
            ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
                'email' => 'Owner@alpha.nexuserp.test',
                'password' => 'WrongPassword123!',
            ])->assertStatus(401);
    }

    // 2 failed attempts with ALL CAPS
    for ($i = 0; $i < 2; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.100.25'])
            ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
                'email' => 'OWNER@ALPHA.NEXUSERP.TEST',
                'password' => 'WrongPassword123!',
            ])->assertStatus(401);
    }

    // 6th attempt with all lowercase must hit the throttle (429)
    $throttledResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.100.25'])
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
            'email' => 'owner@alpha.nexuserp.test',
            'password' => 'WrongPassword123!',
        ]);

    $throttledResponse->assertStatus(429);
    $throttledResponse->assertJson([
        'success' => false,
        'code' => 'TOO_MANY_ATTEMPTS',
    ]);
});

test('a successful login clears prior failed login attempts from the rate limiter', function (): void {
    // 4 failed attempts from IP 192.168.50.10
    for ($i = 0; $i < 4; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.50.10'])
            ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
                'email' => 'owner@alpha.nexuserp.test',
                'password' => 'WrongPassword123!',
            ])->assertStatus(401);
    }

    // 5th attempt: successful login with valid credentials
    $successResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.50.10'])
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
            'email' => 'owner@alpha.nexuserp.test',
            'password' => 'AlphaSecret123!',
        ]);
    $successResponse->assertStatus(200);

    // After success, RateLimiter::clear() has run. A new failed attempt must NOT hit 429
    $subsequentFailed = $this->withServerVariables(['REMOTE_ADDR' => '192.168.50.10'])
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
            'email' => 'owner@alpha.nexuserp.test',
            'password' => 'WrongPassword123!',
        ]);
    $subsequentFailed->assertStatus(401);
    $subsequentFailed->assertJson([
        'success' => false,
        'code' => 'INVALID_CREDENTIALS',
    ]);
});

test('session created on alpha is rejected on beta when replayed with 401 UNAUTHENTICATED', function (): void {
    // 1. Log in on alpha
    $alphaLogin = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);

    $alphaLogin->assertStatus(200);
    $alphaCookie = $alphaLogin->getCookie(config('session.cookie'));
    expect($alphaCookie)->not->toBeNull();

    // 2. Replay alpha session cookie against beta host
    $betaReplay = $this->withCookie(config('session.cookie'), $alphaCookie->getValue())
        ->postJson('http://beta.nexuserp.test/api/v1/auth/logout');

    // Must be rejected with 401 UNAUTHENTICATED
    $betaReplay->assertStatus(401);
    $betaReplay->assertJson([
        'success' => false,
        'code' => 'UNAUTHENTICATED',
    ]);
});
