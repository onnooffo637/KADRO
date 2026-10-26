<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_reactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('series_review_id')->nullable()->constrained('series_reviews')->restrictOnDelete();
            $table->foreignUlid('chapter_comment_id')->nullable()->constrained('chapter_comments')->restrictOnDelete();
            $table->string('kind', 20)->default('like');
            $table->timestampsTz();
            $table->unique(['user_id', 'series_review_id'], 'manga_reaction_one_per_review');
            $table->unique(['user_id', 'chapter_comment_id'], 'manga_reaction_one_per_comment');
        });

        Schema::create('content_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('series_review_id')->nullable()->constrained('series_reviews')->restrictOnDelete();
            $table->foreignUlid('chapter_comment_id')->nullable()->constrained('chapter_comments')->restrictOnDelete();
            $table->string('reason', 24);
            $table->text('details_plain')->nullable();
            $table->string('state', 24)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['reporter_id', 'series_review_id'], 'manga_report_one_per_review');
            $table->unique(['reporter_id', 'chapter_comment_id'], 'manga_report_one_per_comment');
            $table->index(['state', 'created_at'], 'manga_report_queue_idx');
        });

        Schema::create('moderation_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_report_id')->constrained('content_reports')->restrictOnDelete();
            $table->foreignId('moderator_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('reason_plain');
            $table->timestampsTz();
            $table->index(['content_report_id', 'created_at'], 'manga_moderation_history_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE community_reactions ADD CONSTRAINT manga_reaction_single_target_ck CHECK ((series_review_id IS NOT NULL) <> (chapter_comment_id IS NOT NULL))");
            DB::statement("ALTER TABLE community_reactions ADD CONSTRAINT manga_reaction_kind_ck CHECK (kind IN ('like'))");
            DB::statement("ALTER TABLE content_reports ADD CONSTRAINT manga_report_single_target_ck CHECK ((series_review_id IS NOT NULL) <> (chapter_comment_id IS NOT NULL))");
            DB::statement("ALTER TABLE content_reports ADD CONSTRAINT manga_report_reason_ck CHECK (reason IN ('spam','harassment','spoiler','copyright','other'))");
            DB::statement("ALTER TABLE content_reports ADD CONSTRAINT manga_report_state_ck CHECK (state IN ('open','reviewing','resolved','rejected'))");
            DB::statement("ALTER TABLE content_reports ADD CONSTRAINT manga_report_details_ck CHECK (details_plain IS NULL OR (char_length(details_plain) BETWEEN 1 AND 2000 AND position('<' in details_plain) = 0 AND position('>' in details_plain) = 0))");
            DB::statement("ALTER TABLE moderation_actions ADD CONSTRAINT manga_moderation_action_ck CHECK (action IN ('approve','hide','reject','restore','close_report','dismiss_report'))");
            DB::statement("ALTER TABLE moderation_actions ADD CONSTRAINT manga_moderation_reason_ck CHECK (char_length(btrim(reason_plain)) BETWEEN 1 AND 2000)");
        }
    }

    public function down(): void
    {
        foreach (['moderation_actions', 'content_reports', 'community_reactions'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
