<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    RateLimiter::clear('tenants:*');
    RateLimiter::clear('auth:*');
    RateLimiter::clear('downloads:*');
});

test('responses emit all required OWASP security headers', function (): void {
    $response = $this->get('/up');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-XSS-Protection', '0')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
        ->assertHeader('Content-Security-Policy');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'");
});

test('HTTPS requests include Strict-Transport-Security header', function (): void {
    $response = $this->get('https://nexuserp.test/up');

    $response->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
});

test('perimeter rate limiting throttles excessive requests with HTTP 429', function (): void {
    // Test the tenant registration rate limit (5 per minute)
    for ($i = 0; $i < 5; $i++) {
        $res = $this->postJson('http://nexuserp.test/api/v1/tenants', [
            'name' => 'Rate Limit Corp',
            'slug' => 'ratelimit-'.$i,
            'email' => 'admin@ratelimit.com',
            'password' => 'Password12345!',
        ]);
        // Either 202 (valid) or 422 (validation error), but NOT 429
        expect($res->status())->not->toBe(429);
    }

    // 6th request must be throttled with HTTP 429
    $throttleRes = $this->postJson('http://nexuserp.test/api/v1/tenants', [
        'name' => 'Excessive Corp',
        'slug' => 'excessive',
        'email' => 'admin@excessive.com',
        'password' => 'Password12345!',
    ]);

    $throttleRes->assertStatus(429);
});

test('temporary signed routes strictly reject tampered query parameters with 403', function (): void {
    app('router')->get('/test-signed-security-route', function () {
        return response()->json(['status' => 'secure']);
    })->middleware('signed')->name('test.signed.security');
    app('router')->getRoutes()->refreshNameLookups();

    // Generate valid signed URL
    $validUrl = URL::temporarySignedRoute(
        'test.signed.security',
        now()->addMinutes(10),
        ['user' => 'admin']
    );

    $validRes = $this->get($validUrl);
    $validRes->assertOk()->assertJson(['status' => 'secure']);

    // Tamper parameter value
    $tamperedParamUrl = str_replace('user=admin', 'user=hacker', $validUrl);
    $tamperedRes = $this->get($tamperedParamUrl);
    $tamperedRes->assertStatus(403);

    // Tamper signature hash
    $tamperedSigUrl = substr($validUrl, 0, -5).'abcde';
    $tamperedSigRes = $this->get($tamperedSigUrl);
    $tamperedSigRes->assertStatus(403);
});

test('temporary signed routes reject expired URLs with 403', function (): void {
    app('router')->get('/test-signed-expiration-route', function () {
        return response()->json(['status' => 'secure']);
    })->middleware('signed')->name('test.signed.expiration');
    app('router')->getRoutes()->refreshNameLookups();

    // Generate URL that expired 1 minute ago
    $expiredUrl = URL::temporarySignedRoute(
        'test.signed.expiration',
        now()->subMinute(),
        ['action' => 'download']
    );

    $response = $this->get($expiredUrl);
    $response->assertStatus(403);
});
