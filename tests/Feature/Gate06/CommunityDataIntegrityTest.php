<?php

namespace Tests\Feature\Gate06;

use App\Models\CanonicalChapter;
use App\Models\Edition;
use App\Models\EditionChapter;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        # Fail before RefreshDatabase can use any database other than the isolated test DB.
        if (getenv('APP_ENV') !== 'testing'
            || getenv('DB_CONNECTION') !== 'pgsql'
            || getenv('DB_DATABASE') !== 'manga_gate06_test'
            || getenv('DB_HOST') !== '127.0.0.1') {
            $this->fail('Gate 06 community tests require isolated PostgreSQL: manga_gate06_test.');
        }

        parent::setUp();
    }

    private function insertReview(User $user, Series $series, int $rating = 5): string
    {
        $id = (string) Str::ulid();
        DB::table('series_reviews')->insert([
            'id' => $id, 'user_id' => $user->id, 'series_id' => $series->id,
            'rating' => $rating, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        return $id;
    }

    private function makeEditionChapter(): EditionChapter
    {
        $series = Series::factory()->create();
        $edition = Edition::create([
            'series_id' => $series->id, 'language_code' => 'ar', 'label' => 'Fictional test',
        ]);
        $chapter = CanonicalChapter::create([
            'series_id' => $series->id, 'display_number' => '1', 'sort_key' => '1.00000000',
        ]);
        return EditionChapter::create([
            'series_id' => $series->id, 'edition_id' => $edition->id,
            'canonical_chapter_id' => $chapter->id,
        ]);
    }

    private function insertComment(User $user, EditionChapter $chapter, ?string $parentId = null): string
    {
        $id = (string) Str::ulid();
        DB::table('chapter_comments')->insert([
            'id' => $id, 'user_id' => $user->id,
            'edition_chapter_id' => $chapter->id, 'parent_id' => $parentId,
            'body_plain' => 'Fictional review comment',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        return $id;
    }

    public function test_reviews_default_to_pending_and_spoilers_not_revealed(): void
    {
        $user = User::factory()->create();
        $series = Series::factory()->create();
        $id = $this->insertReview($user, $series);
        $review = DB::table('series_reviews')->where('id', $id)->first();
        $this->assertSame('pending', $review->moderation_status);
        $this->assertFalse((bool) $review->is_spoiler);
        $this->assertSame(0, DB::table('series_reviews')->where('moderation_status', 'approved')->count());
    }

    public function test_rating_outside_one_to_five_is_denied_by_database(): void
    {
        $this->expectException(QueryException::class);
        $this->insertReview(User::factory()->create(), Series::factory()->create(), 6);
    }

    public function test_user_cannot_duplicate_review_for_same_series(): void
    {
        $user = User::factory()->create();
        $series = Series::factory()->create();
        $this->insertReview($user, $series);
        $this->expectException(QueryException::class);
        $this->insertReview($user, $series);
    }

    public function test_comments_default_to_pending_and_reply_within_same_chapter_works(): void
    {
        $user = User::factory()->create();
        $chapter = $this->makeEditionChapter();
        $parent = $this->insertComment($user, $chapter);
        $reply = $this->insertComment($user, $chapter, $parent);
        $this->assertSame($parent, DB::table('chapter_comments')->where('id', $reply)->value('parent_id'));
        $this->assertSame('pending', DB::table('chapter_comments')->where('id', $reply)->value('moderation_status'));
    }

    public function test_reply_cannot_reference_a_comment_from_another_chapter(): void
    {
        $user = User::factory()->create();
        $first = $this->makeEditionChapter();
        $second = $this->makeEditionChapter();
        $parent = $this->insertComment($user, $first);
        $this->expectException(QueryException::class);
        $this->insertComment($user, $second, $parent);
    }

    public function test_comment_storage_rejects_html_markers(): void
    {
        $user = User::factory()->create();
        $chapter = $this->makeEditionChapter();
        $this->expectException(QueryException::class);
        DB::table('chapter_comments')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id,
            'edition_chapter_id' => $chapter->id,
            'body_plain' => '<script>unsafe</script>',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_reaction_cannot_target_both_review_and_comment(): void
    {
        $user = User::factory()->create();
        $reviewId = $this->insertReview($user, Series::factory()->create());
        $commentId = $this->insertComment($user, $this->makeEditionChapter());
        $this->expectException(QueryException::class);
        DB::table('community_reactions')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id,
            'series_review_id' => $reviewId, 'chapter_comment_id' => $commentId,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_reaction_is_unique_per_user_and_comment(): void
    {
        $user = User::factory()->create();
        $commentId = $this->insertComment($user, $this->makeEditionChapter());
        $row = [
            'user_id' => $user->id, 'chapter_comment_id' => $commentId,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ];
        DB::table('community_reactions')->insert(['id' => (string) Str::ulid()] + $row);
        $this->expectException(QueryException::class);
        DB::table('community_reactions')->insert(['id' => (string) Str::ulid()] + $row);
    }

    public function test_report_defaults_open_and_duplicate_by_reporter_is_denied(): void
    {
        $user = User::factory()->create();
        $commentId = $this->insertComment($user, $this->makeEditionChapter());
        $row = [
            'reporter_id' => $user->id, 'chapter_comment_id' => $commentId,
            'reason' => 'spam', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ];
        $id = (string) Str::ulid();
        DB::table('content_reports')->insert(['id' => $id] + $row);
        $this->assertSame('open', DB::table('content_reports')->where('id', $id)->value('state'));
        $this->expectException(QueryException::class);
        DB::table('content_reports')->insert(['id' => (string) Str::ulid()] + $row);
    }

    public function test_notifications_store_only_event_keys_and_are_deduplicated(): void
    {
        $user = User::factory()->create();
        $columns = DB::getSchemaBuilder()->getColumnListing('in_app_notifications');
        $this->assertNotContains('body_plain', $columns);
        $this->assertNotContains('preview_text', $columns);
        $this->assertNotContains('spoiler_text', $columns);
        $row = [
            'user_id' => $user->id, 'event_key' => 'series_updated',
            'dedupe_key' => 'fictional:series:2026',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ];
        DB::table('in_app_notifications')->insert(['id' => (string) Str::ulid()] + $row);
        $this->expectException(QueryException::class);
        DB::table('in_app_notifications')->insert(['id' => (string) Str::ulid()] + $row);
    }

    public function test_local_notifications_require_opt_in(): void
    {
        $user = User::factory()->create();
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $this->assertFalse((bool) DB::table('notification_preferences')->where('user_id', $user->id)->value('local_notifications_opt_in'));
    }

    public function test_series_follows_are_unique_per_user(): void
    {
        $user = User::factory()->create();
        $series = Series::factory()->create();
        $row = ['user_id' => $user->id, 'series_id' => $series->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')];
        DB::table('series_follows')->insert($row);
        $this->expectException(QueryException::class);
        DB::table('series_follows')->insert($row);
    }

    public function test_audit_logs_are_immutable_after_insertion(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        DB::table('audit_logs')->insert([
            'id' => $id, 'actor_id' => $user->id,
            'subject_type' => 'series_review', 'subject_id' => (string) Str::ulid(),
            'action' => 'review_submitted',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'rewritten']);
    }
    public function test_report_cannot_point_to_two_types_of_content(): void
    {
        $user = User::factory()->create();
        $reviewId = $this->insertReview($user, Series::factory()->create());
        $commentId = $this->insertComment($user, $this->makeEditionChapter());
        $this->expectException(QueryException::class);
        DB::table('content_reports')->insert([
            'id' => (string) Str::ulid(), 'reporter_id' => $user->id,
            'series_review_id' => $reviewId, 'chapter_comment_id' => $commentId,
            'reason' => 'spam', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function test_moderation_action_requires_existing_report_and_moderator(): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('moderation_actions')->insert([
            'id' => (string) Str::ulid(),
            'content_report_id' => (string) Str::ulid(),
            'moderator_id' => $user->id,
            'action' => 'hide', 'reason_plain' => 'Confirmed spam',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

}
