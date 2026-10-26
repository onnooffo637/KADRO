<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('storage_disk', 48)->default('private');
            $table->string('storage_key', 512)->unique();
            $table->string('mime', 120);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 24)->default('quarantined');
            $table->timestampsTz();
            $table->index(['status', 'created_at'], 'manga_asset_status_idx');
        });

        Schema::create('edition_chapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('series_id');
            $table->ulid('edition_id');
            $table->ulid('canonical_chapter_id');
            $table->ulid('current_published_revision_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->foreign(['edition_id', 'series_id'], 'manga_ec_edition_fk')
                ->references(['id', 'series_id'])->on('editions')->restrictOnDelete();
            $table->foreign(['canonical_chapter_id', 'series_id'], 'manga_ec_canonical_fk')
                ->references(['id', 'series_id'])->on('canonical_chapters')->restrictOnDelete();
            $table->unique(['edition_id', 'canonical_chapter_id'], 'manga_ec_unique');
            $table->unique(['id', 'edition_id'], 'manga_ec_edition_pair');
            $table->index(['edition_id', 'status'], 'manga_ec_status_idx');
        });

        Schema::create('chapter_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('edition_chapter_id')->constrained('edition_chapters')->restrictOnDelete();
            $table->unsignedInteger('revision_no');
            $table->foreignId('submitter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('draft');
            $table->char('asset_manifest_hash', 64)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['edition_chapter_id', 'revision_no'], 'manga_revision_number_unique');
            $table->unique(['id', 'edition_chapter_id'], 'manga_revision_chapter_pair');
            $table->index(['edition_chapter_id', 'status'], 'manga_revision_status_idx');
        });

        Schema::create('revision_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('chapter_revision_id')->constrained('chapter_revisions')->restrictOnDelete();
            $table->foreignUlid('media_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->unsignedInteger('page_index');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestampsTz();
            $table->unique(['chapter_revision_id', 'page_index'], 'manga_page_order_unique');
        });

        # PostgreSQL enforces the composite published pointer; SQLite legacy tests use a weaker schema.
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('edition_chapters', function (Blueprint $table): void {
                $table->foreign(['current_published_revision_id', 'id'], 'manga_ec_current_revision_fk')
                    ->references(['id', 'edition_chapter_id'])->on('chapter_revisions')->restrictOnDelete();
            });
        }

        Schema::create('content_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignUlid('edition_id')->constrained('editions')->restrictOnDelete();
            $table->string('capability', 48);
            $table->string('status', 24)->default('pending');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['team_id', 'edition_id', 'capability'], 'manga_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_assignments');
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('edition_chapters', function (Blueprint $table): void {
                $table->dropForeign('manga_ec_current_revision_fk');
            });
        }
        foreach (['revision_pages', 'chapter_revisions', 'edition_chapters', 'media_assets'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
