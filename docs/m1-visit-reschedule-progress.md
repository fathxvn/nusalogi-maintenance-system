# M1 Visit reschedule checkpoint

Branch: `feat/m1-visit-reschedule`.
Base HEAD after fetch: `4b7e4ceed69ab96e92a2442173f726a18f875f39` (PR #19 merged).
Initial working tree was clean. No other worktree is in scope.

## Contract and boundaries

Internal development baseline, not a validated PT Nusalogi SOP. Phase 0C ratification and Phase 0D A1/A3/F04/F05/F14 apply; A2/A4/A5/A6 boundaries remain unchanged.

`VisitSchedulingService::reschedule(actor, sourceVisitId, input)` accepts a stable UUID command key, observed source version, explicit offset-bearing start/end and IANA zone, a nonempty list of eligible WO members, and mandatory reason. Returns the confirmed successor. One successful command atomically supersedes the source, cancels its confirmed bookings, and creates one confirmed successor and bookings. Old cancelled bookings, source interval, actual timestamps, membership and WO status remain unchanged. Same-key identical replay returns the successor before stale/state guards. UTC-equivalent times with unchanged active participants are a no-op even if zone display metadata differs.

Schema is reused: source successor uniqueness and `previous_visit_id` already exist. No migration, dependency, application seeding or QA-data mutation is authorized. Test-only RefreshDatabase/DatabaseMigrations may operate solely behind the existing MySQL `testing`/empty-URL guard.

## Session 1 — completed

Changed: `app/Services/VisitSchedulingService.php`, `tests/Feature/VisitReschedulingTest.php`, this checkpoint.

Backend implemented; shared existing schedule validation/parser reused by draft and reschedule. Lock order is WO/scopes → actor → sorted union of old/new resources → source/bookings; overlap uses a direct current locking join and excludes the source visit only. Rediscovery rolls back the outer command and retries a bounded number of times.

Verified: package versions (`composer show --direct`, package.json), guard source/phpunit configuration, and version-scoped Boost search-docs via existing `boost:mcp` for transactions/locking/validation. Pint completed successfully. Selected service/draft/confirm tests passed: 60 tests / 313 assertions. The first run failed solely on a new test expecting seconds instead of DATETIME(6) microseconds; its expected values were corrected and the selected suite rerun successfully.

Next: Session 2 concurrency/HTTP, then Session 3 frontend. No database suites run concurrently. Browser QA is not yet verified; execution start/complete does not exist and must not be added for race tests. Staging/cutover readiness is not implied.

## Session 2 — completed

Changed additionally: `app/Http/Controllers/WorkVisitController.php`, `routes/web.php`, `tests/Fixtures/m1-visit-booking-worker.php`, `tests/Feature/VisitReschedulingHttpTest.php`, `tests/Feature/VisitReschedulingConcurrencyTest.php`.

POST reschedule scopes the source through its parent WO and does not pre-reject superseded state, allowing service replay. Server props expose an action/key only for allowed unstarted sources, current participant IDs, and both predecessor/successor visit numbers. Existing worker accepts reschedule as a test action, without adding an execution command.

Actual verification: HTTP and two-process concurrency suites passed together: 16 tests / 295 assertions, 120.90 seconds. Covers same-source competing actors, identical simultaneous replay, cross-WO competing replacement slots, confirm-vs-reschedule both orderings, cancel-vs-reschedule both orderings. Barriers form stale snapshots before release; workers assert distinct database connection IDs and REPEATABLE-READ. A new HTTP fixture initially attempted a technician read without membership; fixture corrected to add membership while retaining authorization assertions. A subsequent added error-bag assertion is being checked separately and will be included in final regression.

A read-only attempt to inspect InnoDB transaction metadata during the long suite was denied for lack of PROCESS privilege; it changed no state. Suite completed successfully without intervention. Guarded test databases only were used. Race against execution is NOT VERIFIED because the execution command does not exist; persisted actual timestamp guards are tested instead. No staging/production concurrency or query-plan proof is claimed.

Next: Session 3 UI, frontend contract tests, scheduling regression, typecheck/build/Pint/diff review.

## Session 3 — implementation complete, final regression in progress

Changed additionally: `resources/js/components/visit-reschedule-action.tsx`, `resources/js/pages/work-orders/visits/index.tsx`, `resources/js/lib/visit-time.ts`, `resources/js/types.ts`, `tests/Frontend/visit-reschedule.test.mjs`, `tests/Frontend/component-harness.mjs`.

UI reuses Base UI Dialog, fields, Button, error renderer and existing layout/theme. It prefills source local time/zone and currently eligible active participants, requires a reason, keeps command key/observed version/input on failed retry, blocks repeated submit/closing in flight, and displays predecessor/successor lineage from the server. Form identity deliberately remains stable across stale responses; the explicit refresh action starts with fresh server state. Participants revoked during editing can be removed explicitly without discarding other input.

Timezone helpers preserve explicit offset values and source IANA zones; new ambiguous/nonexistent DST wall times fail locally instead of silently choosing an instant. Backend still validates offsets and is authoritative. This supports existing API-created non-Indonesian source zones without adding a new zone catalog or dependency.

Actual verification so far: frontend 91 passed before the last additional recovery test; focused frontend 7 passed; typecheck passed; build passed (existing optional `fontaine` warning, no dependency installed). HTTP error-bag follow-up assertion initially failed because its next request omitted the session cookie; preserving the POST session cookie made the narrow test pass: 1 test / 15 assertions. Production error handling was not changed. Final regression includes two additional service cases and an additional HTTP stale/conflict case; results pending.

Final selected backend command: schema, draft, confirm, cancellation, existing WorkVisit HTTP, new rescheduling service/HTTP, and all scheduling/cancellation/rescheduling concurrency tests. No full backend suite requested/run. Frontend suite rerun after the additional recovery case. Browser executable/tool unavailable: actual keyboard, focus trap and mobile viewport QA are NOT VERIFIED. Shared Base UI semantics and responsive overflow classes were inspected, not claimed as browser QA.

## Final verification and handoff — READY FOR REVIEW

All three sessions are complete. Branch/HEAD remain `feat/m1-visit-reschedule` / `4b7e4ceed69ab96e92a2442173f726a18f875f39`; no stage, commit, push, PR or merge was performed.

Final executed checks:

- Selected backend scheduling regression (10 files named in Session 3): **117 passed / 1,135 assertions**, 204.11 seconds. Includes all new service and HTTP cases plus existing and new concurrency suites; runs were sequential against the guarded MySQL `testing` database with no database URL.
- Complete frontend suite: **92 passed / 0 failed** (including 8 new reschedule cases).
- `npm run typecheck`: passed.
- `npm run build`: passed, 2,820 modules. Existing optional `fontaine` optimized-font-fallback warning only; dependencies unchanged.
- `vendor/bin/pint --dirty --format agent`: passed. Explicit formatting of changed/new PHP paths also passed.
- `git diff --check`: passed; untracked files separately checked for whitespace, no findings.
- Route inspection: exactly one named POST `work-orders.visits.reschedule` registered.

No full backend suite, application migration/seeding, QA-data writes or other-worktree changes were performed. Browser keyboard/focus/mobile QA remains **NOT VERIFIED**; execution-race verification remains **NOT VERIFIED** because execution commands are outside scope and absent. This does not establish staging/production rollout, query-plan, backfill or cutover readiness. Existing exception rules remain disabled. Domain mutations and tests use existing schema and command infrastructure; no new migration or dependency is needed.

### Review files (14)

Backend:
- `app/Services/VisitSchedulingService.php`
- `app/Http/Controllers/WorkVisitController.php`
- `routes/web.php`

Frontend:
- `resources/js/components/visit-reschedule-action.tsx`
- `resources/js/lib/visit-time.ts`
- `resources/js/pages/work-orders/visits/index.tsx`
- `resources/js/types.ts`

Tests:
- `tests/Feature/VisitReschedulingTest.php`
- `tests/Feature/VisitReschedulingHttpTest.php`
- `tests/Feature/VisitReschedulingConcurrencyTest.php`
- `tests/Fixtures/m1-visit-booking-worker.php`
- `tests/Frontend/visit-reschedule.test.mjs`
- `tests/Frontend/component-harness.mjs`

Checkpoint:
- `docs/m1-visit-reschedule-progress.md`

Next action: user review, then browser QA on disposable data before claiming interaction/mobile QA complete. Source intervals and prior cancellation reasons remain historical; the new command never changes WO membership/status or invents execution timestamps. Confirm/cancel service public contracts are unchanged; only schedule validation/parsing and the optional source-slot exclusion are reused internally.
