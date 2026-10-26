<?php

namespace Tests\Feature\Gate11;

use App\Models\CanonicalChapter;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OriginalContentPublicationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate07_test' || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Original content tests ONLY on isolated manga_gate07_test.');
        }
        parent::setUp();
        $this->assertSame('manga_gate07_test', DB::selectOne('select current_database() as name')->name);
        Storage::fake('manga_private');
        config()->set('manga_catalog.public_territory', 'EG');
    }

    private function prepare(): array
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        config()->set('platform_admin.user_id', (string) $admin->id);
        config()->set('platform_admin.email', $admin->email);
        $series = Series::factory()->create(['slug' => 'owner-art-'.Str::lower(Str::random(10))]);
        $edition = Edition::create([
            'series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Owned Arabic',
            'status' => 'draft', 'reading_direction_hint' => 'vertical',
        ]);
        $canonical = CanonicalChapter::create([
            'series_id' => $series->id, 'display_number' => '1', 'sort_key' => '1.00000000',
            'status' => 'draft', 'kind' => 'regular',
        ]);
        $chapter = EditionChapter::create([
            'series_id' => $series->id, 'edition_id' => $edition->id,
            'canonical_chapter_id' => $canonical->id, 'status' => 'draft',
        ]);
        return compact('admin', 'series', 'edition', 'chapter');
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAE0lEQVR4nGM8wcDAwMDAxIBMAQAP9ADOEjHWXgAAAABJRU5ErkJggg==');
    }

    public function test_owned_content_needs_one_admin_confirmation_then_reads_and_revokes(): void
    {
        $work = $this->prepare();
        $admin = $work['admin'];
        $series = $work['series'];
        $chapter = $work['chapter'];
        $prefix = '/admin/series/'.$series->id;
        $this->actingAs($admin)->post($prefix.'/cover', [
            'upload' => UploadedFile::fake()->createWithContent('cover.png', $this->png()),
        ])->assertRedirect();
        $this->post($prefix.'/chapters/'.$chapter->id.'/upload', [
            'upload' => UploadedFile::fake()->createWithContent('page.png', $this->png()),
        ])->assertRedirect();
        $rev = $chapter->revisions()->firstOrFail();
        $this->getJson($prefix.'/chapters/'.$chapter->id.'/readiness')
            ->assertOk()->assertJsonPath('ready', false);
        $this->post($prefix.'/chapters/'.$chapter->id.'/publish', [
            'revision_id' => $rev->id,
        ])->assertSessionHasErrors('publish');
        $this->getJson('/api/v1/catalog/series?lang=ar')->assertDontSee($series->slug);
        $this->post($prefix.'/ownership/original', [])->assertSessionHasErrors('confirm_original');
        $this->assertFalse((bool) $series->fresh()->is_original_content);
        $this->post($prefix.'/ownership/original', ['confirm_original' => true])->assertRedirect();
        $this->assertTrue((bool) $series->fresh()->is_original_content);
        $this->getJson($prefix.'/chapters/'.$chapter->id.'/readiness')
            ->assertOk()->assertJsonPath('ready', true);
        $this->post($prefix.'/chapters/'.$chapter->id.'/publish', [
            'revision_id' => $rev->id,
        ])->assertRedirect();
        $this->getJson('/api/v1/catalog/series?lang=ar')->assertOk()->assertJsonFragment(['slug' => $series->slug]);
        $api = '/api/v1/catalog/chapters/'.$chapter->id.'/pages?lang=ar';
        $pages = $this->getJson($api)->assertOk()->json('data.pages');
        $this->assertCount(1, $pages);
        $this->get($pages[0]['url'])->assertOk();
        $this->post($prefix.'/ownership/revoke', ['confirm_revoke' => true])->assertRedirect();
        $this->getJson($api)->assertNotFound();
        $this->get($pages[0]['url'])->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'series.original_ownership_confirmed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'series.original_ownership_revoked')->count());
    }

    public function test_reader_cannot_claim_ownership_and_form_cannot_spoof_flag(): void
    {
        $work = $this->prepare();
        $reader = User::factory()->create(['email_verified_at' => now()]);
        $prefix = '/admin/series/'.$work['series']->id;
        $this->actingAs($reader)->post($prefix.'/ownership/original', [
            'confirm_original' => true,
        ])->assertForbidden();
        $this->actingAs($work['admin'])->put($prefix.'/content/details', [
            'author_name' => 'Fictional', 'is_original_content' => true,
        ])->assertRedirect();
        $this->assertFalse((bool) $work['series']->fresh()->is_original_content);
        $this->assertNull($work['series']->fresh()->original_content_declared_at);
    }
}
