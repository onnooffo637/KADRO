<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('edition_id')->constrained('editions')->restrictOnDelete();
            $table->ulid('last_edition_chapter_id')->nullable();
            $table->unsignedInteger('last_page_index')->nullable();
            $table->ulid('furthest_edition_chapter_id')->nullable();
            $table->unsignedInteger('furthest_page_index')->nullable();
            $table->unsignedBigInteger('sync_revision')->default(0);
            $table->timestampTz('last_read_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'edition_id'], 'manga_progress_unique');
            $table->unique(['id', 'user_id', 'edition_id'], 'manga_progress_user_edition_fk_target');
            $table->foreign(['last_edition_chapter_id', 'edition_id'], 'manga_progress_last_chapter_fk')
                ->references(['id', 'edition_id'])->on('edition_chapters')->restrictOnDelete();
            $table->foreign(['furthest_edition_chapter_id', 'edition_id'], 'manga_progress_furthest_chapter_fk')
                ->references(['id', 'edition_id'])->on('edition_chapters')->restrictOnDelete();
            $table->index(['user_id', 'last_read_at'], 'manga_progress_recent_idx');
        });

        Schema::create('reading_sync_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('reading_progress_id');
            $table->foreignId('user_id');
            $table->ulid('edition_id');
            $table->ulid('edition_chapter_id');
            $table->uuid('client_operation_id');
            $table->unsignedInteger('page_index');
            $table->unsignedBigInteger('base_revision');
            $table->unsignedBigInteger('applied_revision');
            $table->boolean('updated_last_position');
            $table->boolean('advanced_furthest_position');
            $table->timestampsTz();
            $table->foreign(['reading_progress_id', 'user_id', 'edition_id'], 'manga_sync_progress_owner_fk')
                ->references(['id', 'user_id', 'edition_id'])->on('reading_progress')->restrictOnDelete();
            $table->foreign(['edition_chapter_id', 'edition_id'], 'manga_sync_edition_chapter_fk')
                ->references(['id', 'edition_id'])->on('edition_chapters')->restrictOnDelete();
            $table->unique(['user_id', 'client_operation_id'], 'manga_sync_operation_unique');
            $table->index(['reading_progress_id', 'applied_revision'], 'manga_sync_revision_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
ALTER TABLE reading_progress ADD CONSTRAINT manga_progress_positions_ck CHECK (
    ((last_edition_chapter_id IS NULL AND last_page_index IS NULL) OR (last_edition_chapter_id IS NOT NULL AND last_page_index IS NOT NULL AND last_page_index > 0))
    AND ((furthest_edition_chapter_id IS NULL AND furthest_page_index IS NULL) OR (furthest_edition_chapter_id IS NOT NULL AND furthest_page_index IS NOT NULL AND furthest_page_index > 0))
    AND sync_revision >= 0
)
SQL);
            DB::statement('ALTER TABLE reading_sync_operations ADD CONSTRAINT manga_sync_revisions_ck CHECK (page_index > 0 AND base_revision >= 0 AND applied_revision > base_revision)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_sync_operations');
        Schema::dropIfExists('reading_progress');
    }
};
