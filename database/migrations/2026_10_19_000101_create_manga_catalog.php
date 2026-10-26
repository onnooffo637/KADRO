<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('display_name', 100);
            $table->timestampsTz();
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('status', 24)->default('pending');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('team_members', function (Blueprint $table): void {
            $table->foreignUlid('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('member_role', 40);
            $table->string('status', 24)->default('pending');
            $table->timestampsTz();
            $table->primary(['team_id', 'user_id']);
        });

        Schema::create('series', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 180)->unique();
            $table->string('primary_title', 300);
            $table->string('format', 16);
            $table->string('original_language', 24);
            $table->string('publication_status', 24)->default('draft');
            $table->string('completion_status', 24)->default('ongoing');
            $table->text('synopsis')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['publication_status', 'published_at', 'id'], 'manga_series_public_idx');
        });

        Schema::create('title_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->string('locale', 24);
            $table->string('value', 300);
            $table->string('normalized_value', 300);
            $table->timestampsTz();
            $table->unique(['series_id', 'locale', 'normalized_value'], 'manga_alias_unique');
            $table->index(['locale', 'normalized_value'], 'manga_alias_search_idx');
        });

        foreach (['genres', 'tags'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 80)->unique();
                $table->string('name', 140);
                $table->timestampsTz();
            });
        }

        Schema::create('series_genres', function (Blueprint $table): void {
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->foreignUlid('genre_id')->constrained('genres')->restrictOnDelete();
            $table->primary(['series_id', 'genre_id']);
        });

        Schema::create('series_tags', function (Blueprint $table): void {
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->foreignUlid('tag_id')->constrained('tags')->restrictOnDelete();
            $table->primary(['series_id', 'tag_id']);
        });

        Schema::create('canonical_chapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->string('display_number', 32);
            $table->decimal('sort_key', 20, 8);
            $table->string('title', 300)->nullable();
            $table->string('kind', 24)->default('regular');
            $table->string('status', 24)->default('draft');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['series_id', 'sort_key'], 'manga_chapter_order_unique');
            $table->unique(['id', 'series_id'], 'manga_chapter_series_pair');
            $table->index(['series_id', 'status', 'sort_key'], 'manga_chapter_catalog_idx');
        });

        Schema::create('editions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->string('language_code', 24);
            $table->string('label', 180);
            $table->foreignUlid('translation_team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('reading_direction_hint', 16)->default('auto');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['id', 'series_id'], 'manga_edition_series_pair');
            $table->index(['series_id', 'language_code', 'status'], 'manga_edition_browse_idx');
        });
    }

    public function down(): void
    {
        foreach (['editions', 'canonical_chapters', 'series_tags', 'series_genres', 'tags', 'genres', 'title_aliases', 'series', 'team_members', 'teams', 'user_roles', 'roles'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
