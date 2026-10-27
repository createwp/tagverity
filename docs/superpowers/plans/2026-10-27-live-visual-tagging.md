# Live Visual Tagging Toolbar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user open a point-and-click overlay on their real, live website (not a static snapshot) to pick a page element and hand it back to the existing Tags & triggers / element-mapper review-and-save flow, unchanged.

**Architecture:** A small, dormant JS snippet pasted once into the client's site renders an authenticated overlay only when summoned with a short-lived, domain-scoped token minted by the dashboard. The overlay reuses the static mapper's existing selector-generation logic verbatim. When the user clicks an element, the live tab hands the captured payload back to the dashboard tab via `window.opener.postMessage` — the exact mechanism the static iframe mapper already uses — so the existing review form and `api/save-element-mapping.php` persist it with no new save path. The only backend additions are session-token issuance/validation and a snippet-presence check; the only behavior change to existing code is relaxing `save-element-mapping.php`'s page validation from "must be one of the ≤8 scanned pages" to "must be on the scanned site's domain," since the live mapper's whole value is covering pages the bounded scan never crawled.

**Tech Stack:** PHP 8.1 + PDO/MySQL (no framework), vanilla JS + `fetch()`, the project's existing hand-rolled test scripts (`php tests/X.php`, no PHPUnit; Node-stubbed browser tests like `tests/IdentifierProcessorBrowserTest.js`).

**Spec:** [`docs/superpowers/specs/2026-10-27-live-visual-tagging-design.md`](../specs/2026-10-27-live-visual-tagging-design.md)

## Global Constraints

- PDO prepared statements for every variable SQL value (project-wide rule).
- `htmlspecialchars()` on any user-controlled value echoed into HTML.
- Every new authenticated endpoint requires `require_auth()` + `assert_client_access($clientId)`; every new state-changing same-origin endpoint requires `api_require_csrf($input)`.
- The two new cross-origin endpoints (`exchange-live-mapper-session.php`) cannot use PHP session cookies or CSRF tokens for auth (the caller is the client's own site, a different origin, in a fresh tab) — they authenticate purely via the random, hashed, short-lived session token.
- No customer Google/analytics credential or token is ever read, stored, or passed through any new file in this plan.
- Token storage never stores the raw token — only `hash('sha256', $token)` — matching how the project already treats secrets at rest.
- New migrations are appended to `run-migrations.php` as the next numbered migration (012), following the existing idempotent-`ALTER`/`CREATE TABLE IF NOT EXISTS` pattern — there is no separate migration tool or directory in this project.
- The live mapper's domain-eligibility check matches the client's self-reported `site_scans.root_url`, not a new ownership-verification mechanism (per the approved design, "Decided" section).
- Apply the project's existing rate-limit helper (`includes/rate-limit.php`) to `exchange-live-mapper-session.php`, keyed by IP, consistent with its use on other unauthenticated-by-cookie endpoints (login, registration).

## Review Focus

- A token opened in the live tab after it has expired must show a clear "expired, reopen from TagVerity" message, not a silent failure or a half-rendered overlay. → Task 7, Step: exchange-failure rendering test.
- `window.open()` can return `null` when the browser's popup blocker fires; the dashboard must say so, not fail silently. → Task 8, Step: popup-blocked handling.
- A token presented from an origin that does not match the token's own `site_scans.root_url` host must be rejected (403 `origin_mismatch`), even though the token itself is valid and unexpired. → Task 3, Step: origin-mismatch test.
- A garbage/unknown token string must be rejected the same way as an expired one (403 `invalid_or_expired_token`), without leaking whether the token ever existed. → Task 3, Step: unknown-token test.
- Opening two live-mapper sessions for the same client back to back must not invalidate the first session before its own expiry — each token is an independent row. → Task 3, Step: concurrent-sessions test.

---

### Task 1: Schema — `mapper_sessions` table and snippet-detection column

**Files:**
- Modify: `run-migrations.php` (append migration 012 after the existing migration 011 block, before the final `echo json_encode(...)` line)

**Interfaces:**
- Produces: table `mapper_sessions(id, client_id, site_scan_id, created_by_user_id, token_hash, expires_at, created_at)`; column `site_scans.mapper_snippet_detected_at DATETIME NULL`.

- [ ] **Step 1: Add migration 012 to `run-migrations.php`**

```php
// Migration 012: Live visual tagging sessions
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute(['site_scans', 'mapper_snippet_detected_at']);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE site_scans ADD COLUMN mapper_snippet_detected_at DATETIME NULL AFTER completed_at");
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mapper_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            site_scan_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mapper_sessions_token_hash (token_hash),
            INDEX idx_mapper_sessions_expiry (expires_at),
            FOREIGN KEY (client_id) REFERENCES clients(id),
            FOREIGN KEY (site_scan_id) REFERENCES site_scans(id),
            FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $results[] = ['label' => '012 - live visual tagging sessions', 'ok' => true, 'msg' => 'OK'];
} catch (PDOException $e) {
    error_log('Migration 012 failed: ' . $e->getMessage());
    $results[] = ['label' => '012 - live visual tagging sessions', 'ok' => false, 'msg' => $e->getMessage()];
}
```

- [ ] **Step 2: Run the migration twice to confirm idempotency**

Run: `C:\wamp64\bin\php\php8.1.0\php.exe run-migrations.php` (CLI mode skips the key check), twice in a row.
Expected: both runs report `"012 - live visual tagging sessions"` with `"ok": true`.

- [ ] **Step 3: Commit**

```bash
git add run-migrations.php
git commit -m "feat: add mapper_sessions table and snippet-detection column"
```

---

### Task 2: `MapperSnippetDetector` — pure snippet-presence check

**Files:**
- Create: `src/MapperSnippetDetector.php`
- Test: `tests/MapperSnippetDetectorTest.php`

**Interfaces:**
- Produces: `GTMAuditor\MapperSnippetDetector::detect(string $html): bool` — returns `true` iff `$html` contains the literal substring `assets/js/mapper-snippet.js`.
- Consumes (by Task 4): this method, with HTML fetched by `SiteScanner::fetchPreviewPage()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
require_once __DIR__ . '/../src/MapperSnippetDetector.php';
use GTMAuditor\MapperSnippetDetector;

function assert_detector($expected, $actual, string $label): void {
    if ($expected !== $actual) { fwrite(STDERR, $label . " failed\n"); exit(1); }
}

$detector = new MapperSnippetDetector();
assert_detector(true, $detector->detect('<head><script src="https://app.tagverity.com/assets/js/mapper-snippet.js" data-tagverity-api="https://app.tagverity.com" async></script></head>'), 'Detects installed snippet');
assert_detector(false, $detector->detect('<head><script src="https://app.tagverity.com/assets/js/app.js"></script></head>'), 'Does not false-positive on unrelated script');
echo "MapperSnippetDetector tests passed.\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\wamp64\bin\php\php8.1.0\php.exe tests\MapperSnippetDetectorTest.php`
Expected: fatal error, class not found.

- [ ] **Step 3: Implement `MapperSnippetDetector::detect(string $html): bool` in `src/MapperSnippetDetector.php`**

A one-line `str_contains($html, 'assets/js/mapper-snippet.js')`, namespaced `GTMAuditor`.

- [ ] **Step 4: Run test to verify it passes**

Run: `C:\wamp64\bin\php\php8.1.0\php.exe tests\MapperSnippetDetectorTest.php`
Expected: `MapperSnippetDetector tests passed.`

- [ ] **Step 5: Commit**

```bash
git add src/MapperSnippetDetector.php tests/MapperSnippetDetectorTest.php
git commit -m "feat: add snippet-presence detector"
```

---

### Task 3: `MapperSessionManager` — token issuance and validation

**Files:**
- Create: `src/MapperSessionManager.php`
- Test: `tests/MapperSessionManagerTest.php`

**Interfaces:**
- Produces:
  - `GTMAuditor\MapperSessionManager::createToken(PDO $pdo, int $clientId, int $siteScanId, int $userId, int $ttlSeconds = 1800): array` → `['token' => string, 'expires_at' => string]` (`token` is the only time the raw value exists; only its SHA-256 hash is stored; `expires_at` is computed from PHP's own clock, never MySQL's `NOW()`, so the comparison in `validateToken` stays correct regardless of the DB server's timezone configuration).
  - `GTMAuditor\MapperSessionManager::validateToken(PDO $pdo, string $token, string $origin): array` → `['client_id' => int, 'site_scan_id' => int, 'root_url' => string, 'expires_at' => string]` on success. `$origin` is the full origin exactly as a browser's `Origin:` request header sends it (scheme + host + optional non-default port, e.g. `https://example.com`), not a bare hostname. Throws `DomainException` with message `invalid_or_expired_token` (unknown hash, or `expires_at <= NOW()`) or `origin_mismatch` (hash found and unexpired, but the normalized origin built from `$row['root_url']` — lowercase scheme/host, port omitted only when default for that scheme — does not case-insensitively match `$origin`). **Tokens are single-use:** on a successful validation only, the row is deleted (`DELETE FROM mapper_sessions WHERE id = ?`) before returning, so the same token can never be redeemed twice. A failed validation (unknown, expired, or origin-mismatched) never deletes the row, so a caller that fails once from the wrong origin can still redeem the token correctly afterward.
- Consumes (by Tasks 5 and 6): both methods.

- [ ] **Step 1: Write the failing tests**

```php
<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../src/MapperSessionManager.php';
use GTMAuditor\MapperSessionManager;

$pdo = db();
$scan = $pdo->query("SELECT id, client_id, root_url FROM site_scans WHERE status = 'complete' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$scan) { echo "MapperSessionManager tests skipped: no completed site scan.\n"; exit(0); }
$user = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$manager = new MapperSessionManager();
$rootHost = parse_url($scan['root_url'], PHP_URL_HOST);

// 1. Happy path
$issued = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
$validated = $manager->validateToken($pdo, $issued['token'], $rootHost);
if ($validated['client_id'] !== (int)$scan['client_id']) { fwrite(STDERR, "Happy path failed\n"); exit(1); }

// 2. Unknown token
try {
    $manager->validateToken($pdo, 'not-a-real-token', $rootHost);
    fwrite(STDERR, "Unknown token should have thrown\n"); exit(1);
} catch (DomainException $e) {
    if ($e->getMessage() !== 'invalid_or_expired_token') { fwrite(STDERR, "Wrong message for unknown token\n"); exit(1); }
}

// 3. Origin mismatch
$issuedTwo = $manager->createToken($pdo, (int)$scan['client_id'], (int)$scan['id'], (int)$user['id']);
try {
    $manager->validateToken($pdo, $issuedTwo['token'], 'attacker.example');
    fwrite(STDERR, "Origin mismatch should have thrown\n"); exit(1);
} catch (DomainException $e) {
    if ($e->getMessage() !== 'origin_mismatch') { fwrite(STDERR, "Wrong message for origin mismatch\n"); exit(1); }
}

// 4. Two concurrent sessions are independent
$manager->validateToken($pdo, $issued['token'], $rootHost); // first token still valid after a second was issued

// 5. Expired token
$pdo->prepare("UPDATE mapper_sessions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = ?")
    ->execute([hash('sha256', $issued['token'])]);
try {
    $manager->validateToken($pdo, $issued['token'], $rootHost);
    fwrite(STDERR, "Expired token should have thrown\n"); exit(1);
} catch (DomainException $e) {
    if ($e->getMessage() !== 'invalid_or_expired_token') { fwrite(STDERR, "Wrong message for expired token\n"); exit(1); }
}

echo "MapperSessionManager tests passed.\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\wamp64\bin\php\php8.1.0\php.exe tests\MapperSessionManagerTest.php`
Expected: fatal error, class not found (or skip message if the local DB has no completed scan yet — if so, complete one manually first).

- [ ] **Step 3: Implement `MapperSessionManager` in `src/MapperSessionManager.php`**

`createToken`: `$token = bin2hex(random_bytes(32))`; insert a row with `token_hash = hash('sha256', $token)` and `expires_at = (new DateTime())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s')`; return the raw token and formatted `expires_at`.
`validateToken`: `SELECT ms.client_id, ms.site_scan_id, ms.expires_at, ss.root_url FROM mapper_sessions ms JOIN site_scans ss ON ss.id = ms.site_scan_id WHERE ms.token_hash = ?`; if no row or `expires_at <= NOW()` (compare as DateTime, not a second SQL round trip), throw `invalid_or_expired_token`; else if `parse_url($row['root_url'], PHP_URL_HOST) !== $originHost`, throw `origin_mismatch`; else return the array.

- [ ] **Step 4: Run test to verify it passes**

Run: `C:\wamp64\bin\php\php8.1.0\php.exe tests\MapperSessionManagerTest.php`
Expected: `MapperSessionManager tests passed.` (or the skip message, acceptable if no completed scan exists locally).

- [ ] **Step 5: Commit**

```bash
git add src/MapperSessionManager.php tests/MapperSessionManagerTest.php
git commit -m "feat: add live mapper session token issuance and validation"
```

---

### Task 4: `api/check-mapper-snippet.php`

**Files:**
- Create: `api/check-mapper-snippet.php`

**Interfaces:**
- Consumes: `MapperSnippetDetector::detect()` (Task 2); `SiteScanner::fetchPreviewPage(string $url): array` (existing, returns `['body' => string, 'url' => string]` per its use in `preview-site.php`).
- Produces: `POST api/check-mapper-snippet.php` body `{client_id, csrf_token}` → `{ok:true, data:{detected: bool, detected_at: string|null}}`, or `{error: 'measurement_plan_required'}` (409) / `{error: 'snippet_check_failed'}` (422).

- [ ] **Step 1: Implement the endpoint**

Mirror the auth header of `api/save-element-mapping.php`: `require_auth()`, `api_require_post()`, `api_require_editor($user)`, `api_require_csrf($input)`, `assert_client_access($clientId)`. Look up the client's latest `site_scans` row with `status = 'complete'` (same join shape as `preview-site.php`); if none, respond `measurement_plan_required` (409). Fetch its `root_url` via `(new SiteScanner(1, 1000000, 6, SITE_SCAN_CA_BUNDLE))->fetchPreviewPage($rootUrl)` inside a `try/catch`, catching `Throwable` into `snippet_check_failed` (422). Run `(new MapperSnippetDetector())->detect($response['body'])`; if `true`, `UPDATE site_scans SET mapper_snippet_detected_at = NOW() WHERE id = ?`. Respond with the detected flag and the (possibly just-updated) `mapper_snippet_detected_at`.

- [ ] **Step 2: Manual verification**

With the local app server and MySQL running: log in, POST this endpoint for a client with a completed scan whose site does not yet have the snippet installed (expect `detected: false`); add the `<script>` tag (see Task 7's exact markup) to a locally-servable test page at that scan's `root_url`, POST again (expect `detected: true` and `site_scans.mapper_snippet_detected_at` set).

- [ ] **Step 3: Commit**

```bash
git add api/check-mapper-snippet.php
git commit -m "feat: add server-side mapper snippet detection endpoint"
```

---

### Task 5: `api/create-live-mapper-session.php`

**Files:**
- Create: `api/create-live-mapper-session.php`

**Interfaces:**
- Consumes: `MapperSessionManager::createToken()` (Task 3).
- Produces: `POST api/create-live-mapper-session.php` body `{client_id, csrf_token}` → `{ok:true, data:{token, root_url, expires_at}}`, or `{error:'measurement_plan_required'}` (409) / `{error:'snippet_not_detected'}` (409).

- [ ] **Step 1: Implement the endpoint**

Same auth header as Task 4. Look up the client's latest completed `site_scans` row (`id, root_url, mapper_snippet_detected_at`); 409 `measurement_plan_required` if none; 409 `snippet_not_detected` if `mapper_snippet_detected_at IS NULL`. Call `(new MapperSessionManager())->createToken($pdo, $clientId, (int)$scan['id'], (int)$user['id'])`. Respond `{ok:true, data:{token, root_url: $scan['root_url'], expires_at}}`.

- [ ] **Step 2: Manual verification**

POST with a client whose scan has `mapper_snippet_detected_at` set (from Task 4's test); confirm a `token` and `root_url` come back, and that POSTing with a client whose scan lacks the snippet returns `snippet_not_detected`.

- [ ] **Step 3: Commit**

```bash
git add api/create-live-mapper-session.php
git commit -m "feat: add endpoint to mint a live mapper session token"
```

---

### Task 6: `api/exchange-live-mapper-session.php` (cross-origin)

**Files:**
- Create: `api/exchange-live-mapper-session.php`

**Interfaces:**
- Consumes: `MapperSessionManager::validateToken()` (Task 3); `includes/rate-limit.php`'s existing rate-limit helper (same function signature it already exposes to `login.php`/`register.php`).
- Produces: `OPTIONS`/`POST api/exchange-live-mapper-session.php`, called cross-origin from the client's own site (no cookies, no CSRF token). `POST` body `{token}` → `{ok:true, data:{client_id, site_scan_id, root_url, expires_at}}` (200) or `{error:'invalid_or_expired_token'|'origin_mismatch'|'missing_token'}` (403/400). Always answers `OPTIONS` with 204 and reflects the request's `Origin` header plus `Access-Control-Allow-Methods: POST, OPTIONS` and `Access-Control-Allow-Headers: Content-Type`.

- [ ] **Step 1: Implement the endpoint**

No `require_once includes/auth.php` — this is intentionally not session-authenticated. `require_once includes/config.php`, `includes/db.php`, `includes/rate-limit.php`, `src/MapperSessionManager.php`. On every request (including `OPTIONS`), read `$origin = $_SERVER['HTTP_ORIGIN'] ?? ''` and set `header('Access-Control-Allow-Origin: ' . $origin)` plus `Vary: Origin`. If `$_SERVER['REQUEST_METHOD'] === 'OPTIONS'`, also set the Allow-Methods/Allow-Headers above and `http_response_code(204)`, then `exit`. Otherwise require `POST`; apply the rate-limit check `rate_limit('live_mapper_exchange', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 30, 60)` (exact call per the ledger's ruling — this function exits and responds internally when the limit is hit, matching `login.php`'s usage); decode the JSON body; if `token` is empty, 400 `missing_token`; call `validateToken($pdo, $token, $origin)` — passing the raw `Origin` header value directly, since `validateToken` now does its own full-origin normalization — inside a `try/catch(DomainException $e)`, responding 403 with `['error' => $e->getMessage()]` on catch, else 200 with the returned array as `data`.

- [ ] **Step 2: Manual verification**

From a browser console on any page (e.g. `about:blank` won't work for `fetch` CORS purposes — use a second localhost vhost or `http://127.0.0.1`), `fetch` this endpoint with a valid token and a mismatched `Origin`; confirm `origin_mismatch`. Repeat from the token's real root URL's origin; confirm success. Repeat with an expired token (reuse Task 3's expiry trick directly in the DB); confirm `invalid_or_expired_token`.

- [ ] **Step 3: Commit**

```bash
git add api/exchange-live-mapper-session.php
git commit -m "feat: add cross-origin live mapper session exchange endpoint"
```

---

### Task 7: `assets/js/mapper-snippet.js` — the dormant overlay snippet

**Files:**
- Create: `assets/js/mapper-snippet.js`
- Test: `tests/MapperSnippetSelectorTest.js` (Node-stubbed, mirrors `tests/IdentifierProcessorBrowserTest.js`'s pattern)

**Interfaces:**
- Consumes: `api/exchange-live-mapper-session.php` (Task 6).
- Produces: a global `window.TagVerityMapperSnippet = { selectorFor, payloadFor }` for testability; at runtime, on a page that embeds the script with a `#__tagverity_mapper=<token>` URL hash present, renders an overlay and eventually calls `window.opener.postMessage({source: 'tagverity-live-mapper', type: 'element_selected', payload: {...payloadFor(element), page_url: location.href}}, apiBase)`, where `payload` has the exact same keys `SitePreviewRenderer::previewScript()`'s `payloadFor()` already produces (`selector, tag, text, id, class_name, href, type, name, field_label, autocomplete, form_id, form_class_name, form_name, form_selector, aria_label, placeholder, suggested_name, recommended_intent`), consumed by Task 8's `selectElement()`.

- [ ] **Step 1: Write the failing test**

```js
'use strict';
global.document = { currentScript: { dataset: {} }, createElement: function () { return {}; } };
global.window = {};
require('../assets/js/mapper-snippet.js');
const lib = window.TagVerityMapperSnippet;
const fakeElement = {tagName: 'BUTTON', id: '', className: 'book-consultation', textContent: 'Book now', attributes: [], getAttribute: function () { return null; }, closest: function () { return null; }, classList: {contains: function () { return false; }}};
const selector = lib.selectorFor(fakeElement);
if (typeof selector !== 'string' || selector.indexOf('book-consultation') === -1) {
  console.error('selectorFor did not produce a class-based selector: ' + selector);
  process.exit(1);
}
console.log('MapperSnippet selector tests passed.');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests\MapperSnippetSelectorTest.js`
Expected: error, `mapper-snippet.js` not found or `window.TagVerityMapperSnippet` undefined.

- [ ] **Step 3: Implement `assets/js/mapper-snippet.js`**

Port `escapeCss`, `stableValue`, `usefulClasses`, `segment`, `selectorFor`, `cleanText`, `suggestedName`, `recommendedIntent`, `labelFor`, `payloadFor` from `src/SitePreviewRenderer.php`'s `previewScript()` verbatim (same bodies — this is a direct copy, not a rewrite), assigning them to `window.TagVerityMapperSnippet`. Add the new, snippet-specific logic:
- Read `apiBase = (document.currentScript && document.currentScript.dataset.tagverityApi) || ''`.
- Parse `location.hash` for `__tagverity_mapper=(.+)`; if absent, stop here (dormant — no DOM listeners, no network calls).
- If present: `history.replaceState(null, '', location.pathname + location.search)`; `fetch(apiBase + '/api/exchange-live-mapper-session.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({token})})`.
- On a non-ok or `error` response: inject a small fixed banner reading "This TagVerity mapping link is invalid or has expired. Return to TagVerity and open the live mapper again." and stop (no listeners attached).
- On success: inject the same hover/selected outline CSS as `SitePreviewRenderer::previewCss()` (reuse the two class names and colours verbatim for visual continuity) plus a floating banner; attach the same `mouseover`/`mouseout`/`click`/`submit`-prevention listeners `previewScript()` already has, except the `click` handler calls `window.opener && window.opener.postMessage({source: 'tagverity-live-mapper', type: 'element_selected', payload: Object.assign({}, payloadFor(target), {page_url: location.href})}, apiBase)` and shows a small inline confirmation ("Sent to TagVerity — switch tabs to confirm") instead of using `window.parent.postMessage`. Set a `setTimeout` for the session's remaining TTL (compute from the exchange response's `expires_at`) that removes the overlay and listeners and shows an "This session has expired" banner.

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests\MapperSnippetSelectorTest.js`
Expected: `MapperSnippet selector tests passed.`

- [ ] **Step 5: Manual verification**

Serve a static HTML page locally with `<script src="http://localhost/gtm-auditor/assets/js/mapper-snippet.js" data-tagverity-api="http://localhost/gtm-auditor"></script>` in its `<head>`. Load it directly (no hash) — confirm no overlay, no console errors (dormant). Load it with `#__tagverity_mapper=<a token from Task 5>` appended — confirm the overlay renders, hovering highlights elements, and clicking one triggers the `postMessage` (check via `window.opener` in a real opener/opened-tab pair, or temporarily log the payload to console for a quick check).

- [ ] **Step 6: Commit**

```bash
git add assets/js/mapper-snippet.js tests/MapperSnippetSelectorTest.js
git commit -m "feat: add live visual tagging overlay snippet"
```

---

### Task 8: Wire up the dashboard — `element-mapper.php`, `element-mapper.js`, and the save-path domain check

**Files:**
- Modify: `element-mapper.php` (add the live-mapper UI block)
- Modify: `assets/js/element-mapper.js` (add snippet-check/open-live-mapper/postMessage-listener logic)
- Modify: `api/save-element-mapping.php` (relax page validation to same-host-as-scan-root instead of scanned-pages membership)

**Interfaces:**
- Consumes: `api/check-mapper-snippet.php` (Task 4), `api/create-live-mapper-session.php` (Task 5), the `tagverity-live-mapper` / `element_selected` message shape produced by Task 7, and the existing `selectElement(payload, mappingId)` / `compactUrl(url)` functions already defined in `assets/js/element-mapper.js`.
- Produces: no new interfaces for later tasks — this is the last task in the plan.

- [ ] **Step 1: Add the live-mapper UI block to `element-mapper.php`**

Inside the `mapper-toolbar` card (or immediately after it), add a second card rendered from PHP, carrying on `#element-mapper-app`: `data-snippet-detected="<?= $profile && !empty($profile['mapper_snippet_detected_at']) ? '1' : '0' ?>"` and `data-root-url="<?= htmlspecialchars($scanRootUrl ?? '', ENT_QUOTES, 'UTF-8') ?>"` (fetch `ss.root_url` and `ss.mapper_snippet_detected_at` alongside the existing `$profile` query at the top of the file). Render two mutually-exclusive sections keyed on that flag: (a) not detected — an install snippet shown as literal text, `<code>&lt;script src="<?= htmlspecialchars(APP_URL) ?>/assets/js/mapper-snippet.js" data-tagverity-api="<?= htmlspecialchars(APP_URL) ?>" async&gt;&lt;/script&gt;</code>`, plus a `#mapper-check-snippet-button` button; (b) detected — a `#mapper-open-live-button` button.

- [ ] **Step 2: Add the snippet-check handler to `assets/js/element-mapper.js`**

```js
document.getElementById('mapper-check-snippet-button')?.addEventListener('click', async function () {
  try {
    const data = await request('api/check-mapper-snippet.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({client_id: state.clientId, csrf_token: csrfToken()})});
    if (data.detected) { showToast('Snippet detected — reloading.', 'success'); location.reload(); }
    else showToast('Snippet not found yet. Double-check it is pasted in <head> and try again.', 'error');
  } catch (error) { showToast(error.message, 'error'); }
});
```

- [ ] **Step 3: Add the open-live-mapper handler and message listener**

```js
document.getElementById('mapper-open-live-button')?.addEventListener('click', async function () {
  try {
    const data = await request('api/create-live-mapper-session.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({client_id: state.clientId, csrf_token: csrfToken()})});
    const liveWindow = window.open(data.root_url + '#__tagverity_mapper=' + encodeURIComponent(data.token));
    if (!liveWindow) { showToast('Allow pop-ups for this site to open the live mapper.', 'error'); return; }
    state.liveMapperWindow = liveWindow;
    state.liveMapperOrigin = new URL(data.root_url).origin;
    state.liveMapperPollId = setInterval(function () {
      if (state.liveMapperWindow.closed) { clearInterval(state.liveMapperPollId); return; }
      loadMappings();
    }, 4000);
  } catch (error) { showToast(error.message, 'error'); }
});

window.addEventListener('message', function (event) {
  if (!state.liveMapperWindow || event.source !== state.liveMapperWindow || event.origin !== state.liveMapperOrigin) return;
  if (!event.data || event.data.source !== 'tagverity-live-mapper' || event.data.type !== 'element_selected') return;
  const payload = event.data.payload;
  if (!pageSelect.querySelector('option[value="' + CSS.escape(payload.page_url) + '"]')) {
    const option = new Option(compactUrl(payload.page_url), payload.page_url);
    pageSelect.add(option);
  }
  pageSelect.value = payload.page_url;
  selectElement(payload, null);
  showToast('Element received from the live site. Confirm what you want to measure.', 'info');
});
```

- [ ] **Step 4: Relax `api/save-element-mapping.php`'s page validation**

Add `ss.root_url` to the existing `SELECT ... FROM onboarding_profiles op LEFT JOIN site_scans ss ...` query near the top of the file. Replace the `$allowedPages` membership check with: `$allowedHost = parse_url($row['root_url'] ?? '', PHP_URL_HOST); $submittedHost = parse_url($pageUrl, PHP_URL_HOST); if ($pageUrl === '' || $submittedHost === null || $submittedHost !== $allowedHost) { api_respond(['error' => 'page_outside_domain', 'message' => 'Choose a page on your verified site domain.'], 422); }`.

- [ ] **Step 5: Manual verification**

End to end, locally: open `element-mapper.php` for a client without the snippet installed — confirm the install instructions render and "Check again" correctly flips to the live-mapper button once the snippet is added to a locally-served copy of the scanned site. Click "Open live mapper," confirm a new tab opens to the live site with the overlay active (per Task 7's own verification), select an element there, and confirm the dashboard tab's form pre-fills with that element, including for a page URL that was never part of the original site scan. Submit the form and confirm the save succeeds (previously it would have failed with `page_not_in_scan`).

- [ ] **Step 6: Commit**

```bash
git add element-mapper.php assets/js/element-mapper.js api/save-element-mapping.php
git commit -m "feat: wire up live visual tagging in the element mapper UI"
```
