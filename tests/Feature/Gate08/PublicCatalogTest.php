<?php

namespace Tests\Feature\Gate08;

use App\Models\CanonicalChapter;
use App\Models\ChapterRevision;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\MediaAsset;
use App\Models\RevisionPage;
use App\Models\RightsGrant;
use App\Models\RightsLicense;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Only execute on the disposable manga_gate07_test PostgreSQL database. */
class PublicCatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate07_test'
            || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate 08 catalog tests require isolated manga_gate07_test PostgreSQL.');
        }

        parent::setUp();
        $this->assertSame('manga_gate07_test', DB::selectOne('SELECT current_database() AS name')->name);
        config()->set('manga_catalog.public_territory', 'EG');
    }

    /** Create fully fictional approved catalog metadata; NO real copyrighted pages. */
    private function fictionalPublication(bool $withRights = true): array
    {
        $now = now();
        $owner = User::factory()->create();
        $series = Series::factory()->create([
            'slug' => 'fictional-gate08-starlight-'.Str::lower(Str::random(7)),
            'primary_title' => 'Starlight Catalog Fixture',
            'publication_status' => 'published',
            'published_at' => $now->copy()->subDays(2),
        ]);
        $canonical = CanonicalChapter::create([
            'series_id' => $series->id,
            'display_number' => '12.5',
            'sort_key' => '12.50000000',
            'title' => 'Fictional test chapter',
            'kind' => 'regular',
            'status' => 'published',
        ]);
        $edition = Edition::create([
            'series_id' => $series->id,
            'language_code' => 'ar',
            'label' => 'Arabic test edition',
            'status' => 'published',
            'published_at' => $now->copy()->subDay(),
        ]);
        $editionChapter = EditionChapter::create([
            'series_id' => $series->id,
            'edition_id' => $edition->id,
            'canonical_chapter_id' => $canonical->id,
        ]);
        $revision = ChapterRevision::create([
            'edition_chapter_id' => $editionChapter->id,
            'revision_no' => 1,
            'submitter_id' => $owner->id,
        ]);
        $asset = MediaAsset::create([
            'storage_disk' => 'private',
            'storage_key' => 'gate08/fictional/'.Str::lower(Str::random(16)).'.png',
            'mime' => 'image/png',
            'sha256' => str_repeat('f', 64),
            'bytes' => 16,
            'width' => 100,
            'height' => 150,
            'status' => 'ready',
        ]);
        RevisionPage::create([
            'chapter_revision_id' => $revision->id,
            'media_asset_id' => $asset->id,
            'page_index' => 1,
            'width' => 100,
            'height' => 150,
        ]);
        $revision->update([
            'status' => 'reviewed',
            'reviewer_id' => User::factory()->create()->id,
            'asset_manifest_hash' => str_repeat('a', 64),
            'reviewed_at' => $now->copy()->subHour(),
        ]);
        $revision->update(['status' => 'published', 'published_at' => $now->copy()->subMinutes(10)]);
        $editionChapter->update([
            'current_published_revision_id' => $revision->id,
            'status' => 'published',
        ]);

        $license = null;
        $grant = null;
        if ($withRights) {
            $license = RightsLicense::create([
                'holder_name' => 'Fictional authorized test holder',
                'document_ref' => 'local-fixture/test-license',
                'approval_status' => 'approved',
                'approved_by' => $owner->id,
                'approved_at' => $now->copy()->subDay(),
                'valid_from' => $now->copy()->subDays(2),
                'valid_until' => $now->copy()->addDays(2),
            ]);
            $grant = RightsGrant::create([
                'license_id' => $license->id,
                'series_id' => $series->id,
                'edition_id' => $edition->id,
                'can_publish' => true,
                'can_translate' => true, // fictional Korean source -> Arabic authorized translation
                'status' => 'active',
                'valid_from' => $now->copy()->subDays(2),
                'valid_until' => $now->copy()->addDays(2),
            ]);
            DB::table('rights_territories')->insert(['grant_id' => $grant->id, 'territory_code' => 'EG']);
            DB::table('rights_languages')->insert(['grant_id' => $grant->id, 'language_code' => 'ar']);
        }

        return compact('series', 'edition', 'editionChapter', 'canonical', 'revision', 'asset', 'license', 'grant');
    }

    public function test_no_configured_territory_means_empty_catalog_even_when_request_claims_eg(): void
    {
        $fixture = $this->fictionalPublication();
        config()->set('manga_catalog.public_territory', null);

        $this->withHeader('X-Country', 'EG')
            ->getJson('/api/v1/catalog/series?lang=ar&territory=EG')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/catalog/series/'.$fixture['series']->slug.'?lang=ar')
            ->assertNotFound();
    }

    public function test_published_work_without_valid_rights_stays_hidden(): void
    {
        $fixture = $this->fictionalPublication(false);
        $this->getJson('/api/v1/catalog/series?lang=ar')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/catalog/series/'.$fixture['series']->slug)
            ->assertNotFound();
    }

    public function test_approved_publication_is_listed_with_safe_metadata_only(): void
    {
        $fixture = $this->fictionalPublication();
        $response = $this->getJson('/api/v1/catalog/series?lang=ar');
        $response->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', $fixture['series']->slug)
            ->assertJsonPath('data.0.cover_url', null)
            ->assertDontSee('storage_key')->assertDontSee('local-fixture/test-license');
    }

    public function test_show_details_and_chapters_are_sorted_and_restricted(): void
    {
        $fixture = $this->fictionalPublication();
        $slug = $fixture['series']->slug;

        $this->getJson('/api/v1/catalog/series/'.$slug.'?lang=ar')
            ->assertOk()->assertJsonPath('data.editions.0.id', $fixture['edition']->id);
        $this->getJson('/api/v1/catalog/series/'.$slug.'/chapters?lang=ar')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.display_number', '12.5')
            ->assertJsonMissingPath('data.0.media_asset_id');
    }

    public function test_wrong_territory_or_language_denies_visibility(): void
    {
        $fixture = $this->fictionalPublication();
        config()->set('manga_catalog.public_territory', 'US');
        $this->getJson('/api/v1/catalog/series/'.$fixture['series']->slug)->assertNotFound();
        config()->set('manga_catalog.public_territory', 'EG');
        $this->getJson('/api/v1/catalog/series/'.$fixture['series']->slug.'?lang=en')->assertNotFound();
    }

    public function test_revoked_or_expired_license_removes_visibility_immediately(): void
    {
        $fixture = $this->fictionalPublication();
        $fixture['license']->update(['approval_status' => 'revoked']);
        $this->getJson('/api/v1/catalog/series?lang=ar')->assertJsonPath('meta.total', 0);
    }

    public function test_future_publication_or_nonready_assets_are_not_exposed(): void
    {
        $fixture = $this->fictionalPublication();
        $fixture['series']->update(['published_at' => now()->addDay()]);
        $this->getJson('/api/v1/catalog/series?lang=ar')->assertJsonPath('meta.total', 0);
        $fixture['series']->update(['published_at' => now()->subDay()]);
        $fixture['asset']->update(['status' => 'quarantined']);
        $this->getJson('/api/v1/catalog/series?lang=ar')->assertJsonPath('meta.total', 0);
    }

    public function test_search_and_genre_filter_only_return_authorized_work(): void
    {
        $fixture = $this->fictionalPublication();
        $genreId = (string) Str::ulid();
        DB::table('genres')->insert([
            'id' => $genreId, 'code' => 'fantasy', 'name' => 'Fantasy',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('series_genres')->insert(['series_id' => $fixture['series']->id, 'genre_id' => $genreId]);

        $this->getJson('/api/v1/catalog/series?search=Starlight&genre=fantasy&lang=ar')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/catalog/genres?lang=ar')
            ->assertOk()->assertJsonPath('data.0.code', 'fantasy');
        $this->getJson('/api/v1/catalog/series?search=unrelated')->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_page_size_format_and_language_are_rejected(): void
    {
        $this->getJson('/api/v1/catalog/series?per_page=500')->assertUnprocessable();
        $this->getJson('/api/v1/catalog/series?format=secret')->assertUnprocessable();
        $this->getJson('/api/v1/catalog/series?lang=../../a')->assertUnprocessable();
    }
}
