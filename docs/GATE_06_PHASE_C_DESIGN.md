# Gate 06 / Phase C — Community data contract

Status: **schema and model layer only**, pending isolated PostgreSQL acceptance. No public API, UI, authentication policy or notification transport is supplied by this package. Gate 07 implements authenticated authorization; Gate 16 implements community interfaces, XSS-safe rendering, moderation, rate limits and consent-aware local notifications.

## Persisted resources

- `series_reviews`: one review per user and series, integer rating 1–5, moderation `pending` by default; soft deletion does not permit bypassing the unique author–series index. `is_spoiler` is metadata only.
- `chapter_comments`: text comments and replies. Composite `(parent_id, edition_chapter_id)` foreign key prevents cross-chapter replies; `moderation_status=pending` by default. Application services must limit nesting/depth, require published-and-licensed content, escape text on render and enforce moderation. Plain-text field disallows raw `<` and `>` characters at DB level as defense in depth; it does **not** replace output escaping or XSS validation.
- `community_reactions`: one reaction per user and referenced review or comment; exactly one target FK must be non-null. Future reaction types need migration.
- `content_reports`, `moderation_actions`: reporter-scoped reports and action history. Exactly one reported target, de-duplication per reporter and target, restricted action kinds. **Do not write actions from clients**: future privileged moderation service will enforce role, state transitions, audit, rate limit and ownership. Database FK alone is not authorization.
- `series_follows`, `notification_preferences`: follow relationships and permission metadata. `local_notifications_opt_in` defaults to false; OS permission must still be independently verified before any local schedule.
- `in_app_notifications`: typed event keys and object references **only**, no arbitrary text payload or chapter preview, unique recipient/dedupe key. Visibility, rights checking and recipient validation belong to authenticated services. An event referring to an unpublished chapter must never become a visible notice.
- `audit_logs`: append-only on PostgreSQL via trigger; no free-form personal data snapshots. SQL privileges / service authorization are required before production. `DROP`, superuser and schema-owner rights can bypass triggers, so this is not tamper-proof audit storage.

## Security and API boundaries

- All eight new models are guarded from mass-assignment, and no HTTP routes are created.
- `pending` reviews/comments are never public by default. Public queries must explicitly require moderation approval **and** published/rights-verified content.
- Do not show spoiler text in notification cards, snippets, emails or logs. Gate 16 must blur approved spoiler content by default in reader UI.
- No direct HTML/Markdown rendering from stored `body_plain`; escape on every client. Database angle-bracket check is an additional guard, not complete sanitization.
- No real manga media is seeded and no user-generated content is synthesized from copyrighted materials.

## Migration plan

- `000108`: reviews and comments
- `000109`: reactions, reports and moderation history
- `000110`: follows, preferences and notification center metadata
- `000111`: append-only audit logs

All IDs are ULIDs except existing `users.id` foreign keys. Schema references existing `series`, `edition_chapters`, `users`, and Phase A/B tables. All new Migrations are additive. Do not run migrations on `manga_app` until Gate 06 passes and the owner authorizes deployment. Isolated database: `manga_gate06_test` only. Tests use Laravel `RefreshDatabase`, so running tests resets data in the test database. Do not run tests if it contains data that must be retained.

## Remaining Gate 06 work

- Publishing job/status metadata, complete roles/permissions review and expansion, migration/rollback/seed regression, domain ownership and retention documentation, and final catalog right-policy audit.
- Gate 07 policies and authentication before external API routes; Gate 16 actual community interactions and notifications.
