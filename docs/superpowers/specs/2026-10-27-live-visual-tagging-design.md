# Live Visual Tagging Toolbar — Design

**Document type:** Project design (superpowers:brainstorming)
**Last updated:** 2026-10-27
**Status:** Draft — pending product-owner approval before any implementation plan or code
**Supersedes-in-part:** the "visual element mapping MVP" section of
[`PROJECT_STATUS.md`](../../PROJECT_STATUS.md) once built; does not remove it
until the live path is proven
**Follows from:** P1 in
[`COMPETITIVE_LANDSCAPE_AND_ROADMAP.md`](../../COMPETITIVE_LANDSCAPE_AND_ROADMAP.md)

## Intent and Why

Every tag-management competitor researched (Tealium, Segment, mParticle,
RudderStack) and GTM itself lacks a live point-and-click element picker.
PostHog, Heap, and Amplitude have one, and it is the single clearest
"anyone can do this without a developer" moment in the category. Today,
TagVerity's visual mapper (`element-mapper.php`, `SitePreviewRenderer`,
`preview-site.php`) works against a **static, sandboxed snapshot** fetched
server-side: scripts, event handlers, and frames are stripped before the
user ever sees the page. It cannot show real SPA behaviour, dynamically
rendered content, or a real click handler, and every selector it proposes
is a guess that still needs separate, later browser verification. This
design replaces that gap with a live overlay on the client's real,
running site — matching the PostHog/Heap/Amplitude pattern — while
reusing almost all of the existing selector-generation logic and
everything downstream of it (template building, the tracking workspace,
release review) unchanged.

## Goals

- A user can open a toolbar on their actual live website, hover and click
  real elements, and have TagVerity capture a selector and element
  payload from the real DOM — not a snapshot.
- The capture mechanism must work on a general third-party site that
  TagVerity does not control and that may set its own `X-Frame-Options`
  or `frame-ancestors` CSP (which blocks iframing from an outside origin;
  see Constraints).
- Everything downstream of "an element and a payload were selected" —
  `ElementTemplateBuilder`, `measurement_elements`, the tracking
  workspace, release review — is unchanged. This feature only changes how
  the selector/payload is captured, not what happens with it afterward.
- The static sandboxed preview is kept, not replaced, as the fallback for
  a client who has not yet installed the live-mapper snippet.

## Non-Goals

- This is not server-side tagging, full destination delivery, or AI
  event suggestion (P2/P4/P5 in the roadmap doc). It only replaces the
  *input* side of the existing visual mapper.
- This does not make any tag "live" in GTM or a destination. Publication
  remains gated behind the existing release/readiness workflow
  (`publish-tags.php`, `PublishReadiness`), unchanged.
- This is not a general analytics/collector SDK. The snippet's only job
  is to render an authenticated overlay when summoned; it does not fire
  tracking events or talk to any destination.

## Anti-Goals

- The snippet must never be usable to read or exfiltrate a client's live
  DOM by anyone other than an authenticated TagVerity user who currently
  holds a fresh, single-purpose token for that exact domain. It must stay
  inert otherwise — this is the one way this feature could become a
  security liability, and it is treated as non-negotiable.
- A client must not be required to already have GTM or any tags live
  before using the live mapper. Many clients adopt TagVerity precisely
  because they have no tagging set up yet; the snippet install must be
  independent of, and precede, any GTM container work.

## Constraints (decided — technical facts, not preferences)

- **A third-party site generally cannot be iframed from TagVerity's
  origin.** Most real sites set `X-Frame-Options` or a `frame-ancestors`
  CSP directive that blocks being embedded by an outside origin. This is
  why PostHog, Heap, and Amplitude do not use an iframe-from-outside
  approach at all — each requires its own JS snippet to already be
  present on the target page (first-party context), and the "toolbar" is
  a feature of that already-embedded snippet switched on remotely. The
  current `preview-site.php` only works around this by having TagVerity's
  own server fetch and re-serve the page from TagVerity's own origin
  (hence `frame-ancestors 'self'` in its CSP header) — which is exactly
  why that path is a snapshot, not live. The live path has no equivalent
  workaround; a first-party snippet is required.
- **No customer Google/analytics token ever passes through this layer.**
  Consistent with the existing security model — this feature only moves
  selector/payload data, nothing resembling a refresh token or API
  credential.

## Decided Architecture

### 1. A small, dormant mapper snippet, installed once per domain

A new static JS file (e.g. `assets/js/mapper-snippet.js`, served from
TagVerity's own CDN/origin) that the client pastes once into their site's
`<head>` — the same one-time install motion as a GTM container snippet,
GA tag, or PostHog snippet. At rest it does nothing: on page load it
checks `location.hash` for a `#__tagverity_mapper=<token>` fragment. If
absent, it exits immediately with negligible runtime cost. This mirrors
PostHog's own mechanism exactly (temp token in a URL hash, read by an
already-present snippet).

### 2. Token-gated overlay, not an iframe relationship

When a user clicks **"Open live mapper"** on `element-mapper.php` for a
verified domain:

1. The backend mints a short-lived (~30 minute), single-domain,
   single-client, single-user token and stores it server-side (new table,
   see Data Model) with an expiry.
2. The app opens a **new browser tab** to `{domain_root}#__tagverity_mapper=<token>`
   — not an iframe. The live site tab is independent; there is no
   `postMessage`-to-opener relationship required, because the snippet
   talks directly to TagVerity's API.
3. The snippet exchanges the token for a session via a CORS-restricted
   `fetch()` to a new endpoint, confirms the token's domain matches the
   page's own origin, and if valid, injects the overlay toolbar UI.
4. The existing hover/selector-generation logic in
   `SitePreviewRenderer::previewScript()` — `stableValue`,
   `usefulClasses`, `segment`, `selectorFor`, `cleanText`,
   `suggestedName`, `recommendedIntent`, `payloadFor` — ports into the
   snippet essentially unchanged. The only change is the transport: each
   click now `fetch()`-POSTs the captured payload directly to a new
   authenticated save endpoint (bearer: the exchanged session token)
   instead of `window.parent.postMessage`.
5. The TagVerity tab (`element-mapper.php`) short-polls a session-status
   endpoint while the live tab is open, so the existing "saved draft tags
   beneath the preview" tray updates as selections come in — the same UX
   the static mapper already has, just fed live.
6. The token is single-use for the exchange step (step 3) and the
   resulting session expires with the original TTL regardless of
   activity. CORS on both the exchange and save endpoints is restricted
   to the one verified origin tied to that token.

### 3. Domain eligibility — correction after verifying against real code

**This section was revised after the worktree received the actual,
previously-uncommitted application code.** The original draft assumed the
`domains` table already represented a verified client-owns-this-domain
concept and could be reused directly. Checked against the real codebase,
that is not true: the `domains` table (and its `verified` column) is
schema-only — `grep` across the whole app finds zero `SELECT`/`INSERT`/
`UPDATE` statements against it outside of comments in `AuditEngine`, and
`domain-map.php`, the page meant to host domain verification, is a stub.
It is a leftover from the earlier Google-connection audit design, not
something the onboarding/mapper flow uses.

The onboarding/mapper flow's actual scope boundary today is different and
self-reported: `api/save-element-mapping.php` only accepts a `page_url`
that appears in the client's own latest completed `site_scans.scan_data`
— there is no DNS/meta-tag ownership proof anywhere in the product today,
for scanning or for mapping. The live mapper's domain eligibility should
match that existing boundary rather than inventing a new one: eligibility
is keyed off the root domain of the client's own `site_scans.root_url` /
`onboarding_profiles.site_url`, not the dormant `domains` table.

Add a `site_scans.mapper_snippet_detected_at` column (not on `domains`),
populated by a lightweight server-side check — reusing `SiteScanner`'s
existing bounded, private-network-protected fetch — that looks for the
snippet's `<script>` tag on the scanned root URL. "Open live mapper" is
only enabled once this check has passed, so the first thing a user sees
if the snippet isn't detected yet is clear install instructions, not a
broken button.

**Open question for the product owner (genuinely theirs, not mine to
decide):** should the live mapper require real domain-ownership
verification (DNS TXT record or meta tag, the workflow `domain-map.php`
was always meant to host but never got) as a new prerequisite before this
ships, or is matching the product's existing self-reported trust level
acceptable for now? Matching it doesn't lower security below where the
product already sits (the scanner and static mapper already trust
whatever URL a logged-in client-scoped user types in), but it does mean
the live mapper inherits that same open item rather than closing it.

### 4. What is explicitly reused, unchanged

`ElementTemplateBuilder`, the `measurement_elements` table and its save
path, `TrackingWorkspace`, and the entire release/readiness pipeline are
untouched. This feature only changes how a `payloadFor()`-shaped object
reaches the server — the static preview's `postMessage` path and the new
live snippet's `fetch()` path both end up calling (a shared,
slightly-generalised version of) the same save logic
`api/save-element-mapping.php` already has, including its existing
validation rule that `page_url` must belong to the client's own latest
completed site scan — for the live path, that check becomes "does the
token's `site_scan_id` match, and does the page the snippet reports match
that scan's root domain," the live-session equivalent of the same rule.

## Data Model (additions)

- `site_scans.mapper_snippet_detected_at DATETIME NULL` — last time the
  snippet was confirmed present via server-side check against the scan's
  own `root_url`.
- New table `mapper_sessions`: `id`, `client_id`, `site_scan_id` (the
  scope boundary, matching how `save-element-mapping.php` already scopes
  by the client's latest completed scan), `created_by_user_id`,
  `token_hash` (never store the raw token), `status
  ENUM('pending','exchanged','expired')`, `expires_at`, `created_at`.
  Tokens are generated with `random_bytes`, hashed before storage
  (consistent with how session/CSRF tokens are already handled elsewhere
  in the codebase), single-use on exchange.
- Both additions follow the existing numbered-migration convention in
  `run-migrations.php` (the project's only migration mechanism — there is
  no separate migrations directory or tooling) as migration 012, rather
  than introducing a new schema-change process.

## What's Left to the Builder

- Exact polling interval and UX copy for "waiting for a selection from
  the live tab."
- Whether the snippet is served as one universal file for all clients
  (token carries all client/domain scoping, snippet itself has no
  per-client content) or templated per client — recommend universal,
  token-scoped, since that avoids any per-client build/cache step and
  matches how GTM/GA/PostHog snippets work (identical file, scoping lives
  in the token/ID).
- Whether `mapper_sessions` reuses the existing `rate_limits` table's
  patterns for cleanup/expiry or gets its own cron-free lazy-expiry check
  (recommend lazy expiry on read, consistent with how the project avoids
  extra cron jobs where possible).
- Exact overlay visual design (colours/placement of the floating
  toolbar) — reuse the existing `tagverity-preview-hover` /
  `tagverity-preview-selected` outline treatment from
  `SitePreviewRenderer::previewCss()` for visual continuity between the
  static and live mappers.
