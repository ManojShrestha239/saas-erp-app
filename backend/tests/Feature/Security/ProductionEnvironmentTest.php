<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

test('GET /api/v1/health returns HTTP 200 with component diagnostics', function (): void {
    /** @var TestCase $this */
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'healthy')
        ->assertJsonPath('data.checks.central_database', 'ok')
        ->assertJsonPath('data.checks.redis', 'ok')
        ->assertJsonPath('data.checks.storage', 'ok');
});

test('GET /up returns HTTP 200 health status', function (): void {
    /** @var TestCase $this */
    $response = $this->get('/up');

    $response->assertOk();
});

test('production environment safeguards enforce telescope and debugbar disabled', function (): void {
    /** @var TestCase $this */
    // Simulate production environment
    App::detectEnvironment(fn (): string => 'production');
    Config::set('app.env', 'production');
    Config::set('telescope.enabled', true);
    Config::set('debugbar.enabled', true);

    // Boot AppServiceProvider logic
    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(config('telescope.enabled'))->toBeFalse();
    expect(config('debugbar.enabled'))->toBeFalse();
});
