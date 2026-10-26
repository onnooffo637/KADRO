<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->string('shelf', 24)->default('planned');
            $table->boolean('is_favorite')->default(false);
            $table->timestampTz('last_opened_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'series_id'], 'manga_library_unique');
            $table->index(['user_id', 'shelf', 'updated_at'], 'manga_library_user_shelf_idx');
        });

        Schema::create('custom_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();
            $table->unique(['user_id', 'normalized_name'], 'manga_custom_list_name_unique');
        });

        Schema::create('custom_list_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_list_id')->constrained('custom_lists')->restrictOnDelete();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['custom_list_id', 'series_id'], 'manga_custom_list_item_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE library_entries ADD CONSTRAINT manga_library_shelf_ck CHECK (shelf IN ('planned','reading','completed','on_hold','dropped'))");
            # List sharing is out of scope until authenticated authorization is implemented in Gate 07.
            DB::statement('ALTER TABLE custom_lists ADD CONSTRAINT manga_custom_list_private_ck CHECK (is_public = false)');
            DB::statement("ALTER TABLE custom_lists ADD CONSTRAINT manga_custom_list_normalized_ck CHECK (length(trim(normalized_name)) > 0)");
        }
    }

    public function down(): void
    {
        foreach (['custom_list_items', 'custom_lists', 'library_entries'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
