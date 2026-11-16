# M1 reports, evidence, acceptance and closure — 15 November 2026

Branch: `feat/m1-service-reports`. Starting HEAD: `3a5087745643238ee0fd32a65614dc1a9ccc10c9`.
The two modified models and four untracked report files present at handoff are preserved and included in this work. Nothing is pushed, merged or deployed.

## Baseline and checklist

Internal development baseline: Blueprint 0A, ratified ADR 0C, Phase 0D including A1–A6, and the visit execution checkpoint. This is not company SOP ratification. No invented legacy provenance, approval, acceptance or usage. Inventory, finance M2, new roles and exception closure remain outside scope.

- [x] Draft report service/schema: audited create/update; author/member/account/parent/head/state guards; partial updates; server controls; version/idempotency; history rollback; independent MySQL process races. HTTP/UI follows in the report workflow slice.
- [x] Evidence/material: structured checklist and asset results; private validated uploads and scoped downloads; submitted evidence immutable across writers; append-only decimal material ledger and audited adjustments; reproducible submission snapshots.
- [x] Submission/review/revisions: scoped history and UI; frozen submission; one author; independent reviewer; document fix versus technical rework; all required scope heads determine WO status; transactions, versions, history and retries.
- [x] BAST: document/version/item chain; compatible multi-WO packages; all-parent authorization; approved head references; immutable issued content and accepted decisions; external evidence, authority, signer/recorder times; claim and stale-decision races; rejection follow-up.
- [x] Lifecycle/closure: review/rework/acceptance/closed; hold/resume/cancel within baseline authority; active booking impact; nonempty full required scope acceptance with evidence IDs in closure history; exception denied; multi-WO ticket resolution guarded; payment independent.
- [x] Final automated verification (browser pending): full backend/frontend, typecheck, build, Pint, diff review; browser/mobile QA or explicit pending checklist.

## Environment and evidence limits

Use `docker compose -p nusalogi-m1-test -f compose.yaml exec -T -u sail laravel.test` for commands. Database tests require the repository's MySQL `testing` / empty database URL guard; database suites run sequentially. Application QA accounts/data are preserved. Seven additive migrations were applied later in this session to the verified local application database; see final verification below. Earlier slice notes preserve the status at their commit.

Boost is available through the installed `boost:execute-tool` command; search-docs was queried for Laravel 13 transactions/locking/validation/testing. Installed framework is 13.36.0, PHPUnit 12.5.39, PHP runtime image 8.5. `.ai/rules` is absent. Connected real-browser tooling has not been found; browser QA for new work is pending. Staging F01/F03 and company authority/retention decisions in Phase 0D §9.2 remain external rollout gates, not evidence of local implementation completion.

## Current checkpoint

Slice 1 verified: `php artisan test --compact tests/Feature/ServiceReportDraftTest.php tests/Feature/ServiceReportSchemaTest.php tests/Feature/ServiceReportDraftConcurrencyTest.php` through Compose: **57 passed, 382 assertions**, 100.51 seconds. This includes four process races (competing create/update and identical create/update retries), each with independent connection IDs, REPEATABLE-READ, a pre-existing snapshot and a NOWAIT contention probe in both live transactions before parent release. One domain/history mutation commits; a stale/new-key loser conflicts; identical retries return the same report.

The first expanded run had one fixture-only failure: replicating a scope omitted its required `created_at`; corrected and the complete selected suite passed. Pint and `git diff --check` passed. Container Pint `--dirty` cannot resolve the host worktree Git metadata; host PHP 8.5.4 ran `vendor/bin/pint --dirty --format agent` successfully, followed by explicit-path formatting. Frontend was unchanged in slice 1. Application report migration is still unapplied; no QA data was modified. New browser QA remains pending.

## Slice 2 — evidence and material backend

- Draft create/update now validates checklist items and asset results against WO/site assets; partial updates preserve omitted structured fields.
- Private evidence has staged registration, exclusive object creation, hash/size verification and an audited ready promotion. Domain/file transactions are separate; retries retain immutable keys. Failed attachment retains a ready orphan. Cleanup and ever-ready deletion remain disabled.
- Authenticated upload and scoped download routes check current account, WO membership and all nested parents; author-only upload/attach; safe download disposition and no public storage URL.
- Material consume/return/reversal is an append-only decimal ledger. Adjustments require a consume parent on the same scope, matching units and attributes, positive quantity, reason, and a current locking read of all prior adjustments. Report versions, domain writes and command history commit together. Submitted reports require a new draft revision before more movements; no inventory or finance effects.
- Additive migration creates evidence/material tables and nullable photo evidence references. MySQL triggers freeze submitted narrative/structured evidence/photos, finalized review metadata, file identity metadata and material movements, including bulk update/delete paths. Legacy report/photo rows retain nullable references and are not assigned invented metadata. M1 draft history is also protected against deletion.
- Local Compose now enables `log_bin_trust_function_creators` for trigger migrations. The existing local test MySQL was adjusted without a restart or data reset. Other environments require approved migration privileges/trigger support; this is not a staging or production change. The initial run failed with MySQL 1419 before tests; after enabling local support, a JSON key-order comparison was corrected. The old FK deletion assertion was updated to reflect the stronger immutable-history trigger.

Verified through Compose: `php artisan test --compact --stop-on-error --stop-on-failure tests/Feature/ReportEvidenceTest.php tests/Feature/ReportEvidenceHttpTest.php tests/Feature/MaterialUsageTest.php tests/Feature/ServiceReportDraftTest.php tests/Feature/ServiceReportSchemaTest.php tests/Feature/MaterialUsageConcurrencyTest.php`: **74 passed / 401 assertions**, 43.08 seconds. The material race proves two overlapping independent REPEATABLE-READ transactions cannot return 0.750 + 0.750 against 1.000 consumption; the loser sees the committed adjustment and receives MATERIAL_RETURN_EXCEEDS_USAGE. Pint and diff check pass. Application migrations remain unapplied. Evidence/material UI and frozen submission snapshots follow in slice 3; this backend checkpoint does not mark the whole evidence/material section complete.

## Slice 3 — report submission, QA revisions and UI

Implemented scoped report list/detail, legacy read-only presentation, author draft editing, checklist and per-asset results, private upload/download, material history and adjustment forms, frozen submission snapshots, independent Koordinator review, document-fix successors and technical-rework state. Required active scope heads determine pending_review and awaiting_acceptance; one scope approval never completes another. Submission requires saved narrative/checklist and completed running sessions. Technical rework must start execution before resubmission. Old report content/QA decisions and photos remain immutable. Added nullable started_at/field_finished_at/closed_at without inferring legacy timestamps or reinterpreting completed_at.

Forms retain command keys/observed versions across retry, prevent repeated submissions, show loading/validation/conflict recovery and empty states, use labelled native controls and responsive existing styles, and escape user text. No checklist pass/asset attribution/material movement is created by default. Browser tooling/binaries are unavailable; visual/mobile/keyboard QA remains pending. Opening a correction after an already-approved report and its downstream BAST invalidation follows in slice 4, so the entire report workflow is not declared complete yet.

Verification:
- Compose `php artisan test --compact --stop-on-error --stop-on-failure tests/Feature/ServiceReportHttpTest.php tests/Feature/ServiceReportWorkflowTest.php tests/Feature/ReportEvidenceHttpTest.php`: **15 passed / 152 assertions**, 21.24 seconds.
- Compose `php artisan test --compact --stop-on-error --stop-on-failure tests/Feature/ServiceReportWorkflowConcurrencyTest.php`: **4 passed / 116 assertions**, 80.86 seconds. Two submitters, two independent reviewers and two successor creators have one winner; identical simultaneous submissions freeze once. Both workers prove lock contention and independent REPEATABLE-READ connections with a prior snapshot.
- `npm run test:frontend`: **112 passed**. Initial new frontend tests matched FormField wrappers instead of native inputs; selectors were corrected, the 13-test report file and full frontend suite rerun successfully.
- `npm run typecheck`, `npm run build`, host `vendor/bin/pint --dirty --format agent`, and `git diff --check` pass. Build retains the existing optional fontaine warning; no dependencies added.

Local commits so far: `d401756` drafts; `7a257a7` evidence/material backend. No application migrations, push, PR, merge or deployment in this session. Full backend regression is reserved for final verification after the remaining slices.

## Slice 4 — versioned BAST and correction after QA

Implemented document headers, linear version chains, draft item changes, issue/pending, external accepted/rejected decisions, cancellation and supersession/replacement. Packages may include multiple compatible WOs. Every item must reference the current approved scope head; customer/context/site authority are rechecked under parent locks. Scope claims cover all report revisions and pending/accepted versions, preventing duplicate acceptance claims across documents. Issued content/items and final decisions are protected by MySQL triggers. Accepted scope cannot be retired or receive a successor.

Private issued/decision uploads bind the exact version, observed version counter, report IDs and explicit all-scope document/authority attestation. PIC name/capacity/authority reference and all authorized sites are required. External decision time is preserved with microseconds separately from server recording time; historical external decision times are allowed, future times rejected. No response remains pending. There is no portal, paid signing provider, implied acceptance or exception closure.

All-parent scope checks apply before list pagination and to detail/download; a technician assigned to only one WO cannot see or download a combined package. Ready metadata and byte integrity are checked; final facts remain preserved if a file later needs recovery. File keys never overwrite. A BAST technical rejection creates successors only for explicitly selected defect scopes/authors and moves only affected WOs to rework. Other approved/accepted scope evidence is retained.

Koordinator can open approved-report corrections, atomically superseding affected pending packages across all their WOs and preserving old approval/evidence. Document fix returns awaiting_acceptance to pending_review; technical work uses rework. A bounded fresh-discovery retry handles an issue committing ahead of correction. A Koordinator can also assign a needs_revision successor to another eligible WO member after the original author loses access, preserving the original QA category and auditing the author change.

Verification:
- Compose `php artisan test --compact --stop-on-error --stop-on-failure tests/Feature/BastServiceTest.php tests/Feature/BastHttpTest.php tests/Feature/ServiceReportDraftTest.php tests/Feature/ServiceReportWorkflowTest.php`: **78 passed / 468 assertions**, 31.02 seconds. Covers seven acceptance/history rollback points with identical retries, immutable final evidence, rejected/partial packages, all-parent access/revocation, author replacement and legacy-compatible drafts.
- Compose `php artisan test --compact --stop-on-error --stop-on-failure tests/Feature/BastConcurrencyTest.php`: **4 passed / 118 assertions**, 95.64 seconds. Independent processes/connections, prior snapshots and NOWAIT contention probes cover two issuers, accept/cancel, accept/correction, and issue/correction rediscovery. The test workers explicitly share the fake private disk's actual root; no application evidence is used.
- Full frontend **122 passed**; typecheck/build/Pint/diff check pass. New forms preserve state on errors and refresh committed versions/command identities on success. Build retains only the existing optional fontaine warning. Browser/mobile/keyboard QA remains pending.
- Added BAST tables and immutable-history triggers additively. No legacy approval, scope mapping, signer, acceptance, usage or file metadata was synthesized. Application migrations remain unapplied.

Next: normal WO closure, hold/resume and authorized pre-execution cancellation; explicit ticket resolution after all dependent obligations, then full regression and local migration review. New lifecycle files currently being prepared are outside this slice's commit.

## Slice 5 — normal closure and ticket lifecycle

Normal closure is coordinator-only, versioned and audited. It locks the complete parent set, reads every required active scope head again and requires compatible accepted BAST items plus real QA/decision history, signer/authority/time facts and ready, verified private evidence. Empty/partial/pending/rejected/stale or legacy unproven coverage cannot close. History retains exact scope/report/item/version/file IDs; closed_at is separate from completed_at/verified_by. Exception closure is rejected and finance tables are never consulted. Ticket resolution is an explicit human command: all dependent WOs must have audited closed/cancelled disposition and no open required obligations. It cannot resolve when just one WO is done.

Hold/resume records and restores only the audited previous state. Running sessions prevent hold. Normal pre-execution cancellation atomically cancels future visits/bookings and gives scopes an explicit disposition, preserves membership and actual-time provenance, and refuses existing report/material/execution evidence or ambiguous authority. Accepted work is never silently cancelled. A coordinator can explicitly establish a shared acceptance context before any BAST reference, with a reason/history. M1 start can no longer bypass the versioned booking command; started_at is recorded only on an actual assigned-to-in_progress start, without inventing legacy timestamps.

Ticket UI adds reasoned waiting-information/authorization, rejection, duplicate/canonical, cancellation and remote/all-WO resolution actions. Awaiting authorization can be approved only with explicit known coverage and a valid reference when authorized. WO lifecycle UI shows per-scope readiness, status history and closure basis, with coordinator/member action boundaries, loading and conflict recovery. Browser/mobile/keyboard checks remain pending.

Verification through Compose:
- Lifecycle, ticket regression, execution and assignment: **229 passed / 1,922 assertions**, 32.48 seconds.
- Closure concurrency: **2 passed / 57 assertions**, 45.11 seconds. Two independent live MySQL REPEATABLE-READ transactions prove NOWAIT contention with an existing snapshot. Two closers commit one closure/history; closure versus a testing-only direct required-scope writer either sees the added unaccepted scope or the writer is rejected after closure. Added an incremental current-read parent guard in the scope INSERT trigger to fence a writer with an older snapshot. This probe is not a new scope-editing UI.
- Full frontend: **127 passed**; typecheck/build/Pint/diff check pass (existing optional fontaine warning only).

Application migrations are still unapplied. Final full backend regression, application migration/data review and remaining evidence polish follow. No push, PR, merge or deployment.

## Slice 6 — ticket attachments and draft evidence correction

Added the Phase 0D ticket_attachments table/model/factory and private upload/download for Admin/Koordinator. Attachments are bound to ticket, purpose, uploader and observed ticket version, have immutable references and atomic command audit, and never approve/resolve a ticket automatically. Terminal tickets reject new attachments while successful retries remain safe. Downloads check the current account and nested ticket; WO technician membership does not grant ticket access. No application records or evidence were synthesized.

An author can detach a mistaken attachment from a current editable draft with a reason and expected report version. The audit retains photo/file/hash metadata, ready bytes remain retained, and copies on submitted predecessor revisions stay unchanged. This enables a document-fix successor to replace mistaken evidence without altering the original submission. A second additive trigger migration uses current locking reads for terminal/accepted scope updates, including scope provenance fields.

Evidence/ticket regression: **94 passed / 805 assertions**, 28.54 seconds, through Compose; full frontend **129 passed**, typecheck/build/Pint/diff check pass. Follow-up predecessor/successor preservation and scope/lifecycle/BAST guard regression: **44 passed / 336 assertions**, 36.87 seconds. The first attachment HTTP test expected 403 for a disabled session; corrected to the existing middleware contract (redirect to login).

## Manual browser QA — pending, no pass claimed

No real browser or browser automation tool/binary is available in this session. HTTP/component tests are automated evidence only. The previously reported execution-browser pass does not cover these new screens. Use separate authorized QA examples; preserve existing records and never enter fabricated customer acceptance on real work.

- At desktop and 360–390 px width: WO → reports → scope draft, saved checklist/assets, evidence upload/download, material consume/return/reversal, submission, independent review and revision history. Check wrapping, no horizontal page overflow, empty/loading states and clear read-only submitted content.
- Keyboard only: every labelled control, details/summary, file input, select, submit, errors/reload link, navigation and downloads; verify visible focus and sensible tab order.
- Slow/error cases: repeated click, stale observed report/WO/BAST/ticket version, network retry, invalid file/quantity and revoked account/membership. Confirm error input is retained and successful forms receive fresh versions/keys. Save draft before changing evidence/material.
- BAST: compatible combined-WO package, explicit scope/PIC/site authority, issued and signed evidence, external versus recorded decision times, reject/document fix/technical rework, immutable accepted versions and cross-parent technician denial. Never use timeout as acceptance.
- Lifecycle/ticket: full required acceptance enables close; missing/partial acceptance blocks; hold/resume preserves prior state; pre-execution cancellation preserves provenance; ticket with another open WO cannot resolve. Validate role-specific controls and terminal read-only state.

Business/rollout gates remain: Phase 0D F01 staging DDL/locking validation and F03 cutover/reconciliation; company-approved authority for cancelling already-executed or partially accepted work; authorized retention/grace-period policy before enabling cleanup. Those paths remain restricted and are not enabled through guessed rules. The code treats this as an internal engineering baseline.

## Local migration/data verification

Verified `php artisan env --no-interaction` = local and Boost read-only DatabaseQuery actual database = nusalogi_maintenance in Compose project nusalogi-m1-test. Before migration: 4 users, 3 WOs, 0 service reports, 0 tickets. Saved a private single-transaction mysqldump (with routines/triggers) at `/tmp/nusalogi-m1-local-backup.f4FrJT/application-before.sql`; this is a local temporary artifact, not committed.

`php artisan migrate --no-interaction` applied these seven pending migrations in batch 2: report revisions; private evidence/material; lifecycle timestamps; BAST; current-read scope INSERT guard; ticket attachments; current-read scope UPDATE guard. `migrate:status` confirms all ran. No reset, fresh migration, seeder, account change or fabricated operational evidence was run on the application database. Nullable additions retain legacy unknowns; no backfill approval/acceptance/usage/timestamp was synthesized. Normal M1 commands require explicit known origin/context/scope.

Before/after counts plus original-column row fingerprints match for all 19 existing domain/security tables, including users, WOs, membership, visits/bookings, scopes, reports/photos and finance. New tables remain empty. The comparison manifest is stored privately beside the backup. Tests continue to use the isolated guarded MySQL testing database; local migration verification is separate from staging F01.

## Completion matrix

| Area | Implemented and automatically verified | Remaining status |
|---|---|---|
| Draft reports | Author/member/current-head/parent/account/version/idempotency/history and process races | Complete backend; new UI browser QA pending |
| Evidence/material | Structured content, private ticket/report/BAST files, immutable submissions, draft detach, decimal ledger and snapshots | Browser/mobile/keyboard QA pending; authorized retention policy required before cleanup |
| Submission/revision/review | Scoped UI/history, independent reviewer, document fix and technical rework, all-required-head aggregation | Browser QA pending |
| BAST/customer acceptance | Multi-WO versions, explicit authority/evidence, stale/claim guards, rejection/correction and immutable final facts | Browser QA pending; real customer authority evidence remains operational input |
| WO/ticket lifecycle | Hold/resume, pre-execution cancel, full normal closure proof, explicit multi-WO ticket resolution, no finance dependency | Browser QA pending; cancellation after execution/partial acceptance remains blocked without company authority |
| Migration/rollout | Local additive migration, backup and preservation review | Staging F01 and F03 cutover/reconciliation pending; no production rollout |

**M1 is not declared fully accepted or rollout-ready while these QA/authority gates remain.** No push, PR, merge or deployment was performed.

## Final automated verification and handoff

Final code under test: `b8c05c0` on feat/m1-service-reports. No code changed during or after the full backend run.

- Compose `php artisan test --compact --no-ansi`: **1,137 passed / 9,178 assertions**, **1,130.08 seconds**, exit 0. This is the actual new full run, replacing the historical 976-test baseline. Database suites ran sequentially against guarded MySQL testing. Includes existing ticket/WO/visit/execution/auth/finance regressions and new report/evidence/material/BAST/lifecycle tests.
- Compose `npm run test:frontend`: **129 passed**, 0 failed/skipped.
- Compose `npm run typecheck` and `npm run build`: pass. Build retains only the existing optional fontaine warning; no dependency change.
- Host PHP 8.5.4 `vendor/bin/pint --dirty --format agent`: pass. `git diff --check` and staged diff checks: pass.
- Process concurrency includes independent MySQL connections, REPEATABLE-READ snapshots, two live transactions and NOWAIT contention probes for draft create/update/retries, material returns, submission/review/revision, BAST issue/decision/correction and closure. Scope insertion versus closure uses an explicitly testing-only direct-writer probe and terminal DB trigger; it is not a production scope-editing UI.
- Browser/mobile/keyboard: **pending**, not represented as passed by HTTP/component tests. Manual checklist above.
- Seven application migrations applied locally (batch 2), private backup retained, all 19 existing domain/security table data fingerprints/counts unchanged. No application seed/reset or invented historical facts.

Local implementation commits:
1. `d401756` — versioned service report drafts.
2. `7a257a7` — private report evidence and material ledger.
3. `b131ec5` — report submission/review and technician interface.
4. `a72bab4` — versioned customer acceptance and corrections.
5. `7c6372c` — audited WO closure and ticket lifecycle.
6. `b8c05c0` — private ticket attachments and draft evidence correction.

Final checkpoint documentation is committed separately after these results. Remaining work is the browser checklist, staging F01/F03 and explicit company authority/retention gates described above. No independent requested coding work is intentionally deferred to those gates. M1 remains partially accepted pending that QA and those decisions, rather than being declared fully complete. Working tree is expected clean after the checkpoint commit. No push, PR, merge or deploy.

## Review follow-up — 16 November 2026

Clean starting worktree at fdc1fed. All three findings reproduced before fixes: technical QA accepted while a booking ran; a stale coordinator object bypassed material membership; dashboard counted 3 rather than 7 active statuses. New frontend label test failed for on_hold. The initial test run hit MySQL 1419 before assertions; restored local container log_bin_trust_function_creators support and reran. No application migration/reset/data change.

P1 fixed: review uses WO/scopes/coordinator/sorted resources/visits/bookings locks, with explicit authorOnly=false for coordinator review. Technical rework checks running bookings before any decision write. Approved/document_fix contracts remain covered. New two-scope test asserts atomic rejection and successful retry after completion. Independent-process start/review and complete/review races use existing prior-snapshot/NOWAIT contention harness and assert no running booking with rework or partial history. Compose workflow + concurrency: **16 passed / 234 assertions**, 236.08s. Browser QA remains pending.

P2 dashboard finding fixed (review item 3): active count includes all seven explicit nonterminal statuses and excludes closed/cancelled, retaining visibleTo throughout. Incremental per-status assertions include a technician member and an unrelated rework WO. Shared Indonesian labels/styles cover all lifecycle statuses, retain safe unknown fallback, and feed existing list/dashboard/chart consumers. Updated metric description. Selected dashboard/material/evidence/workflow/lifecycle regression: **77 passed / 697 assertions**, 34.49s. Full frontend **131 passed**; Compose typecheck/build pass (existing optional fontaine warning). Rendered dashboard rows/distribution test checks new labels and escaped unknown historical values. Browser QA remains pending.
