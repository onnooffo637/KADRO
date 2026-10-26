<?php

namespace Tests\Feature\Gate06;

use App\Domain\Reading\ReadingSyncService;
use App\Models\CanonicalChapter;
use App\Models\ChapterRevision;
use App\Models\CustomList;
use App\Models\CustomListItem;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\LibraryEntry;
use App\Models\Series;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReaderDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        # Fail closed before RefreshDatabase can migrate or reset any non-test database.
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate06_test'
            || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate 06 reader tests require isolated local PostgreSQL manga_gate06_test.');
        }
        parent::setUp();
    }

    # Returns a fictional series, its edition and two edition chapters.
    private function makeEdition(): array
    {
        $series = Series::factory()->create();
        $edition = Edition::create(['series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Draft test edition']);
        $first = CanonicalChapter::create(['series_id' => $series->id, 'display_number' => '1', 'sort_key' => '1.00000000']);
        $second = CanonicalChapter::create(['series_id' => $series->id, 'display_number' => '2', 'sort_key' => '2.00000000']);
        $a = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $first->id]);
        $b = EditionChapter::create(['series_id' => $series->id, 'edition_id' => $edition->id, 'canonical_chapter_id' => $second->id]);
        return [$series, $edition, $a, $b];
    }

    public function test_library_is_user_scoped_and_unique_per_series(): void
    {
        [$series] = $this->makeEdition();
        $one = User::factory()->create();
        $two = User::factory()->create();
        LibraryEntry::create(['user_id' => $one->id, 'series_id' => $series->id, 'shelf' => 'reading']);
        LibraryEntry::create(['user_id' => $two->id, 'series_id' => $series->id]);
        $this->assertSame(2, DB::table('library_entries')->count());
        $this->expectException(QueryException::class);
        LibraryEntry::create(['user_id' => $one->id, 'series_id' => $series->id]);
    }

    public function test_library_rejects_unsupported_shelf(): void
    {
        [$series] = $this->makeEdition();
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        LibraryEntry::create(['user_id' => $user->id, 'series_id' => $series->id, 'shelf' => 'public']);
    }

    public function test_custom_lists_are_private_and_items_unique(): void
    {
        [$series] = $this->makeEdition();
        $user = User::factory()->create();
        $list = CustomList::create(['user_id' => $user->id, 'name' => 'أقرأ لاحقًا', 'normalized_name' => 'أقرأ لاحقًا']);
        $this->assertFalse($list->is_public);
        CustomListItem::create(['custom_list_id' => $list->id, 'series_id' => $series->id]);
        $this->expectException(QueryException::class);
        CustomListItem::create(['custom_list_id' => $list->id, 'series_id' => $series->id]);
    }

    public function test_public_list_flag_is_denied_by_database(): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('custom_lists')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id,
            'name' => 'Unsafe public', 'normalized_name' => 'unsafe public', 'is_public' => true,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_replay_is_idempotent_and_payload_reuse_is_rejected(): void
    {
        [, $edition, $first] = $this->makeEdition();
        $user = User::factory()->create();
        $service = new ReadingSyncService();
        $op = (string) Str::uuid();
        $firstAck = $service->apply($user->id, $edition->id, $first->id, 3, 0, $op);
        $sameAck = $service->apply($user->id, $edition->id, $first->id, 3, 0, $op);
        $this->assertSame(1, $firstAck['revision']);
        $this->assertTrue($firstAck['updated_last']);
        $this->assertTrue($sameAck['replay']);
        $this->assertSame(1, $sameAck['revision']);
        $this->assertSame(1, DB::table('reading_sync_operations')->count());
        $this->assertSame(1, (int) DB::table('reading_progress')->value('sync_revision'));
        $this->expectException(DomainException::class);
        $service->apply($user->id, $edition->id, $first->id, 4, 0, $op);
    }

    public function test_rereading_old_chapter_never_erases_furthest_progress(): void
    {
        [, $edition, $first, $second] = $this->makeEdition();
        $user = User::factory()->create();
        $service = new ReadingSyncService();
        $service->apply($user->id, $edition->id, $second->id, 9, 0, (string) Str::uuid());
        $last = $service->apply($user->id, $edition->id, $first->id, 2, 1, (string) Str::uuid());
        $this->assertTrue($last['updated_last']);
        $this->assertFalse($last['advanced_furthest']);
        $progress = DB::table('reading_progress')->first();
        $this->assertSame($first->id, $progress->last_edition_chapter_id);
        $this->assertSame(2, (int) $progress->last_page_index);
        $this->assertSame($second->id, $progress->furthest_edition_chapter_id);
        $this->assertSame(9, (int) $progress->furthest_page_index);
    }

    public function test_stale_offline_operation_does_not_replace_last_but_advances_furthest(): void
    {
        [, $edition, $first, $second] = $this->makeEdition();
        $user = User::factory()->create();
        $service = new ReadingSyncService();
        $service->apply($user->id, $edition->id, $first->id, 2, 0, (string) Str::uuid());
        $service->apply($user->id, $edition->id, $first->id, 5, 1, (string) Str::uuid());
        $stale = $service->apply($user->id, $edition->id, $second->id, 3, 0, (string) Str::uuid());
        $this->assertFalse($stale['updated_last']);
        $this->assertTrue($stale['advanced_furthest']);
        $progress = DB::table('reading_progress')->first();
        $this->assertSame($first->id, $progress->last_edition_chapter_id);
        $this->assertSame(5, (int) $progress->last_page_index);
        $this->assertSame($second->id, $progress->furthest_edition_chapter_id);
        $this->assertSame(3, (int) $progress->furthest_page_index);
        $this->assertSame(3, (int) $progress->sync_revision);
    }

    public function test_future_revision_and_wrong_edition_chapter_are_rejected(): void
    {
        [, $edition, $first] = $this->makeEdition();
        [, $foreignEdition, $foreignChapter] = $this->makeEdition();
        $user = User::factory()->create();
        $service = new ReadingSyncService();
        try {
            $service->apply($user->id, $edition->id, $first->id, 1, 4, (string) Str::uuid());
            $this->fail('Future revision must be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('ahead', $e->getMessage());
        }
        $this->expectException(DomainException::class);
        $service->apply($user->id, $edition->id, $foreignChapter->id, 1, 0, (string) Str::uuid());
    }

    public function test_database_cannot_link_last_position_to_chapter_in_other_edition(): void
    {
        [, $edition] = $this->makeEdition();
        [, , $otherChapter] = $this->makeEdition();
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('reading_progress')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'edition_id' => $edition->id,
            'last_edition_chapter_id' => $otherChapter->id, 'last_page_index' => 1,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_offline_download_requires_rights_metadata_before_available(): void
    {
        [, , $first] = $this->makeEdition();
        $revision = ChapterRevision::create(['edition_chapter_id' => $first->id, 'revision_no' => 1]);
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('offline_downloads')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id,
            'edition_chapter_id' => $first->id, 'chapter_revision_id' => $revision->id,
            'device_key_hash' => str_repeat('a', 64), 'state' => 'available',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_offline_download_cannot_reference_revision_of_another_chapter(): void
    {
        [, , $first, $second] = $this->makeEdition();
        $revision = ChapterRevision::create(['edition_chapter_id' => $first->id, 'revision_no' => 1]);
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('offline_downloads')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id,
            'edition_chapter_id' => $second->id, 'chapter_revision_id' => $revision->id,
            'device_key_hash' => str_repeat('b', 64), 'state' => 'queued',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }
}
