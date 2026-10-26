<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->foreignUlid('cover_media_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->string('author_name', 200)->nullable();
            $table->string('illustrator_name', 200)->nullable();
            $table->string('age_rating', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cover_media_asset_id');
            $table->dropColumn(['author_name', 'illustrator_name', 'age_rating']);
        });
    }
};
