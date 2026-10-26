<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Auth\Jobs\SendEmailVerificationEmail;
use Modules\Auth\Jobs\SendPasswordResetEmail;
use Modules\Auth\Mail\ResetPasswordMail;
use Modules\Auth\Mail\VerifyEmailMail;
use Modules\Auth\Models\EmailVerificationToken;
use Modules\Auth\Models\PasswordResetToken;
use Modules\Auth\Models\TenantUser;
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
    RateLimiter::clear('password_reset:*');
    RateLimiter::clear('email_verify_resend:*');
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

    // 3. Set known initial state for tenant alpha
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    $alphaContext = new TenantContext(id: $alphaId, slug: 'alpha', databaseName: 'tenant_alpha');
    $tenantManager->switchTo($alphaContext);
    DB::connection('tenant')->table('users')->where('email', 'owner@alpha.nexuserp.test')->update([
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => now(),
    ]);

    // Add unverified user on alpha
    DB::connection('tenant')->table('users')->insert([
        'id' => (string) Str::ulid(),
        'name' => 'Unverified Alpha User',
        'email' => 'unverified@alpha.nexuserp.test',
        'password' => Hash::make('AlphaSecret123!'),
        'email_verified_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 4. Set known initial state for tenant beta
    $betaContext = new TenantContext(id: $betaId, slug: 'beta', databaseName: 'tenant_beta');
    $tenantManager->switchTo($betaContext);
    DB::connection('tenant')->table('users')->where('email', 'owner@beta.nexuserp.test')->update([
        'password' => Hash::make('BetaSecret123!'),
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();
    TenantContext::set(null);

    $this->alphaId = $alphaId;
    $this->betaId = $betaId;
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

test('requesting password reset for existing and unknown email returns identical 200 response to prevent enumeration', function (): void {
    Queue::fake([SendPasswordResetEmail::class]);

    $existingResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/email', [
        'email' => 'owner@alpha.nexuserp.test',
    ]);

    $unknownResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/email', [
        'email' => 'nonexistent@alpha.nexuserp.test',
    ]);

    $existingResponse->assertStatus(200);
    $unknownResponse->assertStatus(200);

    expect($existingResponse->json())->toBe([
        'success' => true,
        'message' => 'If an account with that email exists, a password reset link has been sent.',
        'data' => [],
    ]);

    expect($existingResponse->json())->toEqual($unknownResponse->json());
    expect($existingResponse->status())->toBe($unknownResponse->status());

    // Job dispatched only for existing user and carries tenant context
    Queue::assertPushed(SendPasswordResetEmail::class, function (SendPasswordResetEmail $job): bool {
        return $job->tenantId === $this->alphaId
            && $job->tenantSlug === 'alpha'
            && $job->email === 'owner@alpha.nexuserp.test'
            && ! empty($job->token);
    });
    Queue::assertPushed(SendPasswordResetEmail::class, 1);
});

test('password reset email request is throttled per tenant and IP after 5 attempts', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $res = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.55'])
            ->postJson('http://alpha.nexuserp.test/api/v1/auth/password/email', [
                'email' => 'owner@alpha.nexuserp.test',
            ]);
        $res->assertStatus(200);
    }

    // 6th attempt is throttled
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.55'])
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/password/email', [
            'email' => 'owner@alpha.nexuserp.test',
        ]);

    $throttled->assertStatus(429);
    $throttled->assertJson([
        'success' => false,
        'code' => 'TOO_MANY_ATTEMPTS',
    ]);
    expect($throttled->headers->has('Retry-After'))->toBeTrue();

    // Same IP on beta tenant is NOT throttled
    $betaRes = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.55'])
        ->postJson('http://beta.nexuserp.test/api/v1/auth/password/email', [
            'email' => 'owner@beta.nexuserp.test',
        ]);
    $betaRes->assertStatus(200);
});

test('weak password is rejected by password reset endpoint using PasswordRule', function (): void {
    // 1. Password shorter than 12 characters
    $res1 = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => 'some-random-token',
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'Short1!',
    ]);
    $res1->assertStatus(422);
    $res1->assertJson([
        'success' => false,
        'code' => 'VALIDATION_FAILED',
    ]);
    expect($res1->json('errors.password'))->not->toBeEmpty();

    // 2. Password with no letters
    $res2 = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => 'some-random-token',
        'email' => 'owner@alpha.nexuserp.test',
        'password' => '123456789012345!',
    ]);
    $res2->assertStatus(422);
    expect($res2->json('errors.password'))->not->toBeEmpty();

    // 3. Password with no numbers
    $res3 = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => 'some-random-token',
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'abcdefghijklmnop!',
    ]);
    $res3->assertStatus(422);
    expect($res3->json('errors.password'))->not->toBeEmpty();
});

test('password reset succeeds once with valid token, updates password, deletes token, and does not auto login', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    PasswordResetToken::query()->create([
        'email' => 'owner@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now(),
        'expires_at' => now()->addMinutes(60),
    ]);
    $tenantManager->reset();

    // Perform password reset
    $newPassword = 'NewSecretPassword123!';
    $response = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => $plainToken,
        'email' => 'owner@alpha.nexuserp.test',
        'password' => $newPassword,
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'Password has been reset successfully.',
        'data' => null,
    ]);

    // Token must be deleted (single use)
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));
    expect(PasswordResetToken::query()->where('email', 'owner@alpha.nexuserp.test')->first())->toBeNull();
    $tenantManager->reset();

    // Old password must now fail
    $oldLogin = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);
    $oldLogin->assertStatus(401);

    // New password succeeds
    $newLogin = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => $newPassword,
    ]);
    $newLogin->assertStatus(200);
});

test('password reset token reuse fails with 400 INVALID_RESET_TOKEN', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    PasswordResetToken::query()->create([
        'email' => 'owner@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now(),
        'expires_at' => now()->addMinutes(60),
    ]);
    $tenantManager->reset();

    // 1st reset succeeds
    $res1 = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => $plainToken,
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'NewPassword12345!',
    ]);
    $res1->assertStatus(200);

    // 2nd reset with same token fails
    $res2 = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => $plainToken,
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'AnotherPassword12345!',
    ]);
    $res2->assertStatus(400);
    $res2->assertJson([
        'success' => false,
        'code' => 'INVALID_RESET_TOKEN',
    ]);
});

test('expired password reset token fails with 400 INVALID_RESET_TOKEN', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    PasswordResetToken::query()->create([
        'email' => 'owner@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now()->subHours(2),
        'expires_at' => now()->subMinute(),
    ]);
    $tenantManager->reset();

    $response = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => $plainToken,
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'NewPassword12345!',
    ]);

    $response->assertStatus(400);
    $response->assertJson([
        'success' => false,
        'code' => 'INVALID_RESET_TOKEN',
    ]);
});

test('cross-tenant password reset token fails on beta with 400 INVALID_RESET_TOKEN', function (): void {
    // Generate token in alpha's database
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    PasswordResetToken::query()->create([
        'email' => 'owner@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now(),
        'expires_at' => now()->addMinutes(60),
    ]);
    $tenantManager->reset();

    // Replay alpha token against beta tenant
    $response = $this->postJson('http://beta.nexuserp.test/api/v1/auth/password/reset', [
        'token' => $plainToken,
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'NewPassword12345!',
    ]);

    $response->assertStatus(400);
    $response->assertJson([
        'success' => false,
        'code' => 'INVALID_RESET_TOKEN',
    ]);
});

test('active user sessions are revoked after password reset', function (): void {
    // 1. Log in to establish an active session
    $loginResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);
    $loginResponse->assertStatus(200);
    $cookie = $loginResponse->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();
    $sessionId = $cookie->getValue();
    $sessionHandler = app('session')->driver()->getHandler();
    expect($sessionHandler->read($sessionId))->not->toBe('');

    // 2. Perform password reset for the user
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    PasswordResetToken::query()->create([
        'email' => 'owner@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now(),
        'expires_at' => now()->addMinutes(60),
    ]);
    $tenantManager->reset();

    $resetResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/password/reset', [
        'token' => $plainToken,
        'email' => 'owner@alpha.nexuserp.test',
        'password' => 'NewPassword12345!',
    ]);
    $resetResponse->assertStatus(200);

    // 3. Verify directly against the session driver's store that the active session was destroyed immediately upon reset
    expect($sessionHandler->read($sessionId))->toBe('');

    // 4. User attempts to use their prior session cookie on an authenticated endpoint
    $subsequentResponse = $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())
        ->postJson('http://alpha.nexuserp.test/api/v1/auth/logout');

    // Prior session MUST be revoked with 401 UNAUTHENTICATED
    $subsequentResponse->assertStatus(401);
    $subsequentResponse->assertJson([
        'success' => false,
        'code' => 'UNAUTHENTICATED',
    ]);
});

test('email verification flow succeeds and sets email_verified_at', function (): void {
    Queue::fake([SendEmailVerificationEmail::class]);

    // 1. Resend verification email
    $resendResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/email/resend', [
        'email' => 'unverified@alpha.nexuserp.test',
    ]);

    $resendResponse->assertStatus(200);
    $resendResponse->assertJson([
        'success' => true,
        'message' => 'If an unverified account with that email exists, a verification link has been sent.',
    ]);

    Queue::assertPushed(SendEmailVerificationEmail::class, function (SendEmailVerificationEmail $job): bool {
        return $job->tenantId === $this->alphaId
            && $job->tenantSlug === 'alpha'
            && $job->email === 'unverified@alpha.nexuserp.test'
            && ! empty($job->token);
    });

    // 2. Fetch the token from alpha's database to verify
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    EmailVerificationToken::query()->where('email', 'unverified@alpha.nexuserp.test')->delete();
    EmailVerificationToken::query()->create([
        'email' => 'unverified@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now(),
        'expires_at' => now()->addMinutes(60),
    ]);
    $tenantManager->reset();

    // 3. Verify email with valid token
    $verifyResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/email/verify', [
        'email' => 'unverified@alpha.nexuserp.test',
        'token' => $plainToken,
    ]);

    $verifyResponse->assertStatus(200);
    $verifyResponse->assertJson([
        'success' => true,
        'message' => 'Email verified successfully.',
    ]);

    // Check user is verified in DB
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));
    /** @var TenantUser|null $user */
    $user = TenantUser::query()->where('email', 'unverified@alpha.nexuserp.test')->first();
    expect($user)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();

    // Token must be single use (deleted)
    expect(EmailVerificationToken::query()->where('email', 'unverified@alpha.nexuserp.test')->first())->toBeNull();
    $tenantManager->reset();

    // 4. Token reuse fails
    $reuseResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/email/verify', [
        'email' => 'unverified@alpha.nexuserp.test',
        'token' => $plainToken,
    ]);
    $reuseResponse->assertStatus(400);
    $reuseResponse->assertJson([
        'success' => false,
        'code' => 'INVALID_VERIFICATION_TOKEN',
    ]);
});

test('cross-tenant email verification token fails on beta with 400 INVALID_VERIFICATION_TOKEN', function (): void {
    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));

    $plainToken = Str::random(64);
    EmailVerificationToken::query()->create([
        'email' => 'unverified@alpha.nexuserp.test',
        'token' => hash('sha256', $plainToken),
        'created_at' => now(),
        'expires_at' => now()->addMinutes(60),
    ]);
    $tenantManager->reset();

    // Replay alpha verification token against beta
    $response = $this->postJson('http://beta.nexuserp.test/api/v1/auth/email/verify', [
        'email' => 'unverified@alpha.nexuserp.test',
        'token' => $plainToken,
    ]);

    $response->assertStatus(400);
    $response->assertJson([
        'success' => false,
        'code' => 'INVALID_VERIFICATION_TOKEN',
    ]);
});

test('login is blocked before email verification when require_email_verification is true, and allowed when false', function (): void {
    // 1. When require_email_verification is TRUE: unverified login blocked with 403 EMAIL_NOT_VERIFIED
    config(['auth.require_email_verification' => true]);

    $blockedResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'unverified@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);

    $blockedResponse->assertStatus(403);
    $blockedResponse->assertJson([
        'success' => false,
        'code' => 'EMAIL_NOT_VERIFIED',
    ]);

    // 2. When require_email_verification is FALSE: unverified login succeeds with 200
    config(['auth.require_email_verification' => false]);

    $allowedResponse = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'unverified@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);

    $allowedResponse->assertStatus(200);
    $allowedResponse->assertJson([
        'success' => true,
        'message' => 'Login successful.',
    ]);

    // 3. When require_email_verification is TRUE again, but user verifies email: login succeeds
    config(['auth.require_email_verification' => true]);

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);
    $tenantManager->switchTo(new TenantContext(id: $this->alphaId, slug: 'alpha', databaseName: 'tenant_alpha'));
    DB::connection('tenant')->table('users')->where('email', 'unverified@alpha.nexuserp.test')->update([
        'email_verified_at' => now(),
    ]);
    $tenantManager->reset();

    $verifiedLogin = $this->postJson('http://alpha.nexuserp.test/api/v1/auth/login', [
        'email' => 'unverified@alpha.nexuserp.test',
        'password' => 'AlphaSecret123!',
    ]);

    $verifiedLogin->assertStatus(200);
    $verifiedLogin->assertJson([
        'success' => true,
        'message' => 'Login successful.',
    ]);
});

test('SendPasswordResetEmail job sends ResetPasswordMail with tenant context and token', function (): void {
    Mail::fake();

    SendPasswordResetEmail::dispatchSync(
        tenantId: $this->alphaId,
        tenantSlug: 'alpha',
        tenantDatabaseName: 'tenant_alpha',
        email: 'owner@alpha.nexuserp.test',
        token: 'test-plain-token-12345',
    );

    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail): bool {
        return $mail->hasTo('owner@alpha.nexuserp.test')
            && $mail->token === 'test-plain-token-12345'
            && $mail->tenantSlug === 'alpha';
    });
});

test('SendEmailVerificationEmail job sends VerifyEmailMail with tenant context and token', function (): void {
    Mail::fake();

    SendEmailVerificationEmail::dispatchSync(
        tenantId: $this->alphaId,
        tenantSlug: 'alpha',
        tenantDatabaseName: 'tenant_alpha',
        email: 'unverified@alpha.nexuserp.test',
        token: 'verify-plain-token-67890',
    );

    Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail): bool {
        return $mail->hasTo('unverified@alpha.nexuserp.test')
            && $mail->token === 'verify-plain-token-67890'
            && $mail->tenantSlug === 'alpha';
    });
});
