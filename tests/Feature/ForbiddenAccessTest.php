<?php

namespace Tests\Feature;

use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ForbiddenAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_request_without_permission_renders_friendly_403_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertStatus(403)
            ->assertInertia(fn ($page) => $page
                ->component('error')
                ->where('status', 403)
                ->where('title', 'Acceso no autorizado')
                ->where('fallback', '/paz-salvos/consultar'));
    }

    public function test_mutating_request_without_permission_still_returns_403(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'ver detalle paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo('ver detalle paz y salvo');
        $document = PazSalvo::factory()->create();

        $this->actingAs($user)
            ->patch(route('paz-salvos.cancel', $document), ['cancel_reason' => 'Intento sin permiso'])
            ->assertStatus(403);
    }
}
