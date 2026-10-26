# Content & Translation Rights Policy — Draft for Owner Approval

**Status:** Documentary proposal for Gate 06; not legal advice and not an approved license register. No scraping, importing or public distribution of third-party chapters until explicit permissions are reviewed.

## Evidence required before publication

- Record the **actual rightsholder and contracting entity**, who signed, approval date, effective/expiry dates, secure evidence reference, and evidence reviewer (a user with verified authority).
- Record **scope** separately for publication, translation/adaptation and offline copying/distribution. Translation may create additional rights issues; holding the translation alone does not automatically confer rights to the original artwork.
- Record covered **work/series**, covered **edition(s)**, **countries/regions**, **languages**, media/app distribution channel, exclusive/nonexclusive constraints, and any device/copy limits. An intentionally global license needs explicit evidence and `GLOBAL` territory scope; absence of territory is a denial.
- Save actual agreements and supporting evidence in access-controlled private storage; `rights_licenses.document_ref` is only a *pointer*, never evidence itself. Do not place contract scans, personal contacts or secrets in the source repository.
- Keep verification/approval by a person distinct from uploader/submission when practical; log grants, decisions and revocations without copying contract or private message contents into logs.

## Default-deny access decision

1. Authenticated or guest request identifies intended **territory** and **language** from a trusted policy (not a client-controlled allow-all fallback).
2. Check series + edition + chapter are currently published and not revoked/deleted; verify revision and its actual media availability.
3. Check approved `rights_licenses`, current UTC window, active `rights_grants` with `can_publish` and matching scope, explicit language and territory (`GLOBAL` only if documented).
4. For translations, require `can_translate` and the necessary underlying rights before allowing new translation content.
5. For offline: re-evaluate `can_offline`, territory/language, expiry, device entitlement, and revision at time of issuing or renewing authorization; store encrypted files with expirations, enforce revocation and do not treat `offline_downloads` rows as entitlements.
6. If unknown, expired, conflicting, missing evidence or revoked: deny and hide the media; record a minimal audit event. Never show a leaked image URL merely because a catalogue row exists.

## Takedown and expiration

- Provide a contact and internal process for copyright complaints; acknowledge and triage quickly according to applicable law and contract (SLA to be agreed by the owner before launch).
- On substantiated complaint or license expiry: suspend grant, hide public edition/chapter assets, stop new downloads, invalidate signed URLs, revoke offline authorizations where technically possible, and queue client refresh/cleanup. Preserve necessary legal evidence privately with limited access.
- Keep an auditable action record containing actor/action/time/subject, with reasons and evidence references outside publicly visible content. Define appeal/counter-notice procedure with counsel as relevant to launch region.
- Support reapproval only after new verified permission; do not silently un-revoke or regenerate public links.

## Operational gaps (NOT yet implemented)

- No rights-approval interface or grant verification workflow; no authenticated API access policies (planned Gate 07+).
- No secure media-delivery authorization, signed media URLs or revocation protocol; no offline enforcement (later Gates).
- No scheduled license-expiration/takedown worker, legal-retention policy, abuse intake or consent management in this schema.
- Rights metadata is **necessary but not sufficient** legal evidence. Owner must approve policy, evidence retention window, jurisdiction, user privacy disclosures and complaint contact before any public launch.
