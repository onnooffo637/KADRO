# Gate 06 — Final Audit and Evidence Register

**Date:** 2026-10-19  
**Status:** PARTIAL / PENDING OWNER ACCEPTANCE. Built from the source-only export, not the running Windows environment or live PostgreSQL. This is NOT `Gate 06 PASS`.

## Verified from the supplied source

| Check | Result | Basis |
| --- | --- | --- |
| ZIP integrity | PASS | `ZipFile.testzip()` on source export |
| Gate 06 migration files `000101`–`000111` | PASS | 11 present and inspected |
| Declared Gate 06 tables | PASS (36 definitions) | `Schema::create` including `genres`/`tags` foreach in `000101` |
| PostgreSQL trigger definitions | PASS (4 definitions) | 3 in `000104`, 1 in `000111` |
| `000104` known corrected hash | PASS | SHA256 `aba507b8c1db34694bed06fe646064aaf8d6157161aab5581b3d330707dbfd2f` |
| `CustomList.php` known corrected hash | PASS | SHA256 `3dd483ed32d0e03682c8bf75196af1804ac16bd93480d75ba563fb2bd90ba32d` |
| PHP syntax | PASS (80 files) | local PHP 8.4.24 `php -l` across `platform/app`, `database`, `tests` |
| Design token contrast scripts | PASS | `tests/contrast-audit.mjs`, `tests/gate05d-contrast.mjs` with local Node 22 |
| Existing Gate 06 tests | PRESENT, NOT RE-RUN | 3 PHPUnit classes; Phase A=10, Phase B=11, Phase C=15 methods as supplied |

## Incomplete verification (blocking final approval)

- **Actual PostgreSQL** on the owner's Windows PC: count the `manga_gate06_test` tables, applied migration rows, 4 non-internal triggers, critical composite FKs and CHECK constraints with the included **read-only** auditor; compare reported evidence. No database inspection occurred in this review environment.
- **Isolated joint regression A+B+C**: source-only export omits Composer `vendor/` and `.env`; no PostgreSQL connection available. `RefreshDatabase` is destructive to the designated test DB and must only run after explicit owner approval and verifying test data is disposable. Previous individual Phase reports are historical, not a substitute for combined execution.
- **Rights/legal sign-off**: `CONTENT_RIGHTS_POLICY.md` is a *proposal*; supporting evidence and retention/takedown decisions require actual owner/legal approval.
- **Publication and authorization decisions**: `GATE06_ARCHITECTURE_DECISIONS.md` documents proposed deferrals; approval required. Gate 07 remains closed until then.
- **Security release gate**: the previous Gate 04 `npm audit` snapshot reported 29 vulnerabilities (18 high, 11 moderate). Recheck current dependencies separately; don't assume unchanged and do not run `npm audit fix --force`.

## How to interpret the evidence

`36` and `4` established here refer to source-level declarations. An actual live database `PASS` requires the SQL catalog to match. PHP lint does not validate Laravel runtime behavior. Existing rights scopes are not proof of contracts. No migrations, rollbacks, factories/seeders, `npm install`, or schema-changing SQL are authorized or performed by this package.

## Remaining acceptance sequence

1. Run package's source-only checks (PowerShell wrapper without `-CheckDatabase`), save its output.
2. When `platform/vendor` and local `.env` exist and refer **only** to `manga_gate06_test`, run wrapper with `-CheckDatabase` for a read-only catalog audit, save output. Abort on any guard failure.
3. Review policy and ADR drafts with owner. Record ACCEPT / REVISE and dates in this register.
4. Only after separate owner approval, run combined test suite A+B+C on verified disposable test DB and attach exact PASS/FAIL output. Never run on `manga_app`.
5. Gate 06 closure requires all evidence, decisions and explicit owner sign-off. Do not begin Gate 07 on partial evidence.

**Owner decision:** PENDING  
**Gate 06 decision:** NOT YET ACCEPTED
