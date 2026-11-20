<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class LandingPageTest extends TestCase
{
    public function test_central_landing_page_renders_public_landing_inertia_view(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/LandingPage')
            ->has('appName')
        );
    }
}
