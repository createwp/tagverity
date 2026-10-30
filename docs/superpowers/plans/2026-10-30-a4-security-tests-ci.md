# A4 Security Hardening, Test Runner and CI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One command runs every test locally and in GitHub CI. Only intended files are reachable from the web. The preview sanitiser parses HTML like a browser does. Live-mapper sessions are cleaned up, and the live-mapper click acknowledgement can't be confused across clicks.

**Architecture:** A PHP runner executes the existing standalone test scripts as subprocesses. `.htaccess` switches to deny-by-default for non-public paths. `SitePreviewRenderer` swaps libxml for `masterminds/html5` (already installed via dompdf) and matches elements by local name so SVG/MathML-namespaced nodes are caught. A CLI cron sweeps expired `mapper_sessions`. The snippet and dashboard exchange a per-click `selection_id`.

**Tech Stack:** PHP 8.1, Apache `.htaccess` (mod_rewrite), `masterminds/html5` 2.10, Node 20 for JS tests, GitHub Actions.

**Spec:** A4 item in `docs/superpowers/plans/2026-10-30-enhancement-roadmap.md`, and the "Known Implementation Concerns" section of `docs/PROJECT_STATUS.md` (sanitiser parser differential, ack race, unswept `mapper_sessions`).

## Global Constraints

- Run PHP as `C:\wamp64\bin\php\php8.1.0\php.exe`; written below as `php`.
- A test passes by exiting 0 and fails by exiting non-zero. A test that prints a line containing `skipped` and exits 0 counts as SKIP.
- Must stay publicly reachable: root `*.php` pages, `api/*.php`, `assets/**`, `uploads/` stays denied (it already is). `run-migrations.php` remains reachable but key-protected.
- Must be denied (403): any path segment starting with `.` (e.g. `.git/`, `.env`, `.remember/`, `.agents/`, `.claude/`), `vendor/`, `tests/`, `docs/`, `logs/`, `storage/`, `sql/`, `src/`, `includes/`, `cron/`, `node_modules/`, `*.md`, `composer.json`, `composer.lock`.
- Do not change the live snippet's selector-generation logic.

## Review Focus

1. A namespaced `<svg><script>` survives today's `//script` XPath — must be removed. (Task 4 test)
2. `javascript:` hidden with tabs/newlines/entities inside `href` — must be removed. (Task 4 test)
3. Blocking `docs/` must not block `assets/` or `api/` URLs that happen to contain `docs` later in the path. (Task 3 test)
4. A stale acknowledgement for click #1 arriving after click #2 must not clear click #2's timer. (Task 6 test)
5. The runner must report FAIL (not crash) when a test fatals with a PHP parse error. (Task 1 test)

---

### Task 1: Test runner and `composer test`

**Files:**
- Create: `tests/run.php`, `tests/fixtures/runner/PassingTest.php`, `tests/fixtures/runner/FailingTest.php`, `tests/fixtures/runner/SkippedTest.php`, `tests/fixtures/runner/BrokenTest.php` (a deliberate parse error)
- Modify: `composer.json` (add `"scripts": {"test": "@php tests/run.php"}`)
- Test: `tests/RunnerTest.php`

**Interfaces:**
- `php tests/run.php [dir]` — `dir` defaults to `tests/`. Non-recursive: runs `*Test.php` with `PHP_BINARY` and `*Test.js` with `node`, each from the repo root, skipping `RunnerTest.php` when invoked on a fixtures dir. Prints one line per test: `PASS name`, `FAIL name` (followed by indented captured output), or `SKIP name`. Ends with `N passed, N failed, N skipped`. Exits 1 if any fail. If `node` isn't on PATH, JS tests become SKIP.

- [ ] **Step 1: Write the failing test** — `RunnerTest.php` runs `PHP_BINARY tests/run.php tests/fixtures/runner` via `proc_open`, then asserts exit code 1 and that the output contains `PASS PassingTest.php`, `FAIL FailingTest.php`, `FAIL BrokenTest.php`, `SKIP SkippedTest.php`, and `1 passed, 2 failed, 1 skipped`.
- [ ] **Step 2: Run** `php tests/RunnerTest.php` — expect FAIL.
- [ ] **Step 3: Implement** the runner, fixtures, and composer script. `BrokenTest.php` deliberately fails to parse; Task 2's lint step excludes `tests/fixtures/` for that reason.
- [ ] **Step 4: Run** `php tests/RunnerTest.php` — expect PASS. Then run `php tests/run.php` and record the baseline. Existing failures are reported to the reviewer, not fixed in this task.
- [ ] **Step 5: Commit** `test: add a single-command runner for standalone tests`

### Task 2: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`, `tests/ci/env.ci`

**Interfaces:**
- Workflow `CI` on `push` and `pull_request`. One job on `ubuntu-latest` with:
  - Service `mysql:8.0` (`MYSQL_DATABASE=tagverity_test`, `MYSQL_ROOT_PASSWORD=root`, health-check `mysqladmin ping`).
  - `shivammathur/setup-php@v2` with `php-version: '8.1'`, `extensions: pdo_mysql, mbstring, intl, curl, dom, openssl`; `actions/setup-node@v4` with `node-version: '20'`.
- Steps:
  1. `composer install --no-interaction --prefer-dist`
  2. `cp tests/ci/env.ci .env`, then append `TOKEN_ENCRYPTION_KEY=$(php -r 'echo base64_encode(random_bytes(32));')`
  3. Lint: `find . -name '*.php' -not -path './vendor/*' -not -path './tests/fixtures/*' -not -path './.claude/*' -print0 | xargs -0 -n1 php -l`
  4. `mysql -h127.0.0.1 -uroot -proot tagverity_test < sql/001_initial_schema.sql`
  5. `php run-migrations.php`
  6. `composer test`
- `tests/ci/env.ci`: `APP_ENV=testing`, `APP_NAME=TagVerity`, `APP_URL=http://localhost`, `DB_HOST=127.0.0.1`, `DB_NAME=tagverity_test`, `DB_USER=root`, `DB_PASS=root`, every other key from `.env.example` present and empty.

- [ ] **Step 1: Validate locally** — `npx --yes yaml-lint .github/workflows/ci.yml` passes. Simulate steps 3, 5, and 6 locally against the WAMP database — expect zero lint errors, migrations `"ok":true`, and the runner's baseline result.
- [ ] **Step 2: Commit** `ci: run lint, migrations and tests on GitHub Actions`
- [ ] **Step 3:** Report to the reviewer that the first real CI run happens on push. Pushing is the product owner's decision; do not push.

### Task 3: Deny-by-default web access

**Files:**
- Modify: `.htaccess`
- Create: `tests/HtaccessRulesTest.php`

**Interfaces:**
- `.htaccess` keeps `Options -Indexes` and the security headers. The rewrite block becomes:
  - `RewriteRule (^|/)\. - [F,L]`
  - `RewriteRule ^(vendor|tests|docs|logs|storage|sql|src|includes|cron|node_modules)(/|$) - [F,L]`
  - `RewriteRule \.md$ - [F,L]`
  - `RewriteRule ^composer\.(json|lock)$ - [F,L]`
- Keep the `.env` `FilesMatch` block.

- [ ] **Step 1: Write the failing test** — parse every `RewriteRule <pattern> - [F` line from `.htaccess`, then test each pattern with `preg_match('#'.$pattern.'#', $path)` against relative paths:
  - Denied: `.git/config`, `.env`, `.remember/now.md`, `.claude/settings.json`, `vendor/autoload.php`, `tests/RunnerTest.php`, `docs/PROJECT_STATUS.md`, `logs/app.log`, `storage/reports/a.pdf`, `sql/001_initial_schema.sql`, `src/AuditEngine.php`, `includes/config.php`, `cron/run-audits.php`, `composer.lock`, `design-qa.md`.
  - Allowed (no pattern matches): `dashboard.php`, `api/job-status.php`, `assets/css/app.css`, `assets/js/mapper-snippet.js`, `run-migrations.php`, `api/docs-helper.php`.
- [ ] **Step 2: Run** `php tests/HtaccessRulesTest.php` — expect FAIL.
- [ ] **Step 3: Implement** the `.htaccess` change.
- [ ] **Step 4: Run** the test — expect PASS. If Apache is running, also `curl -s -o /dev/null -w "%{http_code}" http://localhost/gtm-auditor/.git/config` → `403` and `.../dashboard.php` → `302` or `200`.
- [ ] **Step 5: Commit** `security: deny web access to everything except pages, API and assets`

### Task 4: HTML5-aware preview sanitiser

**Files:**
- Modify: `composer.json` (require `"masterminds/html5": "^2.10"`), `src/SitePreviewRenderer.php`
- Create: `tests/SitePreviewRendererTest.php`

**Interfaces:**
- `render(string $html, string $pageUrl): string` — signature unchanged.
- Parse with `(new \Masterminds\HTML5(['disable_html_ns' => true]))->loadHTML($html)` and serialise with that same instance's `saveHTML($dom)`.
- Remove by **local name**, case-insensitively, wherever they appear (including inside `svg`/`math`): `script, iframe, frame, frameset, object, embed, applet, noscript, base, template, portal, math`, `meta[http-equiv=refresh]`, and inside `svg`: `foreignObject`, `animate`, `set`, `handler`, `listener`.
- Attribute rules: drop every `on*` attribute plus `srcdoc`, `formaction`, `ping`, `target`, `xmlns:xlink`. Before the URL check, normalise URL-bearing attributes (`href, src, xlink:href, action, data, poster, background, srcset`) by removing ASCII whitespace and control characters (`/[\x00-\x20]/`). Then drop the attribute if the value starts with `javascript:`, `vbscript:`, `file:`, or `data:` (allow `data:image/` only on `img src`). Drop `style` attributes containing `expression(`, `url(javascript`, or `-moz-binding`.
- Inject `<base>`, referrer `<meta>`, preview `<style>`, banner, and the existing preview `<script>` exactly as today.

- [ ] **Step 1: Write failing tests.** For each payload, run `render($payload, 'https://example.com/')`, re-parse the output with Masterminds, and assert:
  - (a) exactly one `script` element by local name, and its text contains `tagverity`;
  - (b) no element has an attribute whose name starts with `on`;
  - (c) no `href`/`src` value, after removing `[\x00-\x20]`, starts with `javascript:`.

  Payloads:
  - `<svg><script>alert(1)</script></svg>`
  - `<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>`
  - `<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>`
  - `<a href=" jav&#x09;ascript:alert(1)">x</a>`
  - `<a href="java\nscript:alert(1)">x</a>` (literal newline)
  - `<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>`
  - `<iframe srcdoc="<script>alert(1)</script>"></iframe>`
  - `<template><img src=x onerror=alert(1)></template>`

  Positive: `<button id="book" class="cta">Book now</button>` keeps `id="book"`, `class="cta"`, and its text; output contains `<base href="https://example.com/">` once.
- [ ] **Step 2: Run** `php tests/SitePreviewRendererTest.php` — expect FAIL on at least the svg/noscript cases.
- [ ] **Step 3: Implement.** Run `composer update masterminds/html5 --no-interaction` so `composer.lock` records the direct requirement.
- [ ] **Step 4: Run** the test — expect PASS. Run `php tests/run.php` — no new failures versus the Task 1 baseline.
- [ ] **Step 5:** Remove the libxml-sanitiser concern from `docs/PROJECT_STATUS.md` Known Implementation Concerns and add a change-log line. The `sandbox allow-scripts` CSP in `preview-site.php` stays as defence in depth.
- [ ] **Step 6: Commit** `security: sanitise previews with an HTML5 parser and namespace-aware rules`

### Task 5: Sweep expired live-mapper sessions

**Files:**
- Modify: `src/MapperSessionManager.php`
- Create: `cron/sweep-mapper-sessions.php`
- Test: `tests/MapperSessionSweepTest.php`

**Interfaces:**
- Produces `public function purgeExpired(\PDO $pdo, int $graceSeconds = 3600): int` — `DELETE FROM mapper_sessions WHERE expires_at < (NOW() - INTERVAL ? SECOND)` and returns the number of rows deleted.
- `cron/sweep-mapper-sessions.php` exits unless `PHP_SAPI === 'cli'` (same guard as `cron/run-audits.php`), calls `purgeExpired`, and prints `Purged N expired mapper sessions.`. Document the cron line (`0 * * * *`) in a header comment.

- [ ] **Step 1: Write the failing test** (DB-backed): insert three sessions for a temp client/scan/user with `expires_at` = now − 2h, now − 10min, and now + 10min. `purgeExpired($pdo, 3600)` returns 1 and two rows remain. Clean up in `finally`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test and `php cron/sweep-mapper-sessions.php` — expect PASS / a "Purged" line.
- [ ] **Step 5: Commit** `feat: purge expired live mapper sessions from an hourly cron`

### Task 6: Per-click acknowledgement correlation

**Files:**
- Modify: `assets/js/mapper-snippet.js` (around lines 200–290), `assets/js/element-mapper.js:290-300`, `docs/PROJECT_STATUS.md`
- Test: `tests/MapperSnippetAckTest.js`

**Interfaces:**
- `window.TagVerityMapperSnippet` gains `isMatchingAck(pendingSelectionId, data) -> boolean`: true only when `data.type === 'element_selected_ack'` and `data.selection_id === pendingSelectionId`, and `pendingSelectionId` is a non-empty string.
- On each click the snippet generates `selection_id` (`crypto.getRandomValues`-based hex, 16 chars), stores it as `pendingSelectionId`, and sends it at the top level of the `element_selected` message. The ack listener clears the timer only if `isMatchingAck(...)`.
- `element-mapper.js` echoes `selection_id: event.data.selection_id` in its ack.

- [ ] **Step 1: Write the failing test** (same bootstrap as `tests/MapperSnippetSelectorTest.js`):
  - `isMatchingAck('a1', {type:'element_selected_ack', selection_id:'a1'}) === true`
  - `isMatchingAck('b2', {type:'element_selected_ack', selection_id:'a1'}) === false`
  - `isMatchingAck('', {type:'element_selected_ack', selection_id:''}) === false`
  - `isMatchingAck('a1', {type:'other', selection_id:'a1'}) === false`
- [ ] **Step 2: Run** `node tests/MapperSnippetAckTest.js` — expect FAIL.
- [ ] **Step 3: Implement** both JS changes, then remove the ack-race and unswept-table items from Known Implementation Concerns in `PROJECT_STATUS.md`.
- [ ] **Step 4: Run** `node tests/MapperSnippetAckTest.js`, `node tests/MapperSnippetSelectorTest.js`, and `php tests/run.php` — expect PASS.
- [ ] **Step 5: Commit** `fix: correlate live mapper acknowledgements with the click that sent them`
