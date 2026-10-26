<?php

namespace Tests\Feature\Gate09;

use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Test only on manga_gate07_test, guarded a second time in setUp. */
class AdminSeriesDraftTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate07_test'
            || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate09A: only dedicated local manga_gate07_test is allowed.');
        }

        parent::setUp();
        $this->assertSame('manga_gate07_test', DB::selectOne('SELECT current_database() AS name')->name);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        config()->set('platform_admin.user_id', (string) $user->id);
        config()->set('platform_admin.email', $user->email);

        return $user;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'slug' => 'gate09-fixture-'.Str::lower(Str::random(9)),
            'primary_title' => 'Fictional independent testing work',
            'format' => 'manhwa',
            'original_language' => 'ko',
            'completion_status' => 'ongoing',
            'synopsis' => 'A fictional description.',
            'genre_codes' => [],
            'new_genre_code' => '',
            'new_genre_name' => '',
        ], $override);
    }

    public function test_guests_cannot_open_admin_panel_or_create_series(): void
    {
        $this->get('/admin/series')->assertRedirect('/login');
        $this->post('/admin/series', $this->payload())->assertRedirect('/login');
    }

    public function test_verified_reader_cannot_view_create_or_edit_admin_series(): void
    {
        $reader = User::factory()->create(['email_verified_at' => now()]);
        $draft = Series::factory()->create(['publication_status' => 'draft', 'published_at' => null]);

        $this->actingAs($reader)->get('/admin/series')->assertForbidden();
        $this->actingAs($reader)->get('/admin/series/create')->assertForbidden();
        $this->actingAs($reader)->post('/admin/series', $this->payload())->assertForbidden();
        $this->actingAs($reader)->get('/admin/series/'.$draft->id.'/edit')->assertForbidden();
        $this->actingAs($reader)->put('/admin/series/'.$draft->id, $this->payload())->assertForbidden();
    }

    public function test_unverified_admin_cannot_open_panel(): void
    {
        $user = $this->admin();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get('/admin/series')->assertRedirect();
    }

    public function test_only_configured_admin_can_create_unpublished_draft_and_audit(): void
    {
        $admin = $this->admin();
        $payload = $this->payload();

        $this->actingAs($admin)->post('/admin/series', array_merge($payload, [
            'publication_status' => 'published',
            'published_at' => now()->toDateTimeString(),
        ]))->assertRedirect();

        $record = Series::query()->where('slug', $payload['slug'])->firstOrFail();
        $this->assertSame('draft', $record->publication_status);
        $this->assertNull($record->published_at);
        $this->assertSame($payload['primary_title'], $record->primary_title);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'series',
            'subject_id' => $record->id,
            'actor_id' => $admin->id,
            'action' => 'series.draft_created',
        ]);
    }

    public function test_admin_can_update_draft_but_cannot_mass_assign_publication_fields(): void
    {
        $admin = $this->admin();
        $draft = Series::factory()->create(['publication_status' => 'draft', 'published_at' => null]);
        $this->actingAs($admin)->put('/admin/series/'.$draft->id, $this->payload([
            'slug' => $draft->slug,
            'primary_title' => 'Edited authorized fictional work',
            'publication_status' => 'published',
            'published_at' => now()->toDateTimeString(),
        ]))->assertRedirect();
        $draft->refresh();
        $this->assertSame('draft', $draft->publication_status);
        $this->assertNull($draft->published_at);
        $this->assertSame('Edited authorized fictional work', $draft->primary_title);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $draft->id, 'action' => 'series.draft_updated']);
    }

    public function test_admin_cannot_edit_published_or_reviewed_series(): void
    {
        $admin = $this->admin();
        $published = Series::factory()->create(['publication_status' => 'published', 'published_at' => now()]);
        $reviewed = Series::factory()->create(['publication_status' => 'reviewed', 'published_at' => null]);

        foreach ([$published, $reviewed] as $item) {
            $this->actingAs($admin)->get('/admin/series/'.$item->id.'/edit')->assertStatus(409);
            $this->actingAs($admin)->put('/admin/series/'.$item->id, $this->payload())->assertStatus(409);
        }
    }

    public function test_admin_can_create_and_attach_genre_and_replace_selection(): void
    {
        $admin = $this->admin();
        $payload = $this->payload(['new_genre_code' => 'fictional-g09-fantasy', 'new_genre_name' => 'فانتازيا اختبار']);
        $this->actingAs($admin)->post('/admin/series', $payload)->assertRedirect();
        $record = Series::query()->where('slug', $payload['slug'])->firstOrFail();
        $this->assertSame(1, DB::table('series_genres')->where('series_id', $record->id)->count());
        $this->actingAs($admin)->put('/admin/series/'.$record->id, $this->payload([
            'slug' => $record->slug,
            'genre_codes' => [],
        ]))->assertRedirect();
        $this->assertSame(0, DB::table('series_genres')->where('series_id', $record->id)->count());
    }

    public function test_rejects_invalid_slug_and_external_genre_codes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/series', $this->payload([
            'slug' => '../bad-slug',
            'genre_codes' => ['not-in-catalog-gate09'],
        ]))->assertSessionHasErrors(['slug', 'genre_codes.0']);
    }

    public function test_duplicate_slug_is_rejected_and_draft_count_does_not_increase(): void
    {
        $admin = $this->admin();
        $existing = Series::factory()->create();
        $this->actingAs($admin)->post('/admin/series', $this->payload(['slug' => $existing->slug]))
            ->assertSessionHasErrors(['slug']);
        $this->assertSame(1, Series::query()->where('slug', $existing->slug)->count());
    }

    public function test_drafts_do_not_appear_in_public_catalog(): void
    {
        $admin = $this->admin();
        $payload = $this->payload();
        $this->actingAs($admin)->post('/admin/series', $payload)->assertRedirect();
        $this->assertDatabaseHas('series', ['slug' => $payload['slug'], 'publication_status' => 'draft']);
        $this->getJson('/api/v1/catalog/series?search='.rawurlencode($payload['primary_title']))->assertOk()
            ->assertJsonMissing(['slug' => $payload['slug']]);
    }
}
