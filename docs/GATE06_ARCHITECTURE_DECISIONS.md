# Gate 06 — Publication and Authorization Architecture Decision Record

**Date:** 2026-10-19  
**Status:** Proposed — requires owner decision prior to Gate 06 sign-off. **No database changes in this audit package.**

## ADR-01: `publication_jobs` and publication statuses

**Observed:** Existing source has `publication_status` on `series`, `status` on `editions`, `edition_chapters`, `chapter_revisions`, and `media_assets`; an edition chapter contains a reviewed/published revision pointer guarded by PostgreSQL. Laravel's standard `jobs` table exists, but no `publication_jobs` domain table, retry/outbox contract, schedule or durable editorial state-machine service is implemented.

**Decision proposal:** Keep current row statuses for **Gate 06 domain acceptance**, and defer an explicit publication job/outbox schema until Gate 07+ designs workflow, concurrency, scheduling, retry, idempotency and events. Do *not* repurpose the framework `jobs` queue as an editorial audit trail. A future migration may add `publication_jobs` only when backed by a documented state machine and tests; it is not legitimate to silently invent one now.

**Acceptance concerns:** Require a single privileged publication service, reviewer distinct from submitter, rights re-validation at commit time, one transaction for final pointer/update, retry-safe publication idempotency, and explicit undo/withdrawal semantics. `manga_revision_immutable` currently blocks arbitrary status updates from `published` revisions: withdrawals should generally revoke **visibility/grants/pointers** rather than mutate an immutable revision, and this behavior must be tested in the future workflow.

## ADR-02: permissions / `role_permissions`

**Observed:** `roles`, `user_roles`, `team_members`, `content_assignments` exist, but `permissions`, `role_permissions`, policies and domain API enforcement **do not**. A `team_members.member_role` string is metadata, not a verified permission grant.

**Decision proposal:** A fine-grained permission vocabulary and mapping are needed for admin/editor/moderator/reviewer and legal-rights actions in **Gate 07**, before public or administrative mutation endpoints. Review exact capabilities, their assignment scope (global vs team/edition), separation of duties, role bootstrap, revocation and audit. Add tables by **new migration** only after capability matrix and approval, not by modifying the accepted migrations.

**Illustrative capabilities, NOT implemented:** `series.create`, `edition.submit`, `revision.review`, `chapter.publish`, `rights.approve`, `rights.revoke`, `report.moderate`, `user.role.assign`. Ensure rights approval/review isn't implied by holding a generic editorial role.

## ADR-03: schema and application policy boundary

**Observed:** Database checks/foreign keys/triggers enforce integrity but do not enforce request principal, storage access, rate limits, moderation visibility, notification consent, or legal evidence. Existing `/api/v1/health` is not the content API.

**Decision:** Do not publish Gate 07 endpoints until Policy/Gate tests cover per-user ownership, least privilege, server-side territorial licensing, privacy, spoiler safety and negative authorization scenarios. No production `migrate` or automatic `npm audit fix --force` in this package.
