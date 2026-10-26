# Gate 06 — Joint Regression and Owner Acceptance Register

Date: 2026-10-19. Status: **Pending**. No production access authorized.

## Evidence received in the conversation

- `GATE06_STATIC_AUDIT=PASS migrations=11 php_lint=80 corrected_hashes=2` — exact user-provided output.
- The owner reports that the IP guard patch and second database audit commands succeeded. The full `GATE06_DATABASE_READONLY_AUDIT=PASS` catalog results were **not pasted** yet, so this is *reported success*, not retained line-by-line evidence.
- 3 Gate 06 PHPUnit classes present (source export). Individual A/B/C test passes (10/11/15) were documented in previous handoff. A+B+C combined regression has **not yet** been evidenced.

## Gate 06 decisions for owner acceptance

| Item | Proposed decision | Current status |
|---|---|---|
| Rights | Default-deny until verified license covers original work, translations, territory, language, time and offline rights. A grant row is not legal proof. | DRAFT — owner/legal confirmation pending |
| `publication_jobs` | Do not introduce an untested queue/state-machine table at Gate 06; defer schema and transactional publication workflow to later gate. | DRAFT — owner decision pending |
| `permissions` / `role_permissions` | Define and test granular role capabilities at Gate 07 *before* any privileged mutations or public API. Existing roles tables are not authorization enforcement. | DRAFT — owner decision pending |
| Public launch | Do not launch before legal signoff and security audit; earlier npm audit snapshot had 29 known findings. | BLOCKED |

## Remaining hard gates

1. Retain full read-only DB audit output with `DATABASE_GUARD`, `DOMAIN_TABLES`, `MIGRATIONS`, `TRIGGERS`, `CRITICAL_FKS`, `CHECK_CONSTRAINTS` and `GATE06_DATABASE_READONLY_AUDIT` set to `PASS`.
2. Obtain owner decision on the three proposals above. Record the decision and date; no unapproved policies inferred.
3. Only if the local `manga_gate06_test` database is disposable or backed up: run all 3 test classes together with the packaged script's explicit interactive `-RunTests` option. These tests use `RefreshDatabase` and can reset schema/data in this *test* DB.
4. Capture PHPUnit summary and `GATE06_JOINT_REGRESSION=PASS`. Do not equate syntax check or database catalog check to joint runtime test passing.
5. Close Gate 06 only with all required evidence and explicit owner acceptance. Then plan Gate 07 separately.

**Owner acceptance of legal / publication / permission decisions:** PENDING

**Combined Gate 06 regression:** NOT RUN in this response

**Gate 06 overall:** NOT YET FORMALLY CLOSED
