# M1 reports, evidence, acceptance and closure — 15 November 2026

Branch: `feat/m1-service-reports`. Starting HEAD: `3a5087745643238ee0fd32a65614dc1a9ccc10c9`.
The two modified models and four untracked report files present at handoff are preserved and included in this work. Nothing is pushed, merged or deployed.

## Baseline and checklist

Internal development baseline: Blueprint 0A, ratified ADR 0C, Phase 0D including A1–A6, and the visit execution checkpoint. This is not company SOP ratification. No invented legacy provenance, approval, acceptance or usage. Inventory, finance M2, new roles and exception closure remain outside scope.

- [x] Draft report service/schema: audited create/update; author/member/account/parent/head/state guards; partial updates; server controls; version/idempotency; history rollback; independent MySQL process races. HTTP/UI follows in the report workflow slice.
- [ ] Evidence/material: structured checklist and asset results; private validated uploads and scoped downloads; submitted evidence immutable across writers; append-only decimal material ledger and audited adjustments; reproducible submission snapshots.
- [ ] Submission/review/revisions: scoped history and UI; frozen submission; one author; independent reviewer; document fix versus technical rework; all required scope heads determine WO status; transactions, versions, history and retries.
- [ ] BAST: document/version/item chain; compatible multi-WO packages; all-parent authorization; approved head references; immutable issued content and accepted decisions; external evidence, authority, signer/recorder times; claim and stale-decision races; rejection follow-up.
- [ ] Lifecycle/closure: review/rework/acceptance/closed; hold/resume/cancel within baseline authority; active booking impact; nonempty full required scope acceptance with evidence IDs in closure history; exception denied; multi-WO ticket resolution guarded; payment independent.
- [ ] Final verification: full backend/frontend, typecheck, build, Pint, diff review; browser/mobile QA or explicit pending checklist.

## Environment and evidence limits

Use `docker compose -p nusalogi-m1-test -f compose.yaml exec -T -u sail laravel.test` for commands. Database tests require the repository's MySQL `testing` / empty database URL guard; database suites run sequentially. Application QA accounts/data are preserved. No application migration has been applied by this session.

Boost is available through the installed `boost:execute-tool` command; search-docs was queried for Laravel 13 transactions/locking/validation/testing. Installed framework is 13.36.0, PHPUnit 12.5.39, PHP runtime image 8.5. `.ai/rules` is absent. Connected real-browser tooling has not been found; browser QA for new work is pending. Staging F01/F03 and company authority/retention decisions in Phase 0D §9.2 remain external rollout gates, not evidence of local implementation completion.

## Current checkpoint

Slice 1 verified: `php artisan test --compact tests/Feature/ServiceReportDraftTest.php tests/Feature/ServiceReportSchemaTest.php tests/Feature/ServiceReportDraftConcurrencyTest.php` through Compose: **57 passed, 382 assertions**, 100.51 seconds. This includes four process races (competing create/update and identical create/update retries), each with independent connection IDs, REPEATABLE-READ, a pre-existing snapshot and a NOWAIT contention probe in both live transactions before parent release. One domain/history mutation commits; a stale/new-key loser conflicts; identical retries return the same report.

The first expanded run had one fixture-only failure: replicating a scope omitted its required `created_at`; corrected and the complete selected suite passed. Pint and `git diff --check` passed. Container Pint `--dirty` cannot resolve the host worktree Git metadata; host PHP 8.5.4 ran `vendor/bin/pint --dirty --format agent` successfully, followed by explicit-path formatting. Frontend was unchanged in slice 1. Application report migration is still unapplied; no QA data was modified. New browser QA remains pending.
