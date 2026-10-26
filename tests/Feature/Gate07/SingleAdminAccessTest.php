<?php

namespace Tests\Feature\Gate07;

use App\Models\User;
use Tests\TestCase;

/**
 * No RefreshDatabase: safe to run WITHOUT migrations or persisted users.
 * actingAs() injects each in-memory User directly into the web guard.
 */
class SingleAdminAccessTest extends TestCase
{
    private function user(int $id, string $email): User
    {
        $user = User::factory()->make([
            'email' => $email,
            'email_verified_at' => now(),
        ]);
        $user->forceFill(['id' => $id]);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_reader_cannot_access_dashboard(): void
    {
        config()->set('platform_admin.user_id', '1');
        config()->set('platform_admin.email', 'admin@example.test');

        $this->actingAs($this->user(2, 'reader@example.test'))
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_admin_identity_requires_matching_id_and_email(): void
    {
        config()->set('platform_admin.user_id', '1');
        config()->set('platform_admin.email', 'admin@example.test');

        $this->actingAs($this->user(1, 'reader@example.test'))
            ->get('/dashboard')
            ->assertForbidden();

        $this->actingAs($this->user(2, 'admin@example.test'))
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_admin_is_denied_when_config_is_missing(): void
    {
        config()->set('platform_admin.user_id', null);
        config()->set('platform_admin.email', null);

        $this->actingAs($this->user(1, 'admin@example.test'))
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_configured_admin_can_access_dashboard(): void
    {
        config()->set('platform_admin.user_id', '1');
        config()->set('platform_admin.email', 'admin@example.test');

        $this->actingAs($this->user(1, 'admin@example.test'))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_unverified_admin_is_redirected_to_verification(): void
    {
        config()->set('platform_admin.user_id', '1');
        config()->set('platform_admin.email', 'admin@example.test');

        $user = $this->user(1, 'admin@example.test');
        $user->email_verified_at = null;

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }
}
