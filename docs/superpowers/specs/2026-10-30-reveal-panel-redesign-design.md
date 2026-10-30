# Reveal-Panel Redesign — Destinations and Tags & Triggers

**Document type:** Approved design spec
**Approved by product owner:** 2026-10-30 ("Approved — build it")
**Delivered in:** Phase 2, C9 design pass (see `docs/superpowers/plans/2026-10-30-enhancement-roadmap.md`)
**Mockups:** [TagVerity reveal-panel mockups](https://claude.ai/artifact/3WarRGSreT28h2Z9vPCy4o) — interactive design canvas, artboards 1 and 2. Artboard 3 (Trigger builder) is out of scope here; see "Deferred".
**Origin:** handover from a Claude desktop brainstorming session (three mockup feedback rounds), 2026-10-30.

## Problem

The product owner asked three times for the same thing: accordions take a lot of space, feel clunky, and the settings form is too busy when open. Reading the code confirmed three causes:

1. `assets/js/tracking-workspace.js` `buildEventCard()` — the outer level already keeps one event expanded at a time (`state.expandedEventKey` and the pencil `tracking-event-expand` button). That mechanism is correct and stays. The problem is inside an expanded card: name field, business-meaning select, parameter-template select, a parameter checkbox grid, a Destinations fieldset holding one native `<details class="tracking-destination-card">` per platform (accordions inside an accordion), and a Trigger panel are all visible at once.
2. `assets/js/destination-connections.js` renders each platform as a plain `<details class="card destination-connection-card">` with no "one open at a time" logic, although `design-qa.md` claims that was intended.
3. `tracking-workspace.php` renders "Privacy control" as a permanent sidebar column (`<aside class="tracking-privacy-column">`).

A grep confirmed these are the only places this accordion pattern is used, so the redesign is bounded to: `tracking-workspace.php`, `assets/js/tracking-workspace.js`, `destination-connections.php`, `assets/js/destination-connections.js`, plus shared styles in `assets/css/app.css`.

## Design

### A. Destinations — card grid + centred modal

- Replace the vertical accordion list with a responsive card grid: 4 columns on desktop, 2 on tablet, 1 on phone.
- Each card shows a brand-coloured mark (a solid colour chip with a 1–2 letter monogram; never real trademarked logos), the platform name, a Connected / Not connected badge, and a one-line status summary.
- Clicking a card opens a centred modal over a backdrop with that platform's guided setup (OAuth sign-in or manual ID/key fields, as today). A modal stays reachable whatever the scroll position or number of cards (the design must hold for 50–100 destinations); an inline panel below the grid does not.
- Modal behaviour: `<dialog>`, focus trapped, Escape and backdrop click close it, focus returns to the card that opened it, and the body scroll locks while open.
- When B7 (destination test events) lands, the badge reflects verified health (Connected · verified / Saved · not verified / Token expired) instead of "saved".

### B. Tags & Triggers — simplified event panel

- Keep the one-expanded-event mechanism. Simplify what is inside an expanded event:
  - **Destinations picker:** the first 4 destinations show as pills. The rest sit behind a "+N more" button that opens a small popover with a search box and the remaining destinations as rows. Selecting a destination opens its configuration in a single reveal panel below the pills; only one destination's configuration is open at a time (enforced in JS).
  - **Parameters captured:** chips with a × to remove each, plus a "+ Add parameter" menu with two paths:
    1. Pick from a catalogue of auto-captured variables (e.g. `element_id`, `click_url`, `page_referrer`, `consent_state`), tagged "auto", needing no value.
    2. Define a manual parameter by typing a name and a literal value (e.g. `promo_code = SPRING24`).
  - **Per-destination "Sends:" list:** the same add / × treatment, but the add menu offers only parameters already captured above. A destination forwards a subset of the event's parameters; it cannot invent new ones. This was a deliberate decision, open to revisiting later.
  - **Trigger summary:** an always-visible chip with a pencil "Edit trigger" button beside it, plus a small chevron that expands condition/exception counts below.
- **Privacy control** moves out of the permanent sidebar into a slide-over drawer from the right, opened by a lock-icon button in the page header. The page uses the freed column width for the event list.
- Raw-PII protections, hashed-identifier opt-ins, and the SHA-256 lightbox keep their current behaviour; only where they appear changes.

### Shared rules

- One reveal pattern across the app: modal for "configure a thing picked from a collection", popover for "pick from overflow", slide-over drawer for "page-level settings", single inline reveal for "configure one of a few".
- Existing design tokens, icon family (`app_icon()` / stroke SVG), and the confirmation modal from `assets/js/app.js`. 44 px touch targets, works at 360 px wide, visible focus, `aria-expanded` / `aria-controls` on every disclosure, `aria-label` on icon-only buttons.
- The A1 Connections resource picker (A1 Task 9) uses the same modal pattern, so the redesign leaves three consistent surfaces rather than adding a new style.

## Data changes

- **Manual parameters** (name + literal value) need storage. Extend the per-event configuration in `tracking_event_settings` with a JSON list of `{name, value, source: "manual"}`. Names use the same lowercase GA4-safe validation already applied to event names. Values are literal strings ≤100 characters and must never be identifier fields (email, phone, names). They pass through the existing raw-PII blocklist.
- **Per-destination "Sends" subsets** reuse today's per-event parameter selection, scoped per destination. The implementation plan will confirm the storage shape against `tracking_event_settings` and `TrackingWorkspace::load()` before choosing a column or JSON key.
- No other schema changes.

## Deferred: Trigger builder (separate phase)

Artboard 3 — popular trigger tiles, a "Browse all types" modal of 14 types grouped into five categories, per-type configuration, sentence-style conditions with clickable and/or chips, and exception chips — is approved in principle. It is scheduled as its own phase after B5 (GTM deployment adapter), because it needs a real data model (trigger type, per-type config schema, condition groups, exceptions) that should map one-to-one onto GTM trigger resources written by B5. Recommended types beyond the original list: Consent update, Outbound link click, History change (SPA), and DOM ready / Window loaded split from Page view.

## Acceptance

1. Destinations: grid at 1280 / 768 / 360 px; a modal opens from any card, traps focus, closes with Escape or backdrop click, and returns focus to the card.
2. Tags & Triggers: with 10 destinations configured, at most 4 pills plus "+6 more"; the popover search filters; only one destination configuration is open at any time.
3. Parameters: removing a captured parameter also removes it from every destination's Sends list; a manual parameter can be added, saved, reloaded, and removed; identifier-like names are rejected with a clear message.
4. Privacy: the drawer opens from the header lock button and keeps every existing control and its saved values.
5. No regression in existing workspace tests; new behaviour is covered by page tests in the style of `tests/TrackingWorkspacePageTest.php` and `tests/DestinationConnectionsPageTest.php`.
