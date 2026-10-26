<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body_plain')->nullable();
            $table->boolean('is_spoiler')->default(false);
            $table->string('moderation_status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['user_id', 'series_id'], 'manga_review_author_series_unique');
            $table->index(['series_id', 'moderation_status', 'created_at'], 'manga_review_public_idx');
        });

        Schema::create('chapter_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('edition_chapter_id')->constrained('edition_chapters')->restrictOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->text('body_plain');
            $table->boolean('is_spoiler')->default(false);
            $table->string('moderation_status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['id', 'edition_chapter_id'], 'manga_comment_chapter_pair');
            $table->foreign(['parent_id', 'edition_chapter_id'], 'manga_comment_same_chapter_fk')
                ->references(['id', 'edition_chapter_id'])->on('chapter_comments')->restrictOnDelete();
            $table->index(['edition_chapter_id', 'moderation_status', 'created_at'], 'manga_comment_visibility_idx');
            $table->index(['parent_id', 'created_at'], 'manga_comment_replies_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE series_reviews ADD CONSTRAINT manga_review_rating_ck CHECK (rating BETWEEN 1 AND 5)');
            DB::statement("ALTER TABLE series_reviews ADD CONSTRAINT manga_review_status_ck CHECK (moderation_status IN ('pending','approved','hidden','rejected'))");
            DB::statement("ALTER TABLE series_reviews ADD CONSTRAINT manga_review_body_ck CHECK (body_plain IS NULL OR (char_length(btrim(body_plain)) BETWEEN 1 AND 4000 AND position('<' in body_plain) = 0 AND position('>' in body_plain) = 0))");
            DB::statement("ALTER TABLE chapter_comments ADD CONSTRAINT manga_comment_status_ck CHECK (moderation_status IN ('pending','approved','hidden','rejected'))");
            DB::statement("ALTER TABLE chapter_comments ADD CONSTRAINT manga_comment_body_ck CHECK (char_length(btrim(body_plain)) BETWEEN 1 AND 4000 AND position('<' in body_plain) = 0 AND position('>' in body_plain) = 0)");
            DB::statement('ALTER TABLE chapter_comments ADD CONSTRAINT manga_comment_not_self_ck CHECK (parent_id IS NULL OR parent_id <> id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_comments');
        Schema::dropIfExists('series_reviews');
    }
};
