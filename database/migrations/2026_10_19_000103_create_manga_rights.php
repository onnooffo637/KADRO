<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rights_licenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('holder_name', 200);
            $table->string('document_ref', 512);
            $table->string('approval_status', 24)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->index(['approval_status', 'valid_until'], 'manga_license_state_idx');
        });

        Schema::create('rights_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('license_id')->constrained('rights_licenses')->restrictOnDelete();
            $table->foreignUlid('series_id')->constrained('series')->restrictOnDelete();
            $table->ulid('edition_id')->nullable();
            $table->foreign(['edition_id', 'series_id'], 'manga_grant_edition_fk')
                ->references(['id', 'series_id'])->on('editions')->restrictOnDelete();
            $table->boolean('can_publish')->default(false);
            $table->boolean('can_translate')->default(false);
            $table->boolean('can_offline')->default(false);
            $table->string('status', 24)->default('pending');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->timestampsTz();
            $table->index(['series_id', 'status', 'valid_until'], 'manga_rights_active_idx');
        });

        Schema::create('rights_territories', function (Blueprint $table): void {
            $table->foreignUlid('grant_id')->constrained('rights_grants')->restrictOnDelete();
            $table->string('territory_code', 12);
            $table->primary(['grant_id', 'territory_code']);
        });

        Schema::create('rights_languages', function (Blueprint $table): void {
            $table->foreignUlid('grant_id')->constrained('rights_grants')->restrictOnDelete();
            $table->string('language_code', 24);
            $table->primary(['grant_id', 'language_code']);
        });
    }

    public function down(): void
    {
        foreach (['rights_languages', 'rights_territories', 'rights_grants', 'rights_licenses'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
