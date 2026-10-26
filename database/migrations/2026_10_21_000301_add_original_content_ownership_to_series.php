<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            // Existing works remain unverified by default; no automatic rights bypass.
            $table->boolean('is_original_content')->default(false);
            $table->timestampTz('original_content_declared_at')->nullable();
            $table->unsignedBigInteger('original_content_declared_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->dropColumn([
                'is_original_content',
                'original_content_declared_at',
                'original_content_declared_by',
            ]);
        });
    }
};
