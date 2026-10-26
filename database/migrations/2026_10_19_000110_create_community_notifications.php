<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_follows', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->timestampsTz();
            $table->primary(['user_id', 'series_id']);
            $table->index(['series_id', 'created_at'], 'manga_follows_series_idx');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('series_updates')->default(true);
            $table->boolean('moderation_updates')->default(true);
            $table->boolean('local_notifications_opt_in')->default(false);
            $table->timestampsTz();
        });

        # Notifications store event keys and IDs only, never spoiler text or chapter previews.
        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_key', 48);
            $table->string('dedupe_key', 160);
            $table->foreignUlid('series_id')->nullable()->constrained('series')->restrictOnDelete();
            $table->foreignUlid('edition_chapter_id')->nullable()->constrained('edition_chapters')->restrictOnDelete();
            $table->foreignUlid('content_report_id')->nullable()->constrained('content_reports')->restrictOnDelete();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'dedupe_key'], 'manga_notification_dedupe_idx');
            $table->index(['user_id', 'read_at', 'created_at'], 'manga_notification_inbox_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE in_app_notifications ADD CONSTRAINT manga_notification_event_ck CHECK (event_key IN ('series_updated','chapter_released','report_resolved','report_rejected'))");
            DB::statement("ALTER TABLE in_app_notifications ADD CONSTRAINT manga_notification_dedupe_ck CHECK (char_length(btrim(dedupe_key)) BETWEEN 1 AND 160)");
        }
    }

    public function down(): void
    {
        foreach (['in_app_notifications', 'notification_preferences', 'series_follows'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
