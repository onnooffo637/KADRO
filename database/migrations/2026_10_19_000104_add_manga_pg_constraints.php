<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        # Other drivers remain usable for legacy unit tests; Gate 06 acceptance requires PostgreSQL.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            ['series', 'manga_series_format_ck', "format IN ('manga','manhwa','manhua','webtoon')"],
            ['series', 'manga_series_state_ck', "publication_status IN ('draft','reviewed','published','archived','revoked')"],
            ['teams', 'manga_team_state_ck', "status IN ('pending','approved','suspended','archived')"],
            ['canonical_chapters', 'manga_canonical_kind_ck', "kind IN ('regular','extra','prologue','epilogue','special')"],
            ['editions', 'manga_edition_state_ck', "status IN ('draft','submitted','reviewed','published','archived','revoked')"],
            ['editions', 'manga_edition_direction_ck', "reading_direction_hint IN ('auto','ltr','rtl','vertical')"],
            ['edition_chapters', 'manga_ec_state_ck', "status IN ('draft','submitted','reviewed','published','archived','revoked')"],
            ['edition_chapters', 'manga_ec_published_pointer_ck', "status <> 'published' OR current_published_revision_id IS NOT NULL"],
            ['chapter_revisions', 'manga_revision_state_ck', "status IN ('draft','submitted','reviewed','published','rejected','archived','revoked')"],
            ['chapter_revisions', 'manga_revision_no_ck', 'revision_no > 0'],
            ['chapter_revisions', 'manga_revision_reviewer_ck', 'reviewer_id IS NULL OR submitter_id IS NULL OR reviewer_id <> submitter_id'],
            ['revision_pages', 'manga_page_index_ck', 'page_index > 0 AND width > 0 AND height > 0'],
            ['media_assets', 'manga_asset_bytes_ck', 'bytes > 0'],
            ['media_assets', 'manga_asset_storage_ck', "left(storage_key, 1) NOT IN ('/', chr(92)) AND position('..' in storage_key) = 0 AND position(':' in storage_key) = 0"],
            ['rights_licenses', 'manga_license_term_ck', 'valid_until > valid_from'],
            ['rights_grants', 'manga_grant_term_ck', 'valid_until > valid_from'],
            ['rights_licenses', 'manga_license_status_ck', "approval_status IN ('pending','approved','rejected','revoked')"],
            ['rights_grants', 'manga_grant_status_ck', "status IN ('pending','active','suspended','revoked')"],
            ['rights_licenses', 'manga_license_approver_ck', "approval_status <> 'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)"],
        ];

        foreach ($checks as [$table, $name, $condition]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$condition})");
        }

        # Once reviewed, pages may not be changed. Revisions must be replaced, never rewritten.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION manga_guard_revision_pages() RETURNS trigger AS $$
DECLARE revision_state text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT status INTO revision_state FROM chapter_revisions
          WHERE id = OLD.chapter_revision_id;
        IF revision_state IN ('reviewed', 'published', 'archived', 'revoked') THEN
            RAISE EXCEPTION 'Reviewed chapter pages are immutable';
        END IF;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        SELECT status INTO revision_state FROM chapter_revisions
          WHERE id = NEW.chapter_revision_id;
        IF revision_state IN ('reviewed', 'published', 'archived', 'revoked') THEN
            RAISE EXCEPTION 'Reviewed chapter pages are immutable';
        END IF;
        RETURN NEW;
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER manga_revision_page_immutable
BEFORE INSERT OR UPDATE OR DELETE ON revision_pages
FOR EACH ROW EXECUTE FUNCTION manga_guard_revision_pages();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION manga_guard_published_revision() RETURNS trigger AS $$
BEGIN
    IF OLD.status IN ('reviewed', 'published', 'archived', 'revoked') THEN
        IF OLD.status = 'reviewed' AND NEW.status = 'published'
           AND NEW.edition_chapter_id IS NOT DISTINCT FROM OLD.edition_chapter_id
           AND NEW.revision_no IS NOT DISTINCT FROM OLD.revision_no
           AND NEW.submitter_id IS NOT DISTINCT FROM OLD.submitter_id
           AND NEW.reviewer_id IS NOT DISTINCT FROM OLD.reviewer_id
           AND NEW.asset_manifest_hash IS NOT DISTINCT FROM OLD.asset_manifest_hash
           AND NEW.submitted_at IS NOT DISTINCT FROM OLD.submitted_at
           AND NEW.reviewed_at IS NOT DISTINCT FROM OLD.reviewed_at THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Reviewed revisions are immutable; publish through a new revision';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER manga_revision_immutable
BEFORE UPDATE ON chapter_revisions
FOR EACH ROW EXECUTE FUNCTION manga_guard_published_revision();
SQL);

        # Ensure the active pointer always addresses a published revision containing ordered pages.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION manga_check_published_pointer() RETURNS trigger AS $$
DECLARE revision_state text; pages_count integer; distinct_pages integer; max_index integer; invalid_assets integer; manifest_hash text;
BEGIN
    IF NEW.status = 'published' THEN
        SELECT status, asset_manifest_hash INTO revision_state, manifest_hash FROM chapter_revisions
          WHERE id = NEW.current_published_revision_id AND edition_chapter_id = NEW.id;
        IF revision_state IS DISTINCT FROM 'published' THEN
            RAISE EXCEPTION 'Published chapter requires a published revision in the same chapter';
        END IF;
        SELECT count(*), count(DISTINCT page_index), max(page_index)
          INTO pages_count, distinct_pages, max_index
          FROM revision_pages WHERE chapter_revision_id = NEW.current_published_revision_id;
        IF pages_count = 0 OR pages_count <> distinct_pages OR pages_count <> max_index THEN
            RAISE EXCEPTION 'Published revision requires pages indexed 1..N';
        END IF;
        IF manifest_hash IS NULL THEN
            RAISE EXCEPTION 'Published revision requires an approved asset manifest';
        END IF;
        SELECT count(*) INTO invalid_assets FROM revision_pages page
          INNER JOIN media_assets asset ON asset.id = page.media_asset_id
          WHERE page.chapter_revision_id = NEW.current_published_revision_id
            AND (asset.status <> 'ready' OR asset.width IS NULL OR asset.height IS NULL);
        IF invalid_assets > 0 THEN
            RAISE EXCEPTION 'Published revision cannot reference quarantined or incomplete assets';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER manga_published_pointer_guard
BEFORE INSERT OR UPDATE ON edition_chapters
FOR EACH ROW EXECUTE FUNCTION manga_check_published_pointer();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['manga_published_pointer_guard ON edition_chapters', 'manga_revision_immutable ON chapter_revisions', 'manga_revision_page_immutable ON revision_pages'] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
        foreach (['manga_check_published_pointer', 'manga_guard_published_revision', 'manga_guard_revision_pages'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }

        $checks = [
            'series' => ['manga_series_format_ck', 'manga_series_state_ck'],
            'teams' => ['manga_team_state_ck'],
            'canonical_chapters' => ['manga_canonical_kind_ck'],
            'editions' => ['manga_edition_state_ck', 'manga_edition_direction_ck'],
            'edition_chapters' => ['manga_ec_state_ck', 'manga_ec_published_pointer_ck'],
            'chapter_revisions' => ['manga_revision_state_ck', 'manga_revision_no_ck', 'manga_revision_reviewer_ck'],
            'revision_pages' => ['manga_page_index_ck'],
            'media_assets' => ['manga_asset_bytes_ck', 'manga_asset_storage_ck'],
            'rights_licenses' => ['manga_license_term_ck', 'manga_license_status_ck', 'manga_license_approver_ck'],
            'rights_grants' => ['manga_grant_term_ck', 'manga_grant_status_ck'],
        ];
        foreach ($checks as $table => $names) {
            foreach ($names as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }
    }
};
