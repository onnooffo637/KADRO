<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_reader_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_configured_admin_can_visit_dashboard(): void
    {
        $user = User::factory()->create();

        config()->set('platform_admin.user_id', (string) $user->id);
        config()->set('platform_admin.email', $user->email);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }
}
