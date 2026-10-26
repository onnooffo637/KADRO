<?php

namespace Tests\Feature\Gate07;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Runs only against a pre-migrated, disposable manga_gate07_test PostgreSQL DB.
 * No RefreshDatabase, no migration, and all test writes are rolled back.
 */
class ReaderMobileAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'manga_gate07_test'
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || (string) config('database.connections.pgsql.port') !== '5432'
            || ! empty(config('database.connections.pgsql.url'))
            || DB::selectOne('select current_database() as name')->name !== 'manga_gate07_test') {
            throw new \RuntimeException('STOP: ReaderMobileAuthTest requires disposable manga_gate07_test on 127.0.0.1:5432.');
        }

        Notification::fake();
    }

    public function test_guest_cannot_read_profile(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_public_registration_cannot_assign_admin_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Reader',
            'email' => 'newreader07@example.test',
            'password' => 'CorrectHorseBattery!90',
            'password_confirmation' => 'CorrectHorseBattery!90',
            'role' => 'admin',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'newreader07@example.test']);
    }

    public function test_register_creates_only_unverified_reader(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Reader',
            'email' => 'newreader07@example.test',
            'password' => 'CorrectHorseBattery!90',
            'password_confirmation' => 'CorrectHorseBattery!90',
        ])->assertCreated()->assertJsonPath('verification_required', true);

        $user = User::where('email', 'newreader07@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->isPlatformAdmin());
        $this->assertCount(0, $user->tokens);
    }

    public function test_login_rejects_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
            'device_name' => 'Pixel',
        ])->assertUnprocessable();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_requires_verified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel',
        ])->assertForbidden()->assertJsonPath('code', 'EMAIL_UNVERIFIED');
    }

    public function test_admin_gets_dashboard_scoped_mobile_token(): void
    {
        $user = User::factory()->create();
        config()->set('platform_admin.user_id', (string) $user->id);
        config()->set('platform_admin.email', $user->email);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel',
        ])->assertOk()->assertJsonPath('is_admin', true);

        $token = $response->json('token');
        $this->assertSame(['admin:dashboard'], $user->tokens()->firstOrFail()->abilities);
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('is_admin', true);
        $this->withToken($token)->getJson('/api/v1/admin/dashboard/summary')
            ->assertOk()->assertJsonStructure(['stats', 'recent']);
    }

    public function test_login_profile_logout_and_revocation(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel',
        ])->assertOk()->assertJsonStructure(['token', 'token_type', 'expires_at']);

        $token = $response->json('token');
        $this->assertIsString($token);

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('id', $user->id)->assertJsonMissing(['password']);
        $this->withToken($token)->getJson('/api/v1/admin/dashboard/summary')
            ->assertForbidden();

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Verify server-side revocation, not just a successful JSON response.
        $this->assertSame(0, $user->tokens()->count());

        // PHPUnit reuses the same application for these HTTP requests.
        // RequestGuard memoizes the previously authenticated user, unlike
        // the independent request lifecycle of a mobile client.
        Auth::guard('sanctum')->forgetUser();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_web_session_cannot_impersonate_mobile_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
