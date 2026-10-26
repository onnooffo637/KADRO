<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_revisions', function (Blueprint $table): void {
            // Optional for backward compatibility with Gate 10 ZIP clients.
            // Unique prevents double creation after retries, even across requests/workers.
            $table->uuid('import_request_key')->nullable()->unique('manga_revision_import_request_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_revisions', function (Blueprint $table): void {
            $table->dropUnique('manga_revision_import_request_key_unique');
            $table->dropColumn('import_request_key');
        });
    }
};
