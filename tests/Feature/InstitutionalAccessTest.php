<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_institutional_access_redirects_guests_to_login(): void
    {
        $this->get(route('institutional.access'))
            ->assertRedirect(route('login'));
    }

    public function test_institutional_access_redirects_authenticated_users_to_private_consultation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('institutional.access'))
            ->assertRedirect(route('paz-salvo.index'));
    }

    public function test_login_page_loads_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/login'));
    }

    public function test_login_page_redirects_authenticated_users_to_private_consultation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('paz-salvo.index'));
    }

    public function test_public_home_remains_public(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/home'));
    }

    public function test_public_home_links_to_institutional_access_bridge(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/home.tsx'));

        $this->assertStringContainsString('href="/acceso-institucional"', $source);
        $this->assertStringNotContainsString('href="/login"', $source);
    }
}
