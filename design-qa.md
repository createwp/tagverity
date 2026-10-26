# Event destinations accordion QA

## Source visual truth

- Source: user-provided Event destinations screenshot in the current conversation.
- Source pixels: 1904 × 912.
- Intended viewport: desktop, approximately 1904 × 912 CSS pixels at 1× density.
- State: all four destination forms visible in a two-column grid. The requested change is a single-column accordion with one destination open at a time.

## Implementation target

- Compact single-column rows for GA4, PostHog, Meta, and Google Ads.
- Platform status remains visible while the technical setup is collapsed.
- One accordion can be open at a time.
- The open state contains a three-step plain-language guide, official documentation links, an account link, connection status, and the minimum required fields.
- Google destinations use the existing OAuth sign-in and automatically discover accessible GA4 streams.
- PostHog and Meta use guided manual setup until their production OAuth applications are configured.
- Mobile layout stacks the guide, connection state, fields, and save action.

## Automated evidence

- PHP syntax checks: passed.
- JavaScript syntax check: passed.
- Full PHP and browser-logic suite: 20 tests passed.
- Accordion, documentation-link, Google OAuth scope, OAuth return-path, GA4 discovery, responsive selector, and connection security assertions: passed.

## Browser-rendered evidence

- Implementation screenshot: unavailable.
- Primary interactions tested in browser: unavailable.
- Browser console check: unavailable.
- Blocker: the in-app browser session could not initialize because its managed sandbox metadata was unavailable.

## Required fidelity surfaces

- Fonts and typography: existing TagVerity type tokens and hierarchy retained; browser comparison blocked.
- Spacing and layout rhythm: code now uses full-width compact accordion rows and a two-column open panel; visual comparison blocked.
- Colors and visual tokens: existing TagVerity semantic tokens retained; visual comparison blocked.
- Image quality and assets: no new raster assets were required; existing application icon system retained.
- Copy and content: technical API wording was replaced with guided, task-oriented language and official help links.

## Findings

- [P1] Rendered accordion fidelity cannot be confirmed.
  - Location: Event destinations page at desktop and mobile breakpoints.
  - Evidence: no browser-rendered implementation screenshot is available for comparison with the supplied reference.
  - Impact: spacing, wrapping, and accordion density may still need a visual adjustment.
  - Fix: open the page in the local browser, capture desktop and mobile open/closed states, compare them with the supplied screenshot, and correct any P1/P2 mismatch.

## Comparison history

- Initial source finding: all destination forms were visible simultaneously, creating unnecessary height and technical overload.
- Fix made: converted the grid to one-at-a-time accordions and added progressive guidance plus sign-in/manual paths.
- Post-fix visual evidence: blocked because the browser capture surface was unavailable.

final result: blocked
