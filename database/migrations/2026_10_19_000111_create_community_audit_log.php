<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 64);
            $table->string('subject_id', 64);
            $table->string('action', 64);
            $table->uuid('request_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampsTz();
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'manga_audit_subject_idx');
            $table->index(['actor_id', 'occurred_at'], 'manga_audit_actor_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT manga_audit_action_ck CHECK (char_length(btrim(action)) BETWEEN 1 AND 64)");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION manga_audit_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Audit log is append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
            DB::statement('CREATE TRIGGER manga_audit_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION manga_audit_append_only()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS manga_audit_append_only()');
        }
    }
};
