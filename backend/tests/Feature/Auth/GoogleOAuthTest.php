<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\Auth\Models\OAuthAccount;
use Modules\Auth\Models\TenantUser;
use Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use Modules\Tenancy\Models\Domain;
use Modules\Tenancy\Models\Tenant;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Symfony\Component\HttpFoundation\RedirectResponse;

if (! function_exists('dropGoogleOAuthTestTenantDatabase')) {
    function dropGoogleOAuthTestTenantDatabase(string $dbName): void
    {
        if (! in_array($dbName, ['tenant_alpha_oauth', 'tenant_beta_oauth'], true)) {
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

    Artisan::call('migrate', [
        '--database' => 'central',
        '--path' => 'database/migrations/central',
        '--force' => true,
    ]);

    dropGoogleOAuthTestTenantDatabase('tenant_alpha_oauth');
    dropGoogleOAuthTestTenantDatabase('tenant_beta_oauth');

    Domain::query()->whereIn('host', ['alpha-oauth.nexuserp.test', 'beta-oauth.nexuserp.test'])->delete();
    Tenant::query()->whereIn('slug', ['alpha-oauth', 'beta-oauth'])->delete();

    // 1. Provision Alpha tenant
    $alphaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $alphaId,
        'name' => 'Alpha OAuth Corp',
        'slug' => 'alpha-oauth',
        'database_name' => 'tenant_alpha_oauth',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $alphaId,
        'host' => 'alpha-oauth.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $alphaId,
        ownerEmail: 'owner@alpha.nexuserp.test',
        ownerName: 'Alpha Owner',
    );

    // 2. Provision Beta tenant
    $betaId = (string) Str::ulid();
    Tenant::on('central')->create([
        'id' => $betaId,
        'name' => 'Beta OAuth Corp',
        'slug' => 'beta-oauth',
        'database_name' => 'tenant_beta_oauth',
        'status' => 'provisioning',
    ]);
    Domain::on('central')->create([
        'tenant_id' => $betaId,
        'host' => 'beta-oauth.nexuserp.test',
    ]);
    ProvisionTenantDatabase::dispatchSync(
        tenantId: $betaId,
        ownerEmail: 'owner@beta.nexuserp.test',
        ownerName: 'Beta Owner',
    );

    /** @var TenantManager $tenantManager */
    $tenantManager = app(TenantManager::class);

    // Add unverified user to Alpha
    $alphaContext = new TenantContext(id: $alphaId, slug: 'alpha-oauth', databaseName: 'tenant_alpha_oauth');
    $tenantManager->switchTo($alphaContext);
    TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Unverified Alpha Member',
        'email' => 'unverified@alpha.nexuserp.test',
        'password' => Hash::make('Password1234!'),
        'status' => 'active',
        'email_verified_at' => null,
    ]);

    // Add deactivated user to Alpha
    TenantUser::create([
        'id' => (string) Str::ulid(),
        'name' => 'Deactivated Alpha Member',
        'email' => 'deactivated@alpha.nexuserp.test',
        'password' => Hash::make('Password1234!'),
        'status' => 'deactivated',
        'email_verified_at' => now(),
    ]);

    $tenantManager->reset();

    $this->alphaId = $alphaId;
    $this->betaId = $betaId;
});

afterEach(function (): void {
    dropGoogleOAuthTestTenantDatabase('tenant_alpha_oauth');
    dropGoogleOAuthTestTenantDatabase('tenant_beta_oauth');
});

/**
 * Helper to mock Socialite driver.
 *
 * @param  array{id?: string, email?: ?string, name?: ?string, avatar?: ?string, email_verified?: bool}  $attributes
 */
function mockGoogleSocialite(array $attributes = [], ?Throwable $exception = null): void
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn($attributes['id'] ?? 'google-sub-12345');
    $socialiteUser->shouldReceive('getEmail')->andReturn($attributes['email'] ?? 'owner@alpha.nexuserp.test');
    $socialiteUser->shouldReceive('getName')->andReturn($attributes['name'] ?? 'Google User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn($attributes['avatar'] ?? 'https://lh3.googleusercontent.com/avatar.jpg');

    $isVerified = $attributes['email_verified'] ?? true;
    $raw = ['email_verified' => $isVerified];
    $socialiteUser->shouldReceive('getRaw')->andReturn($raw);
    $socialiteUser->user = $raw;

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('scopes')->andReturnSelf();

    if ($exception !== null) {
        $provider->shouldReceive('user')->andThrow($exception);
    } else {
        $provider->shouldReceive('user')->andReturn($socialiteUser);
    }

    $redirectResponse = new RedirectResponse('https://accounts.google.com/o/oauth2/v2/auth?mocked=true');
    $provider->shouldReceive('redirect')->andReturn($redirectResponse);

    $factory = Mockery::mock(SocialiteFactory::class);
    $factory->shouldReceive('driver')->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $factory);
}

describe('Google OAuth Redirect Endpoint', function (): void {
    it('returns json response with redirect url by default', function (): void {
        mockGoogleSocialite();

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/redirect');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['url'],
            ]);

        expect($response->json('data.url'))->toContain('https://accounts.google.com');
    });

    it('returns http 302 redirect to google when redirect=true is requested', function (): void {
        mockGoogleSocialite();

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/redirect?redirect=true');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('https://accounts.google.com');
    });
});

describe('Google OAuth Callback & Account Linking', function (): void {
    it('automatically links google account to existing user by verified email and authenticates session', function (): void {
        mockGoogleSocialite([
            'id' => 'google-user-id-998877',
            'email' => 'unverified@alpha.nexuserp.test',
            'name' => 'Unverified Alpha Member',
            'avatar' => 'https://example.com/avatar.png',
            'email_verified' => true,
        ]);

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Authenticated successfully with Google.')
            ->assertJsonPath('data.user.email', 'unverified@alpha.nexuserp.test');

        // Verify account linked in Alpha tenant DB
        /** @var TenantManager $tenantManager */
        $tenantManager = app(TenantManager::class);
        $alphaContext = new TenantContext(id: $this->alphaId, slug: 'alpha-oauth', databaseName: 'tenant_alpha_oauth');
        $tenantManager->switchTo($alphaContext);

        $user = TenantUser::where('email', 'unverified@alpha.nexuserp.test')->first();
        expect($user)->not->toBeNull();
        expect($user?->email_verified_at)->not->toBeNull(); // Marked verified via Google verified email

        $oauth = OAuthAccount::where('user_id', $user?->id)->first();
        expect($oauth)->not->toBeNull();
        expect($oauth?->provider)->toBe('google');
        expect($oauth?->provider_id)->toBe('google-user-id-998877');
        expect($oauth?->email)->toBe('unverified@alpha.nexuserp.test');
        expect($oauth?->avatar)->toBe('https://example.com/avatar.png');

        $tenantManager->reset();
    });

    it('authenticates returning user whose google account was already linked', function (): void {
        /** @var TenantManager $tenantManager */
        $tenantManager = app(TenantManager::class);
        $alphaContext = new TenantContext(id: $this->alphaId, slug: 'alpha-oauth', databaseName: 'tenant_alpha_oauth');
        $tenantManager->switchTo($alphaContext);

        $owner = TenantUser::where('email', 'owner@alpha.nexuserp.test')->firstOrFail();
        OAuthAccount::create([
            'user_id' => $owner->id,
            'provider' => 'google',
            'provider_id' => 'google-existing-id-112233',
            'email' => 'owner@alpha.nexuserp.test',
            'avatar' => 'https://example.com/owner.jpg',
        ]);
        $tenantManager->reset();

        mockGoogleSocialite([
            'id' => 'google-existing-id-112233',
            'email' => 'owner@alpha.nexuserp.test',
            'email_verified' => true,
        ]);

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'owner@alpha.nexuserp.test');
    });

    it('rejects google oauth account linking when google email is NOT verified (PRD §3.2)', function (): void {
        mockGoogleSocialite([
            'id' => 'unverified-google-id',
            'email' => 'unverified@alpha.nexuserp.test',
            'email_verified' => false, // UNVERIFIED!
        ]);

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNVERIFIED_OAUTH_EMAIL');

        // Confirm no OAuth account was created in DB
        /** @var TenantManager $tenantManager */
        $tenantManager = app(TenantManager::class);
        $alphaContext = new TenantContext(id: $this->alphaId, slug: 'alpha-oauth', databaseName: 'tenant_alpha_oauth');
        $tenantManager->switchTo($alphaContext);

        expect(OAuthAccount::where('provider', 'google')->count())->toBe(0);

        $tenantManager->reset();
    });

    it('rejects oauth login if user does NOT exist in the tenant database', function (): void {
        mockGoogleSocialite([
            'id' => 'random-google-id',
            'email' => 'stranger@example.com',
            'email_verified' => true,
        ]);

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
    });

    it('rejects oauth login if tenant user is deactivated', function (): void {
        mockGoogleSocialite([
            'id' => 'deactivated-google-id',
            'email' => 'deactivated@alpha.nexuserp.test',
            'email_verified' => true,
        ]);

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    });

    it('rejects oauth callback when error query parameter is returned by google', function (): void {
        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?error=access_denied&error_description=User+cancelled+consent');

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'OAUTH_FAILED');
    });

    it('rejects oauth callback when token exchange or state verification throws an exception', function (): void {
        mockGoogleSocialite([], new Exception('Invalid OAuth state parameter'));

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=invalid_code&state=invalid_state');

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_OAUTH_STATE');
    });

    it('enforces multi-tenant isolation so user of tenant Alpha cannot authenticate on tenant Beta (PRD §3.2)', function (): void {
        mockGoogleSocialite([
            'id' => 'alpha-owner-google-id',
            'email' => 'owner@alpha.nexuserp.test',
            'email_verified' => true,
        ]);

        // Attempt authentication on Beta's domain with Alpha's user email
        $response = $this->get('http://beta-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state');

        // Beta does not know owner@alpha.nexuserp.test -> MUST reject with 404 ACCOUNT_NOT_FOUND
        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        // Confirm Beta tenant DB has no linked OAuth accounts
        /** @var TenantManager $tenantManager */
        $tenantManager = app(TenantManager::class);
        $betaContext = new TenantContext(id: $this->betaId, slug: 'beta-oauth', databaseName: 'tenant_beta_oauth');
        $tenantManager->switchTo($betaContext);

        expect(OAuthAccount::count())->toBe(0);

        $tenantManager->reset();
    });

    it('redirects to dashboard when redirect=true is requested on callback', function (): void {
        mockGoogleSocialite([
            'id' => 'google-owner-id-dashboard',
            'email' => 'owner@alpha.nexuserp.test',
            'email_verified' => true,
        ]);

        $response = $this->get('http://alpha-oauth.nexuserp.test/api/v1/auth/google/callback?code=mock_code&state=mock_state&redirect=true');

        $response->assertRedirect('/dashboard');
    });
});
