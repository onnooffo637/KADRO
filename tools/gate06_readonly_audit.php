<?php

# Gate 06 PostgreSQL catalog inspection only. No migrations, seeds or application writes.
# This script must be run from an installed Laravel checkout that has its own local .env.

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const TEST_DB_NAME = 'manga_gate06_test';

$project = dirname(__DIR__);
$autoload = $project.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "FAIL: platform/vendor/autoload.php is missing; database check not run.\n");
    exit(2);
}

require $autoload;

$app = require $project.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dbConfig = config('database.connections.pgsql');
if (config('database.default') !== 'pgsql'
    || ! is_array($dbConfig)
    || ($dbConfig['database'] ?? null) !== TEST_DB_NAME
    || ($dbConfig['host'] ?? null) !== '127.0.0.1'
    || (string) ($dbConfig['port'] ?? '') !== '5432'
    || ! empty($dbConfig['url'] ?? null)
) {
    fwrite(STDERR, "FAIL: database guard refused connection. Require pgsql, 127.0.0.1:5432, database manga_gate06_test and empty DB_URL.\n");
    exit(2);
}

$tables = [
    'roles', 'user_roles', 'teams', 'team_members', 'series', 'title_aliases', 'genres', 'tags',
    'series_genres', 'series_tags', 'canonical_chapters', 'editions',
    'media_assets', 'edition_chapters', 'chapter_revisions', 'revision_pages', 'content_assignments',
    'rights_licenses', 'rights_grants', 'rights_territories', 'rights_languages',
    'library_entries', 'custom_lists', 'custom_list_items',
    'reading_progress', 'reading_sync_operations', 'offline_downloads',
    'series_reviews', 'chapter_comments',
    'community_reactions', 'content_reports', 'moderation_actions',
    'series_follows', 'notification_preferences', 'in_app_notifications', 'audit_logs',
];
$migrations = [
    '2026_10_19_000101_create_manga_catalog',
    '2026_10_19_000102_create_manga_revisions_and_media',
    '2026_10_19_000103_create_manga_rights',
    '2026_10_19_000104_add_manga_pg_constraints',
    '2026_10_19_000105_create_reader_library',
    '2026_10_19_000106_create_reading_sync',
    '2026_10_19_000107_create_offline_download_metadata',
    '2026_10_19_000108_create_community_reviews_comments',
    '2026_10_19_000109_create_community_reactions_moderation',
    '2026_10_19_000110_create_community_notifications',
    '2026_10_19_000111_create_community_audit_log',
];
$triggers = [
    'revision_pages:manga_revision_page_immutable',
    'chapter_revisions:manga_revision_immutable',
    'edition_chapters:manga_published_pointer_guard',
    'audit_logs:manga_audit_immutable',
];
$criticalFks = [
    'manga_ec_edition_fk', 'manga_ec_canonical_fk', 'manga_ec_current_revision_fk',
    'manga_grant_edition_fk', 'manga_progress_last_chapter_fk',
    'manga_progress_furthest_chapter_fk', 'manga_sync_progress_owner_fk',
    'manga_sync_edition_chapter_fk', 'manga_offline_revision_chapter_fk',
    'manga_comment_same_chapter_fk',
];


$expectedChecks = [
    'manga_asset_bytes_ck', 'manga_asset_storage_ck', 'manga_audit_action_ck', 'manga_canonical_kind_ck',
    'manga_comment_body_ck', 'manga_comment_not_self_ck', 'manga_comment_status_ck', 'manga_custom_list_normalized_ck',
    'manga_custom_list_private_ck', 'manga_ec_published_pointer_ck', 'manga_ec_state_ck', 'manga_edition_direction_ck',
    'manga_edition_state_ck', 'manga_grant_status_ck', 'manga_grant_term_ck', 'manga_library_shelf_ck',
    'manga_license_approver_ck', 'manga_license_status_ck', 'manga_license_term_ck', 'manga_moderation_action_ck',
    'manga_moderation_reason_ck', 'manga_notification_dedupe_ck', 'manga_notification_event_ck', 'manga_offline_available_ck',
    'manga_offline_bytes_ck', 'manga_offline_hash_ck', 'manga_offline_state_ck', 'manga_page_index_ck',
    'manga_progress_positions_ck', 'manga_reaction_kind_ck', 'manga_reaction_single_target_ck', 'manga_report_details_ck',
    'manga_report_reason_ck', 'manga_report_single_target_ck', 'manga_report_state_ck', 'manga_review_body_ck',
    'manga_review_rating_ck', 'manga_review_status_ck', 'manga_revision_no_ck', 'manga_revision_reviewer_ck',
    'manga_revision_state_ck', 'manga_series_format_ck', 'manga_series_state_ck', 'manga_sync_revisions_ck',
    'manga_team_state_ck',
];

function auditSet(string $name, array $expected, array $actual): bool
{
    $missing = array_values(array_diff($expected, $actual));
    sort($missing);
    printf("%s=%s expected=%d found=%d\n", $name, $missing === [] ? 'PASS' : 'FAIL', count($expected), count($expected) - count($missing));
    if ($missing !== []) {
        echo 'MISSING_'.strtoupper($name).'='.implode(',', $missing)."\n";
    }
    return $missing === [];
}

$pdo = null;
$begun = false;
$passed = true;

try {
    $pdo = DB::connection('pgsql')->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $begun = true;
    $pdo->exec('SET TRANSACTION READ ONLY');

    $identity = $pdo->query("SELECT current_database() AS db_name, host(inet_server_addr()) AS server_ip, inet_server_port() AS server_port, current_setting('transaction_read_only') AS read_only")->fetch(PDO::FETCH_ASSOC);
    if (! is_array($identity) || $identity['db_name'] !== TEST_DB_NAME
        || $identity['server_ip'] !== '127.0.0.1'
        || (int) $identity['server_port'] !== 5432
        || $identity['read_only'] !== 'on') {
        throw new RuntimeException('Server or READ ONLY transaction guard mismatch.');
    }
    echo "DATABASE_GUARD=PASS (localhost/isolated test database/READ ONLY)\n";

    $actualTables = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_COLUMN);
    $passed = auditSet('DOMAIN_TABLES', $tables, $actualTables) && $passed;

    $actualMigrations = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $passed = auditSet('MIGRATIONS', $migrations, $actualMigrations) && $passed;

    $triggerQuery = <<<'SQL'
SELECT c.relname || ':' || t.tgname AS key
FROM pg_catalog.pg_trigger t
JOIN pg_catalog.pg_class c ON c.oid = t.tgrelid
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND NOT t.tgisinternal
SQL;
    $actualTriggers = $pdo->query($triggerQuery)->fetchAll(PDO::FETCH_COLUMN);
    $passed = auditSet('TRIGGERS', $triggers, $actualTriggers) && $passed;

    $fkQuery = <<<'SQL'
SELECT con.conname
FROM pg_catalog.pg_constraint con
JOIN pg_catalog.pg_namespace n ON n.oid = con.connamespace
WHERE n.nspname = 'public' AND con.contype = 'f'
SQL;
    $actualFks = $pdo->query($fkQuery)->fetchAll(PDO::FETCH_COLUMN);
    $passed = auditSet('CRITICAL_FKS', $criticalFks, $actualFks) && $passed;

    $checkQuery = <<<'SQL'
SELECT con.conname
FROM pg_catalog.pg_constraint con
JOIN pg_catalog.pg_namespace n ON n.oid = con.connamespace
WHERE n.nspname = 'public' AND con.contype = 'c'
SQL;
    $actualChecks = $pdo->query($checkQuery)->fetchAll(PDO::FETCH_COLUMN);
    $passed = auditSet('CHECK_CONSTRAINTS', $expectedChecks, $actualChecks) && $passed;

    echo 'GATE06_DATABASE_READONLY_AUDIT='.($passed ? 'PASS' : 'FAIL')."\n";
} catch (Throwable $e) {
    # Avoid emitting SQL text or connection strings from exception messages.
    fwrite(STDERR, 'GATE06_DATABASE_READONLY_AUDIT=FAIL ('.get_class($e).", check local database configuration and schema)\n");
    $passed = false;
} finally {
    if ($begun && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

exit($passed ? 0 : 1);
