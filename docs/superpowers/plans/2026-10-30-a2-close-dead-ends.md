# A2 Close Dead Ends Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every navigation item and button leads somewhere real. This phase adds an audit history page, a working Domain map with "Is this yours?" verification that feeds audits, a real PDF export, honest Monitoring and BigQuery pages, and account-wide audit credits.

**Architecture:** Small `src/` services (AuditHistory, DomainMap, AuditReportPdf) hold the logic and are tested against the local DB. Pages and endpoints stay thin, in the existing raw-PHP style. Domain confirmations feed `AuditJobRunner` → `AuditEngine` as verified and flagged hosts.

**Tech Stack:** PHP 8.1, PDO/MySQL, `dompdf/dompdf` (installed), vanilla JS, inline SVG.

**Spec:** `docs/superpowers/specs/2026-06-26-gtm-auditor-design.md` §4.3 (Visual Domain Map & Verification) and §pricing (audit credits per account per month), `docs/AUDIT_DEFINITION_OF_DONE.md`, and the A2 row of `docs/superpowers/plans/2026-10-30-enhancement-roadmap.md`.

## Global Constraints

- Run PHP as `C:\wamp64\bin\php\php8.1.0\php.exe`; run all tests with `php tests/run.php`.
- Follow the security model in `docs/PROJECT_STATUS.md`: prepared statements, `htmlspecialchars()` on all output, `require_auth()` plus `assert_client_access()` / `assert_audit_access()` on every page and endpoint, CSRF via `api_require_csrf()` on POST, editor role (`api_require_editor`) for writes.
- Audit credits are per **account** per calendar month. A plan's `audit_credits` value of `-1` means unlimited.
- Reuse existing markup and classes (page-header, card, btn, status-dot, badge, alert, empty state) and `app_icon()`. New CSS goes at the end of `assets/css/app.css` under a section comment and uses existing tokens only. No new colours.
- Marketer-facing copy: plain language, sentence case, no jargon without explanation.
- Every new page works at 360 px wide, with 44 px touch targets and no sideways scrolling.
- DB-backed tests use `tests/Fakes/TestFixtures.php` and clean up in `finally`.
- The local DB has a pre-existing queued `audit_jobs` row #1 belonging to the user. Never modify or process it.

## Review Focus

1. An Agency account with several clients shares one monthly credit pool; a second client can't bypass the limit. (Task 1 test)
2. A domain answered "No" or "Investigate" produces a critical finding in the next audit even when GA4 sent no traffic from it. (Task 4 test)
3. Re-running domain discovery never resets a domain the user already answered. (Task 3 test)
4. A user can't see, confirm, or export another account's audit, client, or domain by changing an id in the URL. (Tasks 2, 3, 6 tests)
5. Finding text containing `<script>` or quotes is escaped in the PDF HTML. (Task 6 test)

---

### Task 1: Account-wide audit credits

**Files:**
- Modify: `src/AuditCredits.php`, `api/trigger-audit.php`, `dashboard.php` (credit tile), `billing.php` (credit query)
- Test: `tests/AuditCreditsTest.php`

**Interfaces:**
- Produces `AuditCredits::usedThisMonthForAccount(\PDO $pdo, int $accountId): int`. Same status and month rules as `usedThisMonth`, joined through `clients.account_id`.
- `AuditCredits::hasCreditsLeft(int $used, int $limit): bool`: true when `$limit < 0` or `$used < $limit`.
- `trigger-audit.php`, the dashboard tile and `billing.php` all use `usedThisMonthForAccount`. Remove `billing.php`'s `SUM(credits_used)` query. Keep `usedThisMonth` (per client) for per-client display only if something still uses it; otherwise delete it and its test cases.

- [ ] **Step 1: Write failing tests:**
  - An account with two clients, 2 complete + 1 queued jobs on client A and 1 partial on client B this month: `usedThisMonthForAccount` returns 4.
  - Another account's jobs are not counted.
  - `hasCreditsLeft(5, 5) === false`, `hasCreditsLeft(4, 5) === true`, `hasCreditsLeft(999, -1) === true`.
- [ ] **Step 2: Run** `php tests/AuditCreditsTest.php` — expect FAIL.
- [ ] **Step 3: Implement**, and remove the "billing.php sums credits_used…" item from Known Implementation Concerns in `docs/PROJECT_STATUS.md`.
- [ ] **Step 4: Run** the test and `php tests/run.php` — expect PASS.
- [ ] **Step 5: Commit** `fix: count audit credits per account across all clients`

### Task 2: Audit history page

**Files:**
- Create: `src/AuditHistory.php`, `audits.php`
- Modify: `dashboard.php` ("View all" link → `audits.php?client_id=N`)
- Test: `tests/AuditHistoryTest.php`, `tests/AuditsPageTest.php`

**Interfaces:**
- Produces `AuditHistory::page(\PDO $pdo, int $accountId, ?int $clientId, ?string $status, int $page, int $perPage = 25): array` → `['rows' => [...], 'total' => int, 'page' => int, 'pages' => int]`.
  - Each row: `id, client_id, client_name, status, health_score, triggered_by, created_at, started_at, completed_at`.
  - Newest first, scoped to the account.
  - `$status` must be one of `queued|running|complete|partial|failed`, or null.
  - A `$page` out of range is clamped.
- `audits.php`, sidebar `$active_page = 'audits'`:
  - Filters: Client select (all / each account client) and Status select. Both use GET and are validated against the account's clients and the allowed statuses; invalid values are ignored.
  - Table: status dot + label, client, health score (null → "—" plus a Partial/Failed badge, copying the dashboard's history row markup), triggered by, started, and an action. Complete/partial rows get "View report"; failed rows get "View details" (both → `audit-report.php?id=`); queued/running rows get none.
  - Pagination: Previous / Next plus "Page X of Y".
  - Empty state: "No audits yet. Run your first audit from the dashboard." with a link.
  - At 360 px the table scrolls inside its own box.

- [ ] **Step 1: Write failing tests:**
  - `AuditHistoryTest` (DB-backed): 30 jobs across 2 clients → page 1 has 25 rows and `pages` is 2; the client filter returns only that client's rows; the status filter works; an invalid status throws `InvalidArgumentException`; another account's jobs never appear.
  - `AuditsPageTest` (static, in the style of `tests/OnboardingPageTest.php`): `audits.php` calls `require_auth(`, uses `AuditHistory::page(`, contains `htmlspecialchars(`, and `dashboard.php` links to `audits.php?client_id=`.
- [ ] **Step 2: Run** both — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both and `php -l audits.php` — expect PASS.
- [ ] **Step 5: Commit** `feat: add an account-wide audit history page`

### Task 3: Domain discovery and confirmation (data)

**Files:**
- Modify: `run-migrations.php` (migration 014)
- Create: `src/DomainMap.php`
- Test: `tests/Migration014Test.php`, `tests/DomainMapTest.php`

**Interfaces:**
- Migration 014 adds to `domains`:
  - `host VARCHAR(255) NOT NULL DEFAULT ''`
  - `source ENUM('ga4_stream','site_scan','manual') NOT NULL DEFAULT 'manual'`
  - `confirmation ENUM('unconfirmed','yours','not_yours','investigate') NOT NULL DEFAULT 'unconfirmed'`
  - `confirmed_at DATETIME NULL`
  - a unique key `uq_domains_client_host (client_id, host)`

  Backfill `host` from `url` for existing rows (lowercased host, `www.` kept). Before adding the key, de-duplicate keeping the lowest id, deleting dependants in `domain_containers` / `domain_ga4_properties` / `cross_domain_configs`. Idempotent.
- Produces `final class DomainMap`:
  - `public static function normaliseHost(string $urlOrHost): ?string`: lowercase host without port or path; null for empty, IP-literal, `localhost` or invalid input.
  - `refresh(\PDO $pdo, int $clientId): int`: inserts candidate domains that aren't known yet and returns how many were added. Candidates come from:
    1. `ga4_streams.stream_url` of the client's linked GA4 properties (source `ga4_stream`);
    2. the root URL of the client's latest `site_scans` row (source `site_scan`).
    It never changes an existing row's `confirmation`. For `ga4_stream` hosts it also upserts `domain_ga4_properties (domain_id, property_id)`.
  - `load(\PDO $pdo, int $clientId): array` → `['containers' => [...], 'properties' => [...], 'domains' => [...], 'edges' => [['from' => 'ga4:<row id>', 'to' => 'domain:<row id>'], …]]`.
    - Containers: `id, name, public_id`. Properties: `id, display_name, property_id`. Domains: `id, host, source, confirmation, confirmed_at`.
    - Edges come from `domain_ga4_properties`, plus `domain_containers` where present.
  - `confirm(\PDO $pdo, int $clientId, int $domainId, string $answer, int $userId): void`.
    - `$answer` ∈ `yours|not_yours|investigate`; otherwise throw `InvalidArgumentException('invalid_answer')`. A domain belonging to another client throws `foreign_target`.
    - Sets `confirmation` and `confirmed_at = NOW()`. `yours` → `is_yours = 1, verified = 1, verified_by_user_id, verified_at`. `not_yours` → `is_yours = 0, verified = 0`. `investigate` → `is_yours = NULL, verified = 0`.
  - `addManual(\PDO $pdo, int $clientId, string $urlOrHost): int`: normalises the host and inserts it with source `manual`, or returns the existing id. An invalid host throws `invalid_host`.

- [ ] **Step 1: Write failing tests:**
  - `Migration014Test`: the migration runs twice with `"ok":true`, and the columns and key exist. Use `PHP_BINARY` with `escapeshellarg`; never hard-code a PHP path.
  - `DomainMapTest`:
    - `normaliseHost('https://WWW.Example.com:8443/a?b')` → `'www.example.com'`; `normaliseHost('localhost')`, `('192.168.1.1')` and `('')` → null.
    - `refresh` adds the stream host and the scan host once each; a second `refresh` adds 0.
    - After `confirm(..., 'not_yours')`, `refresh` leaves `confirmation = 'not_yours'`.
    - `load` returns an edge from the property to its stream's domain.
    - `confirm` with another client's domain throws `foreign_target`; an invalid answer throws `invalid_answer`.
    - `addManual('example.org/path')` stores `example.org`, and a second call returns the same id.
- [ ] **Step 2: Run** both — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both and `php tests/run.php` — expect PASS.
- [ ] **Step 5: Commit** `feat: discover client domains and record ownership answers`

### Task 4: Audits use domain answers

**Files:**
- Modify: `src/AuditEngine.php`, `src/AuditJobRunner.php`
- Test: `tests/AuditEngineDomainTest.php`

**Interfaces:**
- `AuditEngine` constructor gains a final optional parameter `array $flaggedDomains = []`: a list of `['host' => string, 'confirmation' => 'not_yours'|'investigate']`. When `$this->gtm` or `$this->ga4` is non-empty, `run()` adds one **critical** finding per flagged host:
  - category `data_quality`, fix_owner `multiple`, affected_item = the host;
  - title `Domain marked as not yours` (not_yours) or `Domain under investigation` (investigate);
  - description: "Someone on your team said {host} is not one of your sites (or asked to look into it), but it is connected to your tracking. Data from it may be polluting your reports.";
  - recommendation: "Check where this domain comes from. If it isn't yours, add it to GA4's unwanted referrals or filter it out, and remove it from your tags. If it is yours, mark it as yours on the Domain map.";
  - evidence `{"host": ..., "confirmation": ...}`.
- `AuditJobRunner` loads verified domains as `SELECT host FROM domains WHERE client_id = ? AND confirmation = 'yours' AND host <> ''`. It keeps accepting legacy `verified = 1` rows by `url` and merges them as hosts. Flagged domains are `confirmation IN ('not_yours','investigate')`. Both are passed to the engine.

- [ ] **Step 1: Write failing tests:** the engine with a minimal GTM payload (one tag) and `flaggedDomains = [['host' => 'spam.example', 'confirmation' => 'not_yours']]` emits a critical finding with affected_item `spam.example` and the exact title. `investigate` produces the investigate title. Empty GTM and GA4 data produce no domain finding.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, `tests/AuditJobRunnerTest.php` (may skip locally; CI runs it), and `php tests/run.php` — expect PASS.
- [ ] **Step 5: Commit** `feat: flag domains marked not yours or under investigation in audits`

### Task 5: Domain map page

**Files:**
- Modify: `domain-map.php` (replace the stub)
- Create: `api/confirm-domain.php`, `api/add-domain.php`, `assets/js/domain-map.js`
- Modify: `assets/css/app.css` (append)
- Test: `tests/DomainMapPageTest.php`

**Interfaces:**
- `domain-map.php`, `$active_page = 'domain-map'`, with `client_id` from GET validated against the account (default: the first client).
  - Calls `DomainMap::refresh()` then `load()`.
  - Header: title "Domain map", subtitle "{client} · Confirm which websites belong to you. Audits flag anything you mark as not yours."
  - **Diagram card:** an inline `<svg>` in three columns, "GTM containers", "GA4 properties" and "Domains", with rounded-rect nodes and curved edges for each `edges` entry.
    - Domain node colour by confirmation: yours → success token, unconfirmed → warning token, not_yours/investigate → danger token.
    - A legend explains the colours. The SVG has `role="img"` and an `aria-label` summarising the counts; the table below carries the detail.
    - Up to 1280 px the SVG scales with `viewBox`; below 640 px it is hidden and the table becomes the only view.
  - **Domains card:** one row per domain showing the host, a source label ("From GA4 stream", "From site scan", "Added by you"), the current answer as a badge, and three buttons in a group labelled "Is this yours?": Yes / No / Investigate. The current answer is `aria-pressed="true"`.
  - **Add a domain** form: one URL/host field and an Add button.
  - Empty state when there's nothing to show: "Link a GA4 property on the Connections page or run a site scan to see your domains here."
- `POST api/confirm-domain.php` JSON `{csrf_token, client_id, domain_id, answer}` → `{success, domain: {id, confirmation}}`. `POST api/add-domain.php` `{csrf_token, client_id, url}` → `{success, domain_id}`. Both follow the Phase 1 endpoint pattern (`api_require_post`, `api_require_csrf`, `api_require_editor`, `assert_client_access`, int/enum validation → 400, `foreign_target` → 422, `invalid_host` → 422).
- `domain-map.js` updates the row badge, `aria-pressed` and the SVG node colour class in place after a successful answer; shows a `showToast` on error; builds DOM with `textContent` only.

- [ ] **Step 1: Write the failing test** (static): `domain-map.php` no longer contains `(stub)`, calls `require_auth(` and `DomainMap::refresh(`, contains `role="img"` and `Is this yours?`; both endpoints call `api_require_csrf(`, `api_require_editor(` and `assert_client_access(`; `domain-map.js` contains no `innerHTML`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, `php -l` on each PHP file and `node --check assets/js/domain-map.js` — expect PASS. The reviewer checks it signed in, at 1280 and 360 px.
- [ ] **Step 5: Commit** `feat: build the domain map with ownership confirmation`

### Task 6: Audit report PDF export

**Files:**
- Create: `src/AuditReportPdf.php`, `audit-report-pdf.php`
- Modify: `audit-report.php` (Export PDF button)
- Test: `tests/AuditReportPdfTest.php`

**Interfaces:**
- Produces `final class AuditReportPdf`:
  - `html(array $job, string $clientName, array $findings, array $coverageRows, bool $includeDeveloperNotes): string`: self-contained HTML with inline CSS only (no external assets). Contents, in order:
    - a header with TagVerity, the client, the date, and the audit `#id`;
    - the score, or "Score unavailable" plus the same sentence the web report uses for partial/failed;
    - the Data coverage rows (`AuditCoverageView::describe()` output);
    - findings grouped by severity (critical, warning, info), each with title, description, recommendation, fix owner and affected item, and developer notes only when `$includeDeveloperNotes`;
    - a footer "Generated by TagVerity on {date}".
    Every dynamic value goes through `htmlspecialchars`.
  - `render(...same args...): string`: PDF bytes via Dompdf (`isRemoteEnabled = false`, A4 portrait).
- `audit-report-pdf.php?id=N`: `require_auth`, `assert_audit_access`. Loads the same data as `audit-report.php`, including the client name and the coverage rows. Responds `Content-Type: application/pdf` with `Content-Disposition: attachment; filename="tagverity-audit-{id}-{Y-m-d}.pdf"`. Queued/running jobs → 409 with plain text "This audit hasn't finished yet."
- `audit-report.php`: the Export PDF button becomes a real link to `audit-report-pdf.php?id=` and shows on every plan. Developer notes inside the PDF stay plan-gated through `has_feature('developer_notes')`.

- [ ] **Step 1: Write failing tests:**
  - `html()` with a finding titled `<script>alert(1)</script> "quoted"` contains `&lt;script&gt;` and no `<script`.
  - Developer notes are absent when `$includeDeveloperNotes` is false and present when it is true.
  - A partial job shows "Score unavailable".
  - `render()` output starts with `%PDF-`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test and `php -l` on both PHP files — expect PASS. The reviewer downloads a PDF while signed in and opens it.
- [ ] **Step 5: Commit** `feat: export audit reports as PDF`

### Task 7: Honest Monitoring and BigQuery pages; docs

**Files:**
- Create: `monitoring.php`, `bigquery.php`
- Modify: `docs/PROJECT_STATUS.md`
- Test: `tests/ComingSoonPagesTest.php`

**Interfaces:**
- Both pages use `require_auth()`, the shared shell and sidebar (`$active_page` `monitoring` / `bigquery`), and one card containing:
  - a heading;
  - what the feature will do, in two or three plain sentences:
    - Monitoring: scheduled audits and alerts when tracking breaks.
    - BigQuery: deeper checks using your GA4 BigQuery export.
  - availability: "Included in the {plan names} plans" from `PLAN_FEATURES`. The plans are those where `monitoring` is not `'manual'`, or `bigquery` is true;
  - a status line: "Not available yet — we're building it. Your audits already cover GTM and GA4.";
  - a button back to the dashboard.

  No forms and no fake controls.
- `PROJECT_STATUS.md`:
  - Move the audit history, domain map, PDF export and account credits from "Incomplete or Stubbed Work" to "Implemented Functionality".
  - Note that Monitoring and BigQuery are honest placeholders.
  - Add a `### 2026-10-30 (Phase 2 — A2)` change-log entry and a decision-log row: "Audit credits are counted per account per month across all clients."

- [ ] **Step 1: Write failing tests** (static): both files exist, call `require_auth(`, contain no `<form`, and contain "Not available yet"; the sidebar's monitoring/bigquery hrefs point to files that exist on disk.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test and `php tests/run.php` — expect PASS.
- [ ] **Step 5: Commit** `feat: replace dead navigation links with honest monitoring and BigQuery pages`
