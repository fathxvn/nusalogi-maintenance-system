# Google OAuth QA and security review

Branch: `feat/google-oauth-approval`. Review recorded: 2026-11-02.

## Architecture

- Fortify still serves the Blade login page and handles password login, remember-me, logout, and password recovery. Authenticated pages remain React + Inertia.
- The Google link performs a full-page GET to `auth.google.redirect` (`/auth/google/redirect`). `auth.google.callback` (`/auth/google/callback`) retrieves the identity through Socialite with session-based OAuth state validation; `stateless()` is not used.
- The callback requires a nonempty provider ID, a valid email, and Google's actual `email_verified` field to be boolean `true`. Socialite maps Google's `sub` to the provider ID.
- Existing accounts are identified by `google_id`. Email matches never automatically link accounts. Unique database constraints on email and Google ID prevent duplicate inserts; losing registration attempts return safe feedback.
- New accounts have a verified email timestamp, a securely generated hashed random password, `role = null`, `is_active = false`, and `approval_status = pending`. They remain logged out. OAuth tokens are not persisted.
- Linked users log in through the web session guard only when approved and active; successful login regenerates the session. Password authentication enforces the same account restrictions.

## Approval and role rules

| Decision | Result |
| --- | --- |
| Pending → Approved | Admin selects `teknisi` or `koordinator`; account becomes approved and active with that role. |
| Pending → Rejected | Account stays inactive, has no role, and remains stored with rejected status for review. |
| Repeated or conflicting review | Rejected; the first completed decision remains authoritative. |

Approval/rejection routes require authenticated Admin access. Each decision uses one conditional database update against the still-pending Google account and excludes self-modification. The initial approval action cannot assign `admin`. Later role changes remain explicit Admin user-management operations with existing self-modification and last-active-Admin checks.

Ordinary activation and role editing cannot bypass approval. Pending, rejected, or inactive accounts cannot log in; existing sessions are logged out and invalidated on their next web request. Rejection records currently retain status without dedicated reviewer, decision-time, or reason fields.

## Security review

- Reviewed `main...HEAD`, the feature commits, authentication middleware, Fortify recovery, Inertia props, Blade feedback, and regression coverage.
- A credential-pattern scan covered 39 reachable commits and 316 unique committed file versions before G07. It also compared committed content against the local application key and Google client secret without printing their values. No matches were found. This is a targeted history review, not a guarantee against every possible secret format.
- `.env` is ignored and absent from tracked history. The two unrelated untracked Tinker files were neither read nor changed.
- Approval props use explicit attribute allowlists. Password hashes, Google IDs, OAuth tokens, and two-factor secrets are excluded. OAuth warning logs and validation feedback contain generic messages rather than provider exceptions or credentials. Blade renders status and error messages with escaping.
- G07 reproduced and fixed lost feedback during the Inertia-to-Blade HTTP 409 handoff. The middleware now reflashes session data before the full-page navigation; regressions cover pending-registration status and cancellation errors, including normal expiry afterward. Authentication and approval decisions are unchanged.

## Manual browser QA

The following results were supplied by the user during G07. These are manual browser checks, not automated browser tests, and were not independently rerun by the coding agent.

| Scenario | Reported result |
| --- | --- |
| Admin password login | Passed |
| Google-account logout | Passed |
| Pending account accessing dashboard | Blocked as expected |
| Rejected account login | Blocked as expected |
| Admin-deactivated account login | Blocked as expected |
| Google registration → Pending → Admin Approval → Google Login | Passed end to end |

## Automated validation

All required commands passed on the G07 working tree:

| Command (via `./vendor/bin/sail`) | Result |
| --- | --- |
| `artisan test --compact` | 195 passed, 1,415 assertions |
| `npm run typecheck` | Passed |
| `npm run test:frontend` | 4 passed |
| `npm run build` | Passed; optional font warning below |
| `pint --test` | Passed |
| `composer audit` | No security vulnerability advisories |

`git diff --check` passed. Both Google routes are registered. The focused login-page suite passed 13 cases with 88 assertions. HTTP feature tests use the separate MySQL testing database and fake Google identities or HTTP responses; they do not automate a real browser or contact Google.

## Environment and deployment

Configure these names through the deployment environment; do not commit populated values:

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.
- `APP_URL`, `APP_ENV`, `APP_DEBUG`, `APP_KEY`.
- `SESSION_DRIVER`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`.
- Database and mail environment variables already used by this application.

Before deployment:

1. Register the exact HTTPS callback URI in the Google OAuth client and confirm consent-screen publishing/test-user restrictions for the intended audience.
2. Apply the new Google-auth migration before serving the feature. Existing accounts default to approved; Google registration explicitly overrides that default to pending/inactive. Rolling back drops Google IDs and approval decisions, so preserve data and plan rollback separately.
3. Set production debug mode off. Use HTTPS, Secure and HttpOnly session cookies, and SameSite=Lax for the top-level Google callback. Configure the correct cookie domain and a persistent session store shared across application nodes.
4. Install locked dependencies, build Vite assets, and refresh configuration caches after setting OAuth variables. Remove development hot-file references from deployment artifacts. Verify mail delivery for account recovery.
5. Protect environment secrets and database/session storage. Keep OAuth callback query strings and provider responses out of proxy/access logs and production debug tooling. Review edge rate limits for the public OAuth routes, which currently have no dedicated application throttle.
6. Ensure an approved active Admin is available to review registrations. Repeat the manual workflow in the deployed environment with an approved user and restricted accounts.

The build currently emits a non-blocking warning that optional `fontaine` support for optimized font fallbacks is unavailable. No dependency change is required for this feature.
