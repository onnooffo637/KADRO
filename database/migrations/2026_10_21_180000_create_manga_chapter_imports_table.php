<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->foreignUlid('edition_chapter_id')->constrained('edition_chapters')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('status', 24)->default('queued');
            $table->string('staging_prefix', 200);
            $table->jsonb('files');
            $table->boolean('archive_only')->default(false);
            $table->foreignUlid('revision_id')->nullable()->constrained('chapter_revisions')->nullOnDelete();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['series_id', 'edition_chapter_id', 'status'], 'manga_import_series_chapter_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_imports');
    }
};
