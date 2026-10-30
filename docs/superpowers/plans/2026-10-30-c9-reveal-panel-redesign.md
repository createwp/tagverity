# C9 Reveal-Panel Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stacked accordions on Destinations and Tags & Triggers with the approved reveal-panel design: a card grid with a modal, parameter chips, destination pills with one config panel, an always-visible trigger summary, and a privacy drawer. Also add manual (literal-value) parameters.

**Architecture:** Shared, dependency-free UI helpers in `assets/js/app.js` (dialog, drawer, popover) are reused by every page. The Tags & Triggers editor is rebuilt inside `buildEventCard()` while keeping its outer one-expanded-event mechanism and its save contract. Manual parameters are a new JSON column validated server-side and routed per destination.

**Tech Stack:** PHP 8.1, PDO/MySQL, vanilla JS (ES2017 in the dashboard, which already uses `const` and `async`), CSS tokens in `assets/css/app.css`.

**Spec:** `docs/superpowers/specs/2026-10-30-reveal-panel-redesign-design.md` (approved 2026-10-30). Mockups: https://claude.ai/artifact/3WarRGSreT28h2Z9vPCy4o (artboards 1–2).

## Global Constraints

- Run PHP as `C:\wamp64\bin\php\php8.1.0\php.exe`; run all tests with `php tests/run.php`.
- Scope is bounded to: `destination-connections.php`, `assets/js/destination-connections.js`, `tracking-workspace.php`, `assets/js/tracking-workspace.js`, `assets/js/app.js` (helpers only), `assets/css/app.css` (append), and the backend files named in Task 3. Do not touch `dashboard.php`, `audits.php`, `domain-map.php` or `audit-report.php` (Phase 2 A2 owns them).
- Keep the outer single-expanded-event mechanism in `buildEventCard()` (`state.expandedEventKey`, the pencil `tracking-event-expand` button).
- One reveal pattern app-wide:
  - **modal**: configure a thing picked from a collection;
  - **popover**: pick from overflow or add from a list;
  - **drawer**: page-level settings;
  - **single inline reveal**: configure one of a few.
- Accessibility:
  - `<dialog>` with `showModal()`, Escape closes, focus moves in on open and returns to the opener on close, body scroll locked;
  - popovers and disclosures use `aria-expanded` / `aria-controls`;
  - icon-only buttons have `aria-label`;
  - 44 px touch targets, visible focus, works at 360 px, no sideways scrolling.
- Build DOM with `createElement` / `textContent`. Never put API data into `innerHTML`.
- Use existing tokens and components only. **One exception:** destination brand marks may use a fixed set of brand colours defined once as CSS custom properties (`--brand-ga4`, `--brand-posthog`, `--brand-meta`, `--brand-google-ads`) beside the other tokens. Use solid chips with a 1–2 letter monogram; never real logos.
- Raw PII protections, hashed-identifier opt-ins and the SHA-256 lightbox keep their current behaviour.
- Manual parameters:
  - name matches `/^[a-z][a-z0-9_]{0,39}$/`; value is a literal string of at most 100 characters with no control characters; at most 10 per event;
  - the name must not be one of the event's available parameters, nor identifier-like (`email`, `phone`, `first_name`, `last_name`, `name`, `full_name`, `address`, `postcode`, `zip`, `dob`, `user_id`, `ip`, or containing `email`, `phone` or `password`);
  - sendable to GA4 and PostHog under the same name, and to Meta inside `custom_data`; not sendable to Google Ads.

## Review Focus

1. Removing a captured parameter also removes it from every destination's Sends list, in the UI and in the saved payload. (Task 4 test + browser check)
2. A manual parameter named `email` or `customer_phone`, or with a value longer than 100 characters, is rejected by the server even if the UI is bypassed. (Task 3 test)
3. A Google Ads route that selects a manual parameter is rejected (`unsupported_destination_parameter`). (Task 3 test)
4. With every destination enabled, only one destination config panel is open at a time, and keyboard users can reach every control. (Task 5 browser check)
5. An existing event saved before this change (no `manual_parameters` key) loads and re-saves unchanged. (Task 3 test)

---

### Task 1: Shared reveal helpers

**Files:**
- Modify: `assets/js/app.js` (append), `assets/css/app.css` (append a `/* Reveal patterns */` section)
- Modify: `assets/js/connections.js` (use the helper; remove its private dialog code)
- Test: `tests/RevealHelpersTest.js`, `tests/RevealHelpersStaticTest.php`

**Interfaces:**
- `window.TagVerityUI = { openDialog, closeDialog, openDrawer, closeDrawer, bindPopover, isOutside }`:
  - `openDialog(dialog: HTMLDialogElement, opener: HTMLElement | null, initialFocus?: HTMLElement): void`: `showModal()`, adds `scroll-locked` to `<body>`, focuses `initialFocus` (else the first focusable element), and remembers the opener. A `close` listener (attached once) removes the lock and refocuses the opener. Clicking the backdrop (the click target is the dialog itself) closes it.
  - `closeDialog(dialog): void`.
  - `openDrawer(drawer: HTMLElement, opener)` / `closeDrawer(drawer)`: the drawer is an `<aside class="reveal-drawer" role="dialog" aria-modal="true" aria-labelledby=…>` plus a sibling `.reveal-drawer-backdrop`. Opening sets `hidden = false` and adds `.open` (transform slide-in from the right, 360 px wide, full width below 480 px). It locks scroll, traps Tab inside, and closes on Escape or backdrop click, returning focus to the opener.
  - `bindPopover(button: HTMLButtonElement, panel: HTMLElement): { open(), close() }`: toggles `panel.hidden`, keeps `aria-expanded` and `aria-controls` in sync, positions the panel under the button (absolute, inside the nearest positioned ancestor), and closes on Escape (focus back to the button) or on an outside click. Only one popover is open at a time app-wide.
  - `isOutside(target: Node, elements: Node[]): boolean`: pure, unit-testable.
- CSS classes: `.reveal-dialog`, `.reveal-drawer`, `.reveal-drawer-backdrop`, `.reveal-popover`, `body.scroll-locked`, and `.chip`, `.chip-remove` (×), `.chip-add`, `.chip-auto` (the "auto" tag). Respect `prefers-reduced-motion`.

- [ ] **Step 1: Write failing tests:**
  - `RevealHelpersTest.js`: stub `global.window`/`document` the way `tests/MapperSnippetSelectorTest.js` does, load `app.js` (guard DOM-dependent init behind `typeof document.addEventListener === 'function'` checks if needed), then assert `isOutside` returns true and false correctly with simple fake nodes exposing `contains()`.
  - `RevealHelpersStaticTest.php`: `app.js` defines `TagVerityUI` with all six names; `connections.js` calls `TagVerityUI.openDialog(` and no longer calls `showModal(` itself; `app.css` contains `.reveal-drawer` and `prefers-reduced-motion`.
- [ ] **Step 2: Run** both — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both, `node --check` on both JS files, and `php tests/run.php` (ConnectionsPageTest must still pass) — expect PASS. The reviewer checks the Connections picker still opens and closes correctly.
- [ ] **Step 5: Commit** `feat: add shared dialog, drawer and popover helpers`

### Task 2: Destinations card grid and modal

**Files:**
- Modify: `destination-connections.php`, `assets/js/destination-connections.js`, `assets/css/app.css` (append)
- Test: `tests/DestinationConnectionsPageTest.php` (extend)

**Interfaces:**
- Page:
  - Replace the vertical `<details>` list with `<div class="destination-grid">`: 4 columns at ≥1100 px, 2 at ≥640 px, 1 below.
  - Each card is a `<button type="button" class="destination-tile">` containing:
    - a `destination-mark destination-mark-{platform}` chip (monogram: GA, PH, M, GAds);
    - the label;
    - a status badge: `configured` → "Connected", otherwise "Not connected";
    - the one-line `summary`.
  - Keep the intro card.
  - One `<dialog id="destination-dialog" class="reveal-dialog" aria-labelledby="destination-dialog-title">` holds the selected platform's existing setup content: the auth state, numbered setup steps, fields and the save footer, built with DOM APIs from the same `connection` data.
  - On save success: toast, update the tile badge in place, close the dialog.
  - The "first incomplete card opens automatically" behaviour becomes: none open automatically. The first not-connected tile gets a subtle "Start here" label.
- Remove `escapeHtml`/`innerHTML` rendering and the chevron `<template>`.

- [ ] **Step 1: Extend the test** (static): the page contains `id="destination-dialog"`; the JS contains `TagVerityUI.openDialog(`, no `innerHTML`, and no `<details`; the CSS contains `.destination-grid` and `--brand-ga4`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, `node --check`, and `php tests/run.php` — expect PASS. The reviewer browser-checks 1280/768/360 px, open/save/close, Escape and focus return.
- [ ] **Step 5: Commit** `feat: show destinations as a card grid with a setup dialog`

### Task 3: Manual parameters — storage, validation, routing

**Files:**
- Modify: `run-migrations.php` (migration 015), `src/TrackingWorkspace.php`, `api/save-tracking-event.php`, `src/EventDestinationRouter.php`, `src/DestinationPayloadPreview.php`
- Create: `src/ManualParameters.php`
- Test: `tests/ManualParametersTest.php`, `tests/Migration015Test.php`, `tests/EventDestinationRouterTest.php` (extend)

**Interfaces:**
- Migration 015: `ALTER TABLE tracking_event_settings ADD COLUMN manual_parameters JSON NULL AFTER selected_parameters` (guarded; idempotent).
- `final class ManualParameters { public const MAX = 10; public const MAX_VALUE_LENGTH = 100; public static function clean(array $input, array $availableParameters): array }`: returns a list of `['name' => string, 'value' => string]`. It throws `InvalidArgumentException` with code strings:
  - `manual_parameter_name` for an invalid or identifier-like name;
  - `manual_parameter_conflict` when the name is an available parameter, or a duplicate;
  - `manual_parameter_value` when the value is empty, longer than 100 characters, or contains `[\x00-\x1F\x7F]`;
  - `manual_parameter_limit` for more than 10.

  The identifier-like rule is the list in Global Constraints.
- `TrackingWorkspace::load()` adds `manual_parameters` (an array, default `[]`) to each event from the saved row.
- `save-tracking-event.php`:
  - Accepts optional `manual_parameters` (missing means keep the existing saved value; `[]` clears it), cleans it, stores it as JSON, and returns it.
  - Destination routes may list manual parameter names in their `parameters`. They are allowed only if present in the cleaned manual list.
  - User-facing messages:
    - `manual_parameter_name`: "Custom parameter names must start with a letter, use lowercase letters, numbers and underscores, and can't be personal information like email or phone."
    - `manual_parameter_conflict`: "That parameter already exists for this event."
    - `manual_parameter_value`: "Custom values must be 1–100 characters of plain text."
    - `manual_parameter_limit`: "An event can have up to 10 custom parameters."
- `EventDestinationRouter`:
  - `normaliseInput()` gains an optional final parameter `array $manualParameterNames = []`.
  - A manual name selected for `ga4`/`posthog` maps to itself, and for `meta` maps to `custom_data.<name>`.
  - For `google_ads` it throws `unsupported_destination_parameter`.
  - `routeOptions()` includes manual parameters in each destination's parameter options, with `manual => true` and a `supported` flag (false for google_ads).
- `DestinationPayloadPreview` places each selected manual parameter's literal value into the preview payload under its destination name.

- [ ] **Step 1: Write failing tests:**
  - `ManualParametersTest`:
    - `[['name' => 'promo_code', 'value' => 'SPRING24']]` is cleaned unchanged.
    - `email`, `customer_phone`, `Promo`, and `1code` throw `manual_parameter_name`.
    - A name in the available parameters, or a duplicate, throws `manual_parameter_conflict`.
    - A 101-character value, `"a\nb"`, or an empty string throws `manual_parameter_value`.
    - 11 items throw `manual_parameter_limit`.
  - `Migration015Test`: runs twice with `"ok":true` and the column exists. Use `PHP_BINARY`.
  - Router: `promo_code` selected for ga4 → `promo_code`; for meta → `custom_data.promo_code`; for google_ads → throws.
  - A save round-trip (DB-backed, through `TrackingWorkspace` plus the endpoint's cleaning function, or a direct call to the code the endpoint uses): an event saved without `manual_parameters` keeps a NULL column, and loads `[]`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, the existing `EventDestinationRouterTest`, `DestinationPayloadPreviewTest`, `TrackingWorkspaceSmokeTest`, and `php tests/run.php` — expect PASS.
- [ ] **Step 5: Commit** `feat: support custom literal parameters with per-destination routing`

### Task 4: Event panel — parameter chips and manual parameters

**Files:**
- Modify: `assets/js/tracking-workspace.js` (inside `buildEventCard()` and `saveEvent()`), `assets/css/app.css` (append)
- Test: `tests/TrackingWorkspacePageTest.php` (extend)

**Interfaces:**
- In the expanded card, the top row places "Event name" and "Business meaning" side by side (stacked below 640 px).
- **Parameters captured:** chips for the selected available parameters plus manual parameters (shown as `name = value` with a "custom" tag).
  - Each chip has a × button (`aria-label="Remove {name}"`).
  - A `+ Add parameter` button opens a `TagVerityUI.bindPopover` panel listing unselected available parameters, each tagged "auto", plus a "Custom value" section with a name and a value input and an Add button.
  - Client-side validation mirrors `ManualParameters`; the server stays authoritative.
- Chip state lives in a per-card JS object, not in hidden checkboxes. `saveEvent()` reads `selected_parameters` and `manual_parameters` from that state and sends both.
- Removing a captured parameter also removes it from every destination's Sends state.
- The trigger-intent select (click ↔ visibility) keeps its current behaviour.

- [ ] **Step 1: Extend the test** (static): `tracking-workspace.js` references `manual_parameters`, `TagVerityUI.bindPopover(` and `Remove `. `saveEvent` builds `selected_parameters` from state; assert the identifier `parameterState` (or whatever name you choose — then assert that one) is used in both `buildEventCard` and `saveEvent`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, `node --check`, and `php tests/run.php` — expect PASS. The reviewer browser-checks: add/remove chips, add a custom value, save, reload, still there.
- [ ] **Step 5: Commit** `feat: edit event parameters as chips with custom values`

### Task 5: Event panel — destination pills, single config panel, trigger summary

**Files:**
- Modify: `assets/js/tracking-workspace.js`, `assets/css/app.css` (append)
- Test: `tests/TrackingWorkspacePageTest.php` (extend)

**Interfaces:**
- **Destinations:**
  - A pill row. Each pill is a `<button>` with the brand mark, the label and an on/off dot showing `enabled`; `aria-pressed` reflects that its panel is open.
  - At most 4 pills show. Any more go behind a `+N more` button whose popover has a search box and rows. Today there are 4 platforms; the logic must still handle more.
  - Clicking a pill opens that destination's config in **one** panel below the pills, replacing any other.
  - The panel contains:
    - a "Send this event to {label}" switch (the existing `enabled`);
    - the recommendation select plus the name input (existing behaviour and validation);
    - **Sends** chips for that destination's selected parameters, each with ×, and a `+ Add` popover offering only captured parameters (auto and manual) that this destination supports. Unsupported ones are listed disabled with the reason "Google Ads can't receive custom parameters";
    - the identifier toggles, in a compact list;
    - "Preview saved payload".
  - Remove the `<details>` per destination.
- **Trigger:** an always-visible summary row showing a chip with the trigger label, "Runs on …", and an "Edit trigger" pencil button. For mapped events the button goes to the mapper deep link; for others it focuses the trigger-intent select, or shows "This trigger comes from your measurement plan" when nothing is editable. A small chevron button (`aria-expanded`) reveals the detail rows (Selector, Verification, platform tag chips).
- `saveEvent()` reads destinations from per-card state, including each route's `parameters` (auto and manual names). Name validation is unchanged; the first invalid destination's panel opens and its name input gets focus.

- [ ] **Step 1: Extend the test** (static): `tracking-workspace.js` contains no `'details'` element creation for destinations (`node('details'` absent); it contains `aria-pressed`, `more`, `Edit trigger`, and `can't receive custom parameters`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test, `node --check`, and `php tests/run.php` — expect PASS. The reviewer browser-checks the Review Focus items 1 and 4 at 1280 and 360 px.
- [ ] **Step 5: Commit** `feat: configure destinations through pills and a single reveal panel`

### Task 6: Privacy drawer; docs

**Files:**
- Modify: `tracking-workspace.php`, `assets/js/tracking-workspace.js`, `assets/css/app.css` (append), `docs/PROJECT_STATUS.md`, `design-qa.md`
- Test: `tests/TrackingWorkspacePageTest.php` (extend)

**Interfaces:**
- Move the `<aside class="tracking-privacy-column">` content into `<aside id="privacy-drawer" class="reveal-drawer" role="dialog" aria-modal="true" aria-labelledby="privacy-drawer-title" hidden>`, with a close button, opened by a header button: lock icon plus "Privacy" text (text hidden below 480 px, `aria-label="Privacy controls"` kept). The workspace becomes a single full-width column.
- All privacy form behaviour, the hash demo lightbox and saving are unchanged; `renderPrivacy()` still targets the same form ids.
- Docs:
  - `PROJECT_STATUS.md`: in the Tags & Triggers and destination sections, describe the new patterns. Add a `### 2026-10-30 (Phase 2 — C9)` change-log entry and a decision row: "Adopted one reveal pattern app-wide: modal for configuring a picked item, popover for overflow/add, drawer for page settings, single inline reveal for one of a few."
  - `design-qa.md`: replace the never-implemented "one open at a time" accordion claim with the new behaviour.

- [ ] **Step 1: Extend the test:** the page has `id="privacy-drawer"` with `role="dialog"`, the `tracking-privacy-column` class is gone, and the JS calls `TagVerityUI.openDrawer(`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test and `php tests/run.php` — expect PASS. The reviewer browser-checks the drawer (open/Escape/focus trap/save) at 1280 and 360 px.
- [ ] **Step 5: Commit** `feat: move privacy controls into an on-demand drawer`

## Deferred to C9 part 2 (after A2 merges)

First-run empty states, a dashboard focused on tracking health, and an accessibility sweep of the remaining pages. These touch `dashboard.php`, which A2 is changing in parallel.
