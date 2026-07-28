# Alerts, Error Messages, and Developer Handoffs

**Document type:** Living UX and content standard
**Last updated:** 2026-07-28
**Status:** Direction agreed; implementation not started

## Audience

The primary user is a non-technical marketer. They need to understand whether
measurement can be trusted, what business impact an issue has, and what to do
next without interpreting GTM JSON, browser network logs, or GA4 internals.

Developers and analytics specialists are secondary audiences. They need precise
technical evidence and acceptance criteria, but that detail should not make the
main marketer experience harder to understand.

## Content Principles

- Lead with the customer or measurement impact.
- Use familiar business language before technical terminology.
- State what was observed; do not imply certainty that the evidence does not
  support.
- Give one clear primary action.
- Identify the person or team most likely to fix the issue.
- Name the affected customer, website, journey, event, or report.
- Explain whether data is missing, duplicated, delayed, misattributed, or could
  not be checked.
- Distinguish a confirmed failure from a probable or possible cause.
- Preserve technical evidence without exposing it by default.
- Never blame the customer, marketer, or developer.
- Never show raw exception messages, stack traces, OAuth payloads, or secrets.
- Never use a score as the only explanation.

## Two-Layer Message Model

### Layer 1: Marketer View

Every finding should answer:

1. **What happened?** A short, plain-language title.
2. **Why does it matter?** The effect on reporting or decisions.
3. **Where did it happen?** Customer, domain, page, journey, and measurement.
4. **Who should fix it?** Marketer, GTM specialist, developer, GA4 admin,
   hosting team, or multiple owners.
5. **What should happen next?** One primary action and any necessary secondary
   action.
6. **How will we know it is fixed?** Re-test or verification outcome.

### Layer 2: Technical Detail

An expandable detail view should provide:

- Finding and rule identifiers
- Confidence level
- GTM account, container, workspace, version, tag, trigger, and variable paths
- GA4 property, stream, event, and parameter names
- Page and interaction tested
- Expected versus observed behaviour
- Evidence timeline
- Redacted browser, data-layer, consent, network, and GA4 observations
- Root-cause classification
- Recommended technical approach
- Verification steps

## Alert Card Structure

Every alert card should contain:

```text
[Severity] [Owner]
Clear outcome-focused title
One or two sentence explanation

Impact: What reporting or business decision is affected
Location: Customer, website, page, event, or journey
Cause: Confirmed, probable, possible, or still being investigated

[Primary action] [Send to developer] [View technical details]
Last checked: date and time
```

Only show actions that work. Do not display placeholder buttons.

## Severity Language

Severity should be communicated in words as well as colour and icons.

- **Critical:** Data is missing, materially incorrect, assigned to the wrong
  customer/property, or unsafe to rely on.
- **Warning:** Measurement works but is incomplete, fragile, inconsistent, or at
  meaningful risk.
- **Information:** An improvement, cleanup, or future recommendation that does
  not currently invalidate reporting.
- **Unable to check:** The tool lacked permission, source data, crawl coverage,
  or a required test step. This is not a passing result.

## Action Routing

Use action labels that describe the outcome:

- `Review recommended fix`
- `Create GTM workspace draft`
- `Open in Tag Manager`
- `Send to developer`
- `Send to GA4 administrator`
- `Review consent setup`
- `Reconnect Google account`
- `Grant required access`
- `Run this test again`
- `Add a test journey`
- `Mark as intentionally excluded`

Avoid generic labels such as `Fix`, `Resolve`, `Proceed`, or `Learn more` when a
more specific action is available.

## Human-Friendly Error Messages

System errors should use this structure:

```text
What could not be completed
Why, in plain language
Whether any work or data was saved
What the user can do next
Whether the system will retry automatically
Reference code for support
```

### Error Categories

- **Connection expired:** Explain that Google access needs to be reconnected;
  do not show `invalid_grant`.
- **Permission missing:** Name the resource that could not be checked and the
  role or access needed.
- **Quota or temporary service issue:** Explain that existing work is safe and
  when the tool will retry.
- **Incomplete setup:** Name the missing customer, container, property, domain,
  journey, or approval.
- **Partial audit:** State what was checked, what was not checked, and why no
  normal score is available.
- **Validation failure:** Point to the exact blueprint field or configuration
  requiring correction.
- **Unexpected server error:** Preserve user work, offer a safe retry, and show
  a non-sensitive reference code.

## Marketer Message Examples

### Tag Did Not Fire

**Title:** Purchase tracking did not run on the confirmation page

**Explanation:** We completed the checkout test and reached the confirmation
page, but the purchase tag did not send an event to Google Analytics.

**Impact:** Sales and revenue from this checkout may be missing from reports.

**Owner:** Developer and GTM specialist

**Primary action:** Send the technical handoff to the website developer.

### Required Value Is `(not set)`

**Title:** GA4 is missing the landing page for some sessions

**Explanation:** GA4 recorded sessions without knowing which page visitors first
landed on. We found evidence that page tracking may run before the session
details are ready.

**Impact:** Landing-page and campaign reports cannot be fully trusted.

**Owner:** GTM specialist

**Primary action:** Review the recommended firing-order change.

### Cross-Domain Session Break

**Title:** Visitor sessions restart when checkout opens

**Explanation:** The journey from `www.customer.com` to
`checkout.customer-payments.com` created a new analytics session instead of
continuing the existing one.

**Impact:** Purchases may be separated from the campaign or page that generated
them.

**Owner:** Developer and GA4 administrator

**Primary action:** Send the redirect and linker handoff to the developer.

### Browser-Specific Blocking

**Title:** Tracking was blocked in the affected browser

**Explanation:** The same journey worked in our controlled browser, but the
affected browser blocked the analytics request. The website setup appears to be
working for browsers without that restriction.

**Impact:** Data from visitors using similar privacy settings or extensions may
be incomplete.

**Owner:** Marketer or privacy owner

**Primary action:** Review the affected-browser details and expected measurement
limitations.

### Partial Audit

**Title:** We could not finish checking this website

**Explanation:** GTM and GA4 were checked, but the checkout journey required a
login that was not available to the test. The result is partial and no normal
health score is shown.

**Owner:** Customer account owner

**Primary action:** Add an approved checkout test journey.

## Developer Handoff

When the issue requires website or application work, the marketer should be able
to select `Send to developer` without rewriting or interpreting the finding.

The generated handoff must be versioned and tied to the customer, blueprint,
finding, and verification run.

### Handoff Summary

- Customer and website
- Issue title and business impact
- Priority
- Technical owner
- Date detected and last reproduced
- Affected blueprint and deployment versions

### Reproduction

- Environment and tested URL
- Browser or controlled test context
- Exact user journey and actions
- Required consent state
- Expected behaviour
- Actual behaviour
- Reproduction consistency

### Technical Evidence

- Root-cause classification and confidence
- GTM tag, trigger, variable, version, and workspace references
- DOM or data-layer state at the relevant time
- Redacted network request evidence
- Consent and JavaScript error evidence
- GA4 receipt evidence
- Cross-domain redirect and identifier-continuity evidence where relevant

### Required Change

- Clear description of the required website or application behaviour
- Data-layer event and typed parameters
- When the event must be emitted
- Required stable element attributes if DOM interaction is involved
- Routing, redirect, consent, or timing constraints
- Reviewed implementation example when available
- Explicit personal-data restrictions

The handoff should identify likely files, templates, routes, or components only
when the tool has evidence. It must not invent file paths or framework details.

### Acceptance Tests

- Steps the developer can run locally or on staging
- Expected data-layer observation
- Expected GTM trigger and tag result
- Expected analytics request
- Expected GA4 event and required parameters
- Expected cross-domain session result
- Link or action for GTM Auditor to re-run verification

### Completion State

The handoff should progress through:

```text
draft
  -> sent
  -> acknowledged
  -> developer_ready_for_test
  -> verification_running
  -> verified
  -> closed
```

The marketer should see status and ownership without needing access to a
developer tool.

## Sharing and Privacy

- Share only the affected customer's information.
- Require authenticated or expiring access for technical evidence.
- Do not include refresh tokens, cookies, raw linker values, personal data, or
  unrelated page content.
- Record who created, viewed, sent, and closed the handoff.
- Show a visible document version and generated date.
- Revoke access when a user or customer relationship is removed.
- Preserve a PDF or static export option for developers who cannot access the
  application, without weakening tenant isolation.

## Content Quality Checks

Before a message is shown:

- The title states the outcome, not an internal rule name.
- The explanation is understandable without technical training.
- The impact is specific and not exaggerated.
- The location identifies the affected customer and measurement.
- Ownership is assigned.
- The primary action is available and relevant.
- Confidence matches the evidence.
- Technical terms are explained or kept in technical details.
- The developer handoff contains enough information to reproduce and verify.
- No secret, personal information, or unsafe raw payload is present.

## Definition of Done

The alert and handoff experience is done when:

- Every audit outcome, alert, and system error uses the two-layer model.
- Non-technical test users can explain the issue, impact, owner, and next action
  after reading only the marketer view.
- Developers can reproduce the issue using the handoff without asking the
  marketer to translate it.
- Every actionable issue has a working primary action.
- Partial and failed checks cannot be mistaken for passing results.
- Re-verification updates the alert and handoff status.
- Messages pass accessibility checks without relying on colour alone.
- Tenant isolation and evidence-redaction rules pass review.

## Open Decisions

- Which sharing formats ship first?
- Does the first release send handoffs by email, provide a secure link, or both?
- Which external issue trackers should be supported later?
- Can customers customize terminology and ownership labels?
- Which alerts can be automatically grouped into one developer handoff?
