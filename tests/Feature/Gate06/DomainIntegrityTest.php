<?php

namespace Tests\Feature\Gate06;

use App\Models\CanonicalChapter;
use App\Models\ChapterRevision;
use App\Models\MediaAsset;
use App\Models\RevisionPage;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\RightsGrant;
use App\Models\RightsLicense;
use App\Models\Series;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        # Never let RefreshDatabase run against the regular manga_app database.
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate06_test'
            || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate 06 tests require an isolated local PostgreSQL DB: manga_gate06_test.');
        }

        parent::setUp();
    }

    private function createChapter(Series $series, string $number = '12.5', string $sort = '12.50000000'): CanonicalChapter
    {
        return CanonicalChapter::create([
            'series_id' => $series->id, 'display_number' => $number,
            'sort_key' => $sort, 'kind' => 'regular',
        ]);
    }

    public function test_accepts_fractional_and_extra_chapter_numbers_with_independent_rank(): void
    {
        $series = Series::factory()->create();
        $first = $this->createChapter($series);
        $extra = $this->createChapter($series, 'extra', '13.00000000');
        $this->assertEquals('12.5', $first->display_number);
        $this->assertEquals('extra', $extra->display_number);
        $this->assertCount(2, $series->chapters);
    }

    public function test_cannot_duplicate_rank_within_series(): void
    {
        $series = Series::factory()->create();
        $this->createChapter($series);
        $this->expectException(QueryException::class);
        $this->createChapter($series, 'alt', '12.50000000');
    }

    public function test_cannot_link_edition_chapter_to_canonical_chapter_of_another_series(): void
    {
        $a = Series::factory()->create();
        $b = Series::factory()->create();
        $foreignChapter = $this->createChapter($b);
        $edition = Edition::create([
            'series_id' => $a->id, 'language_code' => 'ar', 'label' => 'Edition A',
        ]);
        $this->expectException(QueryException::class);
        EditionChapter::create([
            'series_id' => $a->id,
            'edition_id' => $edition->id,
            'canonical_chapter_id' => $foreignChapter->id,
        ]);
    }

    public function test_cannot_point_to_revision_from_another_edition_chapter(): void
    {
        $series = Series::factory()->create();
        $a = $this->createChapter($series, '1', '1.00000000');
        $b = $this->createChapter($series, '2', '2.00000000');
        $edition = Edition::create(['series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Test']);
        $left = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $a->id]);
        $right = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $b->id]);
        $revision = ChapterRevision::create(['edition_chapter_id' => $left->id, 'revision_no' => 1]);
        $this->expectException(QueryException::class);
        $right->update(['current_published_revision_id' => $revision->id]);
    }

    public function test_default_rights_deny_even_if_series_is_marked_published(): void
    {
        $now = CarbonImmutable::now('UTC');
        $series = Series::factory()->create(['publication_status' => 'published', 'published_at' => $now->subDay()]);
        $edition = Edition::create([
            'series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Arabic',
            'status' => 'published', 'published_at' => $now->subDay(),
        ]);

        $this->assertFalse(Series::query()->publiclyReadableIn('EG', 'ar', $now)->whereKey($series->id)->exists());
        $this->assertFalse(Edition::query()->publiclyReadableIn(null, 'ar', $now)->whereKey($edition->id)->exists());
    }

    public function test_unknown_rights_and_empty_territories_never_allow_reading(): void
    {
        $now = CarbonImmutable::now('UTC');
        $series = Series::factory()->create(['publication_status' => 'published', 'published_at' => $now->subDay()]);
        $chapter = $this->createChapter($series);
        $edition = Edition::create(['series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Arabic', 'status' => 'published', 'published_at' => $now->subDay()]);
        $ec = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $chapter->id]);
        # Leaving the chapter unpublished is deliberately sufficient to deny visibility.
        $this->assertFalse(Edition::query()->publiclyReadableIn('EG', 'ar', $now)->whereKey($edition->id)->exists());
    }

    public function test_license_expiry_must_be_later_than_start(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->expectException(QueryException::class);
        RightsLicense::create([
            'holder_name' => 'Fictional rights holder', 'document_ref' => 'local-test/none',
            'valid_from' => $now, 'valid_until' => $now->subDay(),
        ]);
    }

    public function test_cannot_delete_canonical_chapter_with_active_edition_reference(): void
    {
        $series = Series::factory()->create();
        $chapter = $this->createChapter($series);
        $edition = Edition::create(['series_id' => $series->id, 'language_code' => 'en', 'label' => 'English']);
        EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $chapter->id]);
        $this->expectException(QueryException::class);
        DB::table('canonical_chapters')->where('id', $chapter->id)->delete();
    }

    public function test_approved_valid_territory_and_language_grant_is_required_for_public_reading(): void
    {
        $now = CarbonImmutable::now('UTC');
        $owner = User::factory()->create();
        $series = Series::factory()->create(['publication_status' => 'published', 'published_at' => $now->subDay()]);
        $chapter = $this->createChapter($series);
        $edition = Edition::create([
            'series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Approved test edition',
            'status' => 'published', 'published_at' => $now->subDay(),
        ]);
        $ec = EditionChapter::create([
            'series_id' => $series->id, 'edition_id' => $edition->id,
            'canonical_chapter_id' => $chapter->id,
        ]);
        $revision = ChapterRevision::create([
            'edition_chapter_id' => $ec->id, 'revision_no' => 1,
            'submitter_id' => $owner->id, 'status' => 'draft',
        ]);
        $asset = MediaAsset::create([
            'storage_key' => 'test/fictional-only/page1.png', 'mime' => 'image/png',
            'sha256' => str_repeat('0', 64), 'bytes' => 8, 'width' => 100,
            'height' => 100, 'status' => 'ready',
        ]);
        RevisionPage::create([
            'chapter_revision_id' => $revision->id, 'media_asset_id' => $asset->id,
            'page_index' => 1, 'width' => 100, 'height' => 100,
        ]);
        $revision->update([
            'status' => 'reviewed', 'reviewer_id' => User::factory()->create()->id,
            'asset_manifest_hash' => str_repeat('a', 64), 'reviewed_at' => $now,
        ]);
        $revision->update(['status' => 'published', 'published_at' => $now]);
        $ec->update(['current_published_revision_id' => $revision->id, 'status' => 'published']);
        $license = RightsLicense::create([
            'holder_name' => 'Fictional local-only rights holder',
            'document_ref' => 'fake:nonexistent-document-ref',
            'approval_status' => 'approved', 'approved_by' => $owner->id,
            'approved_at' => $now->subDay(), 'valid_from' => $now->subDay(),
            'valid_until' => $now->addDay(),
        ]);
        $grant = RightsGrant::create([
            'license_id' => $license->id, 'series_id' => $series->id,
            'edition_id' => $edition->id, 'can_publish' => true,
            'can_translate' => false, 'can_offline' => false,
            'status' => 'active', 'valid_from' => $now->subDay(),
            'valid_until' => $now->addDay(),
        ]);
        $this->assertFalse(Series::query()->publiclyReadableIn('EG', 'ar', $now)->whereKey($series->id)->exists());
        DB::table('rights_territories')->insert(['grant_id' => $grant->id, 'territory_code' => 'EG']);
        DB::table('rights_languages')->insert(['grant_id' => $grant->id, 'language_code' => 'ar']);
        $this->assertTrue(Series::query()->publiclyReadableIn('EG', 'ar', $now)->whereKey($series->id)->exists());
        $this->assertFalse(Series::query()->publiclyReadableIn('US', 'ar', $now)->whereKey($series->id)->exists());
        $this->assertFalse(Series::query()->publiclyReadableIn('EG', 'en', $now)->whereKey($series->id)->exists());
        $this->assertFalse(Series::query()->publiclyReadableIn(null, 'ar', $now)->whereKey($series->id)->exists());
        $this->assertFalse(Series::query()->publiclyReadableIn('EG', 'ar', $now->addDays(2))->whereKey($series->id)->exists());
    }

    public function test_reviewed_pages_cannot_be_replaced(): void
    {
        $series = Series::factory()->create();
        $chapter = $this->createChapter($series);
        $edition = Edition::create(['series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Test']);
        $ec = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $chapter->id]);
        $revision = ChapterRevision::create(['edition_chapter_id' => $ec->id, 'revision_no' => 1, 'status' => 'reviewed']);
        $asset = MediaAsset::create([
            'storage_key' => 'test/fictional-only/reviewed.png', 'mime' => 'image/png',
            'sha256' => str_repeat('0', 64), 'bytes' => 8, 'width' => 100, 'height' => 100,
        ]);
        $this->expectException(QueryException::class);
        RevisionPage::create([
            'chapter_revision_id' => $revision->id, 'media_asset_id' => $asset->id,
            'page_index' => 1, 'width' => 100, 'height' => 100,
        ]);
    }
}
