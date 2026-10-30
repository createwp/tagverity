# A1 Real Audit Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Audits run on real GTM and GA4 data, report per-source status, and never show a normal health score when a required source is missing.

**Architecture:** A TagVerity account can connect several Google logins. After connecting, the user picks which GA4 properties (grouped by GA4 account) and GTM containers to link to each client, and marks one of each as the audit target. The cron runner resolves that target, collects data through a small `AuditDataSource` interface (implemented by `GoogleApiClient`, faked in tests), and classifies each source's outcome. `AuditOutcome` then decides `complete` / `partial` / `failed` and whether a score may be shown.

**Tech Stack:** PHP 8.1, PDO/MySQL, `google/apiclient` (TagManager, AnalyticsData), vanilla JS.

**Spec:** `docs/AUDIT_DEFINITION_OF_DONE.md` (outcome states, source statuses, "never score 100 on missing data") and the A1 item in `docs/superpowers/plans/2026-10-30-enhancement-roadmap.md`.

## Global Constraints

- Run PHP as `C:\wamp64\bin\php\php8.1.0\php.exe`; written below as `php`.
- Namespace `GTMAuditor\`, files in `src/`, loaded with `require_once` like existing tests do.
- Outcome values exactly: `queued`, `running`, `complete`, `partial`, `failed`.
- Source status values exactly: `success`, `not_applicable`, `permission_denied`, `authentication_failed`, `quota_limited`, `timed_out`, `unavailable`, `insufficient_coverage`.
- Required sources for this phase: `gtm`, `ga4`. `bigquery` and `crawl` are always recorded as `not_applicable`.
- `RULE_SET_VERSION = '2026.10.1'`, `SCORING_VERSION = '2026.10.1'`, GA4 reporting window 30 days.
- A health score is stored only for `complete` audits; otherwise `health_score` is `NULL`.
- Prepared statements for every variable SQL value; `htmlspecialchars()` on every echoed value; CSRF via `api_require_csrf()` on new POST endpoints.
- DB-backed tests create their own rows and delete them at the end (even on failure, via `try/finally`); print `... skipped: no database` and exit 0 if `db()` throws.

## Review Focus

1. Google refresh token revoked → audit is `failed` with `gtm` and `ga4` both `authentication_failed`, no score. (Task 6 test)
2. Client connected to a Google account with several GA4 properties and none chosen → `ga4` is `insufficient_coverage`, detail `target_not_selected`, audit `partial`. (Task 5 test)
3. Linking the same property again, or from a second Google login, must not duplicate it or wipe its audit target; ids the login can't see are rejected. (Task 7 test)
4. A queued (not yet started) audit counts against the monthly credit limit. (Task 10 test)
5. GA4 property with zero purchases (non-ecommerce site) is still `ga4: success`, not a failure. (Task 3 test)

---

### Task 1: Migration 013 — outcome, source status, audit targets

**Files:**
- Modify: `run-migrations.php` (append block before the final `echo json_encode`)
- Test: `tests/Migration013Test.php`

**Interfaces:**
- Produces columns and keys:
  - `audit_jobs.status` enum gains `partial`; `audit_jobs.source_status JSON NULL`.
  - `gtm_containers`: `audit_target TINYINT(1) NOT NULL DEFAULT 0`, `gtm_account_name VARCHAR(255) NOT NULL DEFAULT ''`, `linked_by_user_id INT UNSIGNED NULL`, `linked_at DATETIME NULL`, unique key `uq_gtm_containers_client_container (client_id, container_id)`.
  - `ga4_properties`: `audit_target TINYINT(1) NOT NULL DEFAULT 0`, `ga4_account_id VARCHAR(30) NOT NULL DEFAULT ''`, `ga4_account_name VARCHAR(255) NOT NULL DEFAULT ''`, `linked_by_user_id INT UNSIGNED NULL`, `linked_at DATETIME NULL`, unique key `uq_ga4_properties_client_property (client_id, property_id)`.
  - `ga4_streams`: unique key `uq_ga4_streams_property_stream (ga4_property_id, stream_id)`.
- A client may link any number of GA4 properties and GTM containers, from any of the account's Google connections. The same property can't be linked to one client twice.

- [ ] **Step 1: Write the failing test** — runs `php run-migrations.php` twice via `exec()` (CLI path skips the key check), asserts both runs print `"ok":true`, then queries `information_schema.COLUMNS` / `STATISTICS` and asserts every column and key listed above exists and `audit_jobs.COLUMN_TYPE` contains `'partial'`.
- [ ] **Step 2: Run** `php tests/Migration013Test.php` — expect FAIL "missing source_status".
- [ ] **Step 3: Implement** a `// Migration 013: real audit data and resource linking` block in the existing style: guard each `ALTER` with an `information_schema` check (copy the pattern used for `mapper_snippet_detected_at`); for the enum use `ALTER TABLE audit_jobs MODIFY status ENUM('queued','running','complete','partial','failed') NOT NULL DEFAULT 'queued'` only when `COLUMN_TYPE` lacks `partial`. Before adding each unique key, delete duplicate rows keeping the lowest `id` (for `ga4_properties`, delete the duplicates' `ga4_streams` first). Label `'013 - real audit data and resource linking'`.
- [ ] **Step 4: Run** the test — expect PASS on both runs.
- [ ] **Step 5: Commit** `feat: add migration 013 for audit outcomes and audit targets`

### Task 2: Source status classification and the data-source interface

**Files:**
- Create: `src/SourceStatus.php`, `src/AuditDataSource.php`
- Modify: `src/GoogleApiClient.php`
- Test: `tests/SourceStatusTest.php`

**Interfaces:**
- Produces `final class SourceStatus` with constants `SUCCESS, NOT_APPLICABLE, PERMISSION_DENIED, AUTHENTICATION_FAILED, QUOTA_LIMITED, TIMED_OUT, UNAVAILABLE, INSUFFICIENT_COVERAGE` (string values as in Global Constraints) and `public static function fromThrowable(\Throwable $e): string`.
- Produces `interface AuditDataSource { public function liveContainerVersion(string $gtmAccountId, string $containerId): array; public function ga4Report(string $propertyId, array $dimensions, array $metrics, int $daysBack, int $limit): array; }` — both **throw** on failure. `liveContainerVersion` returns `{tags[], triggers[], variables[], container{account_id, container_id}}`; `ga4Report` returns rows shaped like today's `runGa4Report`.
- `GoogleApiClient implements AuditDataSource`. Existing `getContainerVersion()` / `runGa4Report()` keep their signatures and swallow-and-log behaviour by wrapping the new methods. Constructor throws `\RuntimeException('authentication_failed', 401)` when `fetchAccessTokenWithRefreshToken()` returns an `error` key.

- [ ] **Step 1: Write the failing test** asserting `fromThrowable` maps: `new \Google\Service\Exception('x', 401)` → `authentication_failed`; code 403 → `permission_denied`; code 429 → `quota_limited`; `new \RuntimeException('authentication_failed', 401)` → `authentication_failed`; `new \GuzzleHttp\Exception\ConnectException('timeout', new \GuzzleHttp\Psr7\Request('GET','/'))` → `timed_out`; `new \RuntimeException('boom')` → `unavailable`. Load `vendor/autoload.php`.
- [ ] **Step 2: Run** `php tests/SourceStatusTest.php` — expect FAIL (class not found).
- [ ] **Step 3: Implement** `SourceStatus`, `AuditDataSource`, and the `GoogleApiClient` changes. Classification: `ConnectException` → timed_out; otherwise by `getCode()` 401 / 403 / 429; else unavailable.
- [ ] **Step 4: Run** the test, then `php -l src/GoogleApiClient.php` — expect PASS / no syntax errors.
- [ ] **Step 5: Commit** `feat: classify Google source failures and add AuditDataSource interface`

### Task 3: Audit data collector

**Files:**
- Create: `src/AuditDataCollector.php`, `tests/Fakes/FakeAuditDataSource.php`
- Test: `tests/AuditDataCollectorTest.php`

**Interfaces:**
- Consumes `AuditDataSource`, `SourceStatus`.
- Produces `final class AuditDataCollector { public const GA4_WINDOW_DAYS = 30; public function collect(AuditDataSource $source, array $target): array }`.
  - `$target` = `['gtm' => ['account_id'=>string,'container_id'=>string,'public_id'=>string] | null, 'gtm_reason' => string, 'ga4_property_id' => ?string, 'ga4_reason' => string]` (reasons come from Task 5).
  - Returns `['gtm' => array, 'ga4' => array, 'source_status' => ['gtm' => ['status'=>string,'detail'=>string], 'ga4' => [...], 'bigquery' => ['status'=>'not_applicable','detail'=>''], 'crawl' => [...same]]]`.
- `FakeAuditDataSource` — constructor `(array $containerVersion = [], array $reportsByFirstDimension = [], ?\Throwable $gtmError = null, ?\Throwable $ga4Error = null)`; `ga4Report` keys rows by the first dimension name, or `'_total'` when dimensions are empty.

GA4 queries, keyed exactly as `AuditEngine::runDataQualityChecks()` reads them:

| key | dimensions | metrics | limit |
|---|---|---|---|
| `landing_page` | `landingPage` | `sessions` | 50 |
| `total_sessions` (int) | — | `sessions` | 1 |
| `purchase_transactions` | `transactionId` | `ecommercePurchases` | 100 |
| `page_location` | `pageLocation` | `screenPageViews` | 200 |
| `hostname` | `hostName` | `sessions` | 50 |

- [ ] **Step 1: Write failing tests:**
  - `test_all_sources_succeed`: fake returns 2 tags and landing rows → `source_status.gtm.status === 'success'`, `ga4.status === 'success'`, `gtm['tags']` count 2, `ga4['total_sessions']` is int.
  - `test_gtm_permission_denied`: gtmError code 403 → `gtm.status === 'permission_denied'`, `gtm` data `[]`, `ga4` still `success`.
  - `test_no_ga4_target`: `ga4_property_id` null, `ga4_reason` `'target_not_selected'` → `ga4.status === 'insufficient_coverage'`, detail `'target_not_selected'`; when reason is `'not_connected'` → status `'unavailable'`.
  - `test_zero_purchases_is_success`: empty `purchase_transactions` rows → `ga4.status === 'success'`.
- [ ] **Step 2: Run** `php tests/AuditDataCollectorTest.php` — expect FAIL.
- [ ] **Step 3: Implement** — one `try` per source; any throw from a GA4 query marks the whole GA4 source failed with `SourceStatus::fromThrowable`.
- [ ] **Step 4: Run** — expect PASS.
- [ ] **Step 5: Commit** `feat: collect live GTM and GA4 audit data with per-source status`

### Task 4: Audit outcome and the `(not set)` score gate

**Files:**
- Create: `src/AuditOutcome.php`
- Modify: `src/AuditEngine.php` (check 3.1 evidence)
- Test: `tests/AuditOutcomeTest.php`

**Interfaces:**
- Produces `final class AuditOutcome { public const RULE_SET_VERSION = '2026.10.1'; public const SCORING_VERSION = '2026.10.1'; public const REQUIRED_SOURCES = ['gtm','ga4']; public function decide(array $sourceStatus, array $findings, int $rawScore): array }` returning `['status' => 'complete'|'partial'|'failed', 'health_score' => ?int]`.
- Rules: every required source `success`/`not_applicable` → `complete`; at least one required source `success` but not all → `partial`; none `success` → `failed`. `health_score` is non-null only when `complete`. If any finding's decoded `evidence` has `score_gate === true`, the score is `min($rawScore, 1)`.
- `AuditEngine` check 3.1 adds `'score_gate' => $severity === 'critical'` to its evidence array.

- [ ] **Step 1: Write failing tests:** both success, no findings, raw 100 → `complete`/100; gtm success + ga4 `insufficient_coverage` → `partial`/null; both `authentication_failed` → `failed`/null; complete with a finding whose evidence JSON has `"score_gate":true`, raw 85 → 1; engine fed `landing_page` `(not set)` at 20% of 1000 sessions emits a critical finding whose evidence contains `"score_gate":true`.
- [ ] **Step 2: Run** `php tests/AuditOutcomeTest.php` — expect FAIL.
- [ ] **Step 3: Implement** both changes.
- [ ] **Step 4: Run** — expect PASS.
- [ ] **Step 5: Commit** `feat: decide audit outcome from source status and gate (not set) scores`

### Task 5: Audit target resolver and selection

**Files:**
- Create: `src/AuditTargetResolver.php`
- Test: `tests/AuditTargetResolverTest.php`

**Interfaces:**
- Produces `final class AuditTargetResolver`:
  - `public function resolve(\PDO $pdo, int $clientId): array` → `['connection' => ?array /* google_connections row */, 'gtm' => ?array, 'gtm_reason' => string, 'ga4_property_id' => ?string, 'ga4_reason' => string]`.
  - Selection per resource: the row with `audit_target = 1`; else the only row; else null. Reasons: `''` when found, `'not_connected'` when zero rows, `'target_not_selected'` when several rows and none chosen.
  - `connection` is the `google_connections` row joined through the chosen GTM container, else the chosen GA4 property, constrained to the client's `account_id`.
  - `public function setTargets(\PDO $pdo, int $clientId, ?int $gtmContainerRowId, ?int $ga4PropertyRowId): void` — throws `\InvalidArgumentException('foreign_target')` if a row id does not belong to `$clientId`; clears then sets `audit_target` per table inside one transaction.

- [ ] **Step 1: Write failing tests** (DB-backed; inserts an account, client, connection, 2 containers, 2 properties): no targets → both reasons `'target_not_selected'`; after `setTargets` → correct ids and empty reasons; a single property and no target → it is chosen; `setTargets` with another client's row id throws `foreign_target`; a client with nothing → reasons `'not_connected'`, `connection` null.
- [ ] **Step 2: Run** `php tests/AuditTargetResolverTest.php` — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — expect PASS.
- [ ] **Step 5: Commit** `feat: resolve and select per-client GTM and GA4 audit targets`

### Task 6: Wire the job runner

**Files:**
- Modify: `src/AuditJobRunner.php`
- Test: `tests/AuditJobRunnerTest.php`

**Interfaces:**
- Consumes Tasks 2–5.
- Constructor becomes `__construct(?callable $sourceFactory = null)`; the factory is `fn(array $connectionRow): AuditDataSource` and defaults to `fn($c) => new GoogleApiClient($c)`.
- `process()` resolves the target, builds the source (a throw from the factory → every required source gets `SourceStatus::fromThrowable($e)`), collects data, runs `AuditEngine` with `ga4PropertyId` and verified domains (`SELECT url FROM domains WHERE client_id = ? AND verified = 1`), decides the outcome, persists findings, and updates `audit_jobs` with `status`, `health_score`, `credits_used = 1`, and `source_status` JSON. The JSON is the collector's array plus `rule_set_version`, `scoring_version`, `ga4_window_days`, `gtm_public_id`, `ga4_property_id`.
- A missing connection no longer throws: it records `unavailable` / `not_connected` and the job ends `failed`. `processNext()` stops overwriting the status with `complete`; it only sets `failed` on an unexpected exception.

- [ ] **Step 1: Write failing tests** (DB-backed, queued job per case, factory returns `FakeAuditDataSource`): happy path → row `status='complete'`, `health_score` not null, `source_status` JSON decodes with `rule_set_version '2026.10.1'`; factory throws `RuntimeException('authentication_failed', 401)` → `failed`, null score, both sources `authentication_failed`; ga4 target not selected → `partial`, null score.
- [ ] **Step 2: Run** `php tests/AuditJobRunnerTest.php` — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — expect PASS. Also run `php cron/run-audits.php` with an empty queue — expect no errors.
- [ ] **Step 5: Commit** `feat: run audits on real GTM and GA4 data with honest outcomes`

### Task 7: Discovery and user-selected linking

Connecting Google no longer imports anything. The user picks which GA4 properties and GTM containers to link to a client.

**Files:**
- Create: `src/DiscoverySource.php`, `src/GoogleResourceDiscovery.php`, `src/GoogleResourceLinker.php`, `tests/Fakes/FakeDiscoverySource.php`
- Modify: `src/GoogleApiClient.php`, `api/oauth-callback.php` (delete the inline GA4 import block)
- Test: `tests/GoogleResourceLinkerTest.php`

**Interfaces:**
- Produces `interface DiscoverySource` (all methods **throw** on failure):
  - `listGa4AccountSummaries(): array` → `[['account_id'=>string,'account_name'=>string,'properties'=>[['property_id'=>string,'display_name'=>string]]]]`. `GoogleApiClient` implements it with the Admin API `accountSummaries->listAccountSummaries(['pageSize'=>200])`, paginated. It replaces the current `properties.list` call, which has no filter.
  - `listGa4Streams(string $propertyId): array` → `[['stream_id','measurement_id','stream_url']]`, web streams only.
  - `listGtmAccountsAndContainers(): array` → `[['account_id','account_name','containers'=>[['container_id','public_id','name']]]]`.
  - Keep the old `listGa4PropertiesAndStreams()` / `listGtmContainers()` only if something still calls them; otherwise delete them.
- Produces `final class GoogleResourceDiscovery { public function discover(\PDO $pdo, int $clientId, DiscoverySource $source): array }` → `['ga4_accounts' => [...each property gains 'linked' => bool], 'gtm_accounts' => [...each container gains 'linked' => bool], 'errors' => ['ga4' => ?string, 'gtm' => ?string]]`. One failing side records `SourceStatus::fromThrowable` in `errors` and still returns the other side.
- Produces `final class GoogleResourceLinker`:
  - `link(\PDO $pdo, int $clientId, int $connectionId, int $userId, DiscoverySource $source, array $ga4PropertyIds, array $gtmContainerIds): array` → `['ga4_linked'=>int,'gtm_linked'=>int]`. Re-runs discovery and rejects (`\InvalidArgumentException('not_accessible')`) any id the connection can't see, so callers can't link arbitrary ids. Upserts on the Task 1 unique keys, filling account names, `linked_by_user_id`, `linked_at`, `last_synced_at`, and upserts each linked property's streams. Never changes `audit_target`, except that a client whose total linked count of that type becomes exactly 1 gets that row targeted. One transaction.
  - `unlink(\PDO $pdo, int $clientId, string $type /* 'ga4'|'gtm' */, int $rowId): void` — deletes the row and its dependants (`ga4_streams`, `domain_ga4_properties` / `domain_containers`); throws `foreign_target` when the row isn't the client's.
  - `disconnect(\PDO $pdo, int $accountId, int $connectionId): void` — deletes every linked row for that connection across the account's clients (with dependants), then the `google_connections` row; throws `foreign_target` for another account's connection.
- The OAuth callback stores the connection, then redirects to `/connections.php?client_id=N&connection_id=M&pick=1`, keeping the client id from the existing `oauth_return_to` parsing.

- [ ] **Step 1: Write failing tests** (DB-backed, fake source with 2 GA4 accounts × 2 properties and 1 GTM account × 2 containers):
  - `discover` marks nothing linked at first.
  - `link` of 3 properties from both GA4 accounts plus 1 container stores 3 property rows with the correct `ga4_account_name`, their streams, and auto-targets the single container.
  - Linking a property id the fake doesn't return throws `not_accessible` and writes nothing.
  - Linking from a second connection (a second fake) adds properties to the same client.
  - Linking an already-linked property again does not duplicate it and keeps its `audit_target`.
  - `unlink` with another client's row throws `foreign_target`.
  - `disconnect` removes that connection's rows but not the other connection's.
- [ ] **Step 2: Run** `php tests/GoogleResourceLinkerTest.php` — expect FAIL.
- [ ] **Step 3: Implement** the interface, discovery, linker, `GoogleApiClient` methods, and the callback change.
- [ ] **Step 4: Run** the test, plus `php tests/GoogleOAuthFlowTest.php` (update its expectations if it asserted the old import) — expect PASS.
- [ ] **Step 5: Commit** `feat: let users choose which GA4 properties and GTM containers to link`

### Task 8: Linking endpoints

**Files:**
- Create: `api/get-google-resources.php`, `api/link-google-resources.php`, `api/unlink-google-resource.php`, `api/disconnect-google.php`, `api/save-audit-target.php`
- Test: `tests/GoogleLinkingEndpointsTest.php`

**Interfaces:**
- Shared: `require_auth()`, `api_respond()`. POST endpoints read JSON via `api_json_input()` and call `api_require_post`, `api_require_csrf`, `api_require_editor`. Every client-scoped endpoint calls `assert_client_access`, and every `connection_id` is checked as belonging to `$user['account_id']`. Rate-limit `get-google-resources` with `rate_limit('google_discovery', (string)$user['id'], 20, 300)` because it calls Google.
- `GET get-google-resources.php?client_id&connection_id` → `GoogleResourceDiscovery::discover` output.
- `POST link-google-resources.php {client_id, connection_id, ga4_property_ids: string[], gtm_container_ids: string[]}` → `{success, ga4_linked, gtm_linked}`; `not_accessible` → 422.
- `POST unlink-google-resource.php {client_id, type, id}` → `{success}`.
- `POST disconnect-google.php {connection_id}` → `{success}`; requires role `owner` or `superadmin`.
- `POST save-audit-target.php {client_id, gtm_container_id: ?int, ga4_property_id: ?int}` (row ids) → `AuditTargetResolver::setTargets`; `foreign_target` → 422.
- Bad or missing ids → 400 `invalid_request`.

- [ ] **Step 1: Write the failing test** — static checks in the style of `tests/OnboardingPageTest.php`: each POST file contains `api_require_csrf(` and `api_require_editor(`; each client-scoped file contains `assert_client_access(`; each file taking `connection_id` contains a query filtering `google_connections` by `account_id`; `disconnect-google.php` checks `owner`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** the five thin endpoints (logic stays in the Task 5 and Task 7 classes).
- [ ] **Step 4: Run** the test and `php -l` on each file — expect PASS.
- [ ] **Step 5: Commit** `feat: add endpoints to discover, link, unlink and target Google resources`

### Task 9: Connections page — multiple Google logins, resource picker, audit target

**Files:**
- Modify: `connections.php`, `assets/css/app.css` (append only)
- Create: `assets/js/connections.js`
- Test: `tests/ConnectionsPageTest.php`

**Interfaces:**
- Consumes the Task 8 endpoints and `showToast` / the app confirmation modal from `assets/js/app.js`.
- Page layout, client chosen by `client_id` (default: the account's first client; a client switcher if the account has more than one):
  1. **Google accounts** card: every connected login (email, connected date), a **Connect another Google account** button (existing OAuth URL), and a **Disconnect** action (owner only) behind the app confirmation modal, warning that linked properties from that login will be unlinked.
  2. **Linked to this client** card: two lists. GA4 properties show `display_name`, `property_id`, GA4 account name, and which Google login. GTM containers show `name`, `GTM-XXXX`, account name, and login. Each row has an Unlink action and an **Audit target** radio; radios save immediately via `save-audit-target.php`. When more than one of a type is linked and none is targeted, show the hint "Choose which one audits should use."
  3. **Link properties** button per Google login opens a picker dialog (`<dialog id="resource-picker">`, focus-trapped, Escape closes). It loads `get-google-resources.php` with a loading state, shows a search box filtering by name or id, and lists GA4 properties grouped under GA4 account headings with an account-level "select all" and per-property checkboxes; GTM containers are grouped the same way. Already-linked items are checked and disabled with a "Linked" label. If a side has an `errors` entry, that side shows the matching Task 11 message (from `AuditCoverageView`; until Task 11 lands, show the raw status code) instead. A **Link selected** button posts to `link-google-resources.php`, then refreshes the page.
- When the page loads with `pick=1&connection_id=M`, open the picker for that login automatically.
- Use existing design tokens and components from `app.css`; 44px touch targets; works at 360px width.

- [ ] **Step 1: Write the failing test** (static page checks): `connections.php` contains `id="resource-picker"`, `Connect another Google account`, `id="linked-resources"`, `name="ga4_audit_target"`, `name="gtm_audit_target"`, and loads `assets/js/connections.js`; `connections.js` references all five endpoint filenames and `csrf_token`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.** Escape every echoed value; build DOM in JS with `textContent`, never `innerHTML` with API data.
- [ ] **Step 4: Run** the test, `php -l connections.php`, and `node --check assets/js/connections.js` — expect PASS. Reviewer checks the page in a browser at 1280px and 360px.
- [ ] **Step 5: Commit** `feat: manage multiple Google logins and pick resources to link per client`

### Task 10: Credit counting and dashboard polling

**Files:**
- Create: `src/AuditCredits.php`
- Modify: `api/trigger-audit.php`, `api/job-status.php`, `assets/js/app.js:206-226`
- Test: `tests/AuditCreditsTest.php`

**Interfaces:**
- Produces `final class AuditCredits { public static function usedThisMonth(\PDO $pdo, int $clientId): int }` — counts `audit_jobs` with `status IN ('queued','running','complete','partial')` and `created_at` in the current calendar month. `failed` jobs are not charged.
- `trigger-audit.php` uses it and replaces the `!==` CSRF comparison with `hash_equals` (keep the form-POST input).
- `job-status.php` returns `{has_active: bool}`, true when any job is `queued` or `running`.
- `app.js` poller starts when `.status-dot.running, .status-dot.queued` exist and reloads when `has_active` is false.

- [ ] **Step 1: Write failing tests** (DB-backed): one queued (null `started_at`) + one complete + one failed this month → 2; a complete job from last month (set `created_at` to 40 days ago) is not counted.
- [ ] **Step 2: Run** `php tests/AuditCreditsTest.php` — expect FAIL.
- [ ] **Step 3: Implement** all four changes.
- [ ] **Step 4: Run** the test, plus `node -e "require('fs').readFileSync('assets/js/app.js','utf8')"` and `node --check assets/js/app.js` — expect PASS.
- [ ] **Step 5: Commit** `fix: count queued audits against credits and poll until queued jobs finish`

### Task 11: Show coverage and partial outcomes; update docs

**Files:**
- Create: `src/AuditCoverageView.php`
- Modify: `audit-report.php`, `dashboard.php`, `docs/PROJECT_STATUS.md`
- Test: `tests/AuditCoverageViewTest.php`

**Interfaces:**
- Produces `final class AuditCoverageView { public function describe(?array $sourceStatus): array }` returning a list of `['source' => 'Google Tag Manager'|'Google Analytics 4'|'BigQuery'|'Site crawl', 'status' => string, 'tone' => 'ok'|'warn'|'muted', 'message' => string]`. `null` input (pre-013 jobs) → one row, source `'Data coverage'`, tone `muted`, message `'Recorded before coverage tracking was added.'`

Fixed copy (marketer-facing):

| status | message |
|---|---|
| success | Checked successfully. |
| not_applicable | Not part of this audit. |
| permission_denied | We don't have permission. Ask the account owner to grant access, then reconnect Google. |
| authentication_failed | Your Google connection has expired. Reconnect Google to continue. |
| quota_limited | Google asked us to slow down. We'll include this next time. |
| timed_out | Google didn't respond in time. Try the audit again. |
| unavailable + detail not_connected | Not connected yet. Connect Google to include this. |
| insufficient_coverage + detail target_not_selected | Several found — choose which one to audit on the Connections page. |
| unavailable (other) | We couldn't read this source. Try again later. |

- [ ] **Step 1: Write failing tests** for each table row plus the `null` input.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** the view. In `audit-report.php`, add a "Data coverage" card above the findings listing each row. When status is `partial` or `failed`, replace the score with "Score unavailable" plus the sentence "Some data sources couldn't be checked, so we can't give a trustworthy score yet." In `dashboard.php`, show "—" and a `Partial`/`Failed` label instead of a number when `health_score` is null. Update `PROJECT_STATUS.md`: move OAuth identity, GTM discovery, and real audit data out of Phase 1 blockers; remove the resolved credit/polling concerns; add a change-log entry for 2026-10-30.
- [ ] **Step 4: Run** the test, `php -l audit-report.php dashboard.php`, and every test touched in this plan — expect PASS.
- [ ] **Step 5: Commit** `feat: show audit data coverage and partial outcomes`
