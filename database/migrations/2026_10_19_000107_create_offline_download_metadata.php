<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        # Metadata never confers access to private page bytes. A Gate 07/10/14 service must re-check live rights.
        Schema::create('offline_downloads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('edition_chapter_id')->constrained('edition_chapters')->restrictOnDelete();
            $table->ulid('chapter_revision_id');
            $table->char('device_key_hash', 64);
            $table->string('state', 24)->default('queued');
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->foreignUlid('rights_grant_id')->nullable()->constrained('rights_grants')->restrictOnDelete();
            $table->timestampTz('rights_checked_at')->nullable();
            $table->timestampTz('access_expires_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['chapter_revision_id', 'edition_chapter_id'], 'manga_offline_revision_chapter_fk')
                ->references(['id', 'edition_chapter_id'])->on('chapter_revisions')->restrictOnDelete();
            $table->unique(['user_id', 'device_key_hash', 'edition_chapter_id'], 'manga_offline_device_chapter_unique');
            $table->index(['user_id', 'state', 'access_expires_at'], 'manga_offline_user_state_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE offline_downloads ADD CONSTRAINT manga_offline_state_ck CHECK (state IN ('queued','downloading','available','removed','expired','failed'))");
            DB::statement("ALTER TABLE offline_downloads ADD CONSTRAINT manga_offline_hash_ck CHECK (device_key_hash ~ '^[0-9a-f]{64}$')");
            DB::statement('ALTER TABLE offline_downloads ADD CONSTRAINT manga_offline_bytes_ck CHECK (bytes_downloaded >= 0)');
            DB::statement("ALTER TABLE offline_downloads ADD CONSTRAINT manga_offline_available_ck CHECK (state <> 'available' OR (rights_grant_id IS NOT NULL AND rights_checked_at IS NOT NULL AND access_expires_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_downloads');
    }
};
