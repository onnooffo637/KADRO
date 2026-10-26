<?php

namespace Tests\Feature\Gate12C;

use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Only the isolated PostgreSQL test database is permitted. */
class SeriesEditorWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'manga_gate07_test') {
            $this->fail('Gate12C requires the isolated local manga_gate07_test database.');
        }
        parent::setUp();
        $this->assertSame('manga_gate07_test', DB::selectOne('SELECT current_database() AS name')->name);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        config()->set('platform_admin.user_id', (string) $admin->id);
        config()->set('platform_admin.email', $admin->email);
        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'gate12c-'.Str::lower(Str::random(10)),
            'primary_title' => 'Entirely fictional original manga',
            'format' => 'webtoon', 'original_language' => 'ar',
            'completion_status' => 'ongoing', 'synopsis' => 'Fictional test synopsis',
            'author_name' => 'Fictional writer', 'illustrator_name' => 'Fictional illustrator',
            'age_rating' => '12+',
            'aliases' => [['locale' => 'en', 'value' => 'Other Fictional Title']],
            'genre_codes' => [], 'tag_codes' => [],
            'new_genre_code' => '', 'new_genre_name' => '',
            'new_tag_code' => 'gate12c-test-tag', 'new_tag_name' => 'اختباري',
        ], $overrides);
    }

    public function test_admin_creates_unpublished_draft_with_metadata_tags_and_aliases(): void
    {
        $admin = $this->admin();
        $data = $this->payload(['publication_status' => 'published', 'published_at' => now()->toDateTimeString()]);
        $this->actingAs($admin)->post('/admin/series', $data)->assertRedirect()->assertSessionHasNoErrors();
        $work = Series::query()->where('slug', $data['slug'])->firstOrFail();
        $this->assertSame('draft', $work->publication_status);
        $this->assertNull($work->published_at);
        $this->assertSame('Fictional writer', $work->author_name);
        $this->assertSame('Fictional illustrator', $work->illustrator_name);
        $this->assertSame('12+', $work->age_rating);
        $this->assertDatabaseHas('title_aliases', [
            'series_id' => $work->id, 'locale' => 'en', 'normalized_value' => 'other fictional title',
        ]);
        $this->assertDatabaseHas('series_tags', ['series_id' => $work->id]);
        $this->actingAs($admin)->get('/admin/series/'.$work->id.'/edit')->assertOk();
    }

    public function test_update_replaces_aliases_and_tags_and_preserves_draft(): void
    {
        $admin = $this->admin();
        $data = $this->payload();
        $this->actingAs($admin)->post('/admin/series', $data)->assertRedirect();
        $work = Series::query()->where('slug', $data['slug'])->firstOrFail();
        $this->actingAs($admin)->put('/admin/series/'.$work->id, $this->payload([
            'slug' => $work->slug, 'aliases' => [['locale' => 'ko', 'value' => 'Another test name']],
            'new_tag_code' => '', 'new_tag_name' => '', 'tag_codes' => [],
            'author_name' => 'Updated fictional writer',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated fictional writer', $work->fresh()->author_name);
        $this->assertDatabaseMissing('title_aliases', ['series_id' => $work->id, 'locale' => 'en']);
        $this->assertDatabaseHas('title_aliases', ['series_id' => $work->id, 'locale' => 'ko']);
        $this->assertSame(0, DB::table('series_tags')->where('series_id', $work->id)->count());
    }

    public function test_duplicate_alias_rejected_transactionally(): void
    {
        $admin = $this->admin();
        $data = $this->payload(['aliases' => [
            ['locale' => 'en', 'value' => 'Same Fictional Title'],
            ['locale' => 'en', 'value' => ' same   fictional title '],
        ]]);
        $this->actingAs($admin)->post('/admin/series', $data)->assertSessionHasErrors('aliases');
        $this->assertSame(0, Series::query()->where('slug', $data['slug'])->count());
    }

    public function test_invalid_alias_tag_and_reader_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/series', $this->payload([
            'tag_codes' => ['nonexistent-tag-12c'],
            'aliases' => [['locale' => '../', 'value' => 'Invalid locale']],
        ]))->assertSessionHasErrors(['tag_codes.0', 'aliases.0.locale']);
        $reader = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($reader)->get('/admin/series/create')->assertForbidden();
        $this->actingAs($reader)->post('/admin/series', $this->payload())->assertForbidden();
    }

    public function test_gate09_legacy_edit_does_not_erase_unsent_metadata(): void
    {
        $admin = $this->admin();
        $work = Series::factory()->create([
            'publication_status' => 'draft', 'published_at' => null,
            'author_name' => 'Keep original writer', 'age_rating' => '16+',
        ]);
        $this->actingAs($admin)->put('/admin/series/'.$work->id, [
            'slug' => $work->slug, 'primary_title' => 'Updated fictional title',
            'format' => 'manga', 'original_language' => 'ar', 'completion_status' => 'ongoing',
            'genre_codes' => [], 'new_genre_code' => '', 'new_genre_name' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Keep original writer', $work->fresh()->author_name);
        $this->assertSame('16+', $work->fresh()->age_rating);
    }
}
