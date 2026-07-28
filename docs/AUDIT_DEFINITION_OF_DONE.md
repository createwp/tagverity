# Audit Definition of Done

**Document type:** Living acceptance criteria
**Last updated:** 2026-07-28
**Status:** Draft based on current product-owner direction

## Objective

An audit is done only when the product has gathered enough evidence to make an
honest claim about the implementation. Finishing a queue job is not the same as
finishing an audit.

The product must never convert missing GTM, browser, or GA4 data into a clean
result or a score of 100.

## Audit Outcome States

The target outcome model is:

| Outcome | Meaning |
|---|---|
| `queued` | Waiting for a worker |
| `running` | At least one source or rule stage is active |
| `complete` | All required sources and applicable checks completed |
| `partial` | Some checks completed, but one or more required sources or coverage requirements were unavailable |
| `failed` | The audit could not produce a trustworthy result |

The existing database enum does not include `partial`. Adding it will require a
new migration when this outcome model is approved.

## Required Audit Context

Every audit must persist:

- Tenant and customer
- Connected Google identity used for access
- GTM account, container, workspace or version, and public container ID
- GA4 property and data stream
- Verified domains included in scope
- Requested and actually observed page scope
- GA4 reporting window
- Source status for GTM, browser crawl, GA4, and optional BigQuery
- Start and completion timestamps
- Rule-set version
- Scoring version
- Errors, warnings, retries, and unavailable checks

## Source Completion Rules

Each source must report one of:

- `success`
- `not_applicable`
- `permission_denied`
- `authentication_failed`
- `quota_limited`
- `timed_out`
- `unavailable`
- `insufficient_coverage`

An audit may be `complete` only when every required source is `success` or
`not_applicable`. A missing required source produces `partial` or `failed`, not a
normal health score.

The report must state which checks ran and which could not run.

## Rule Execution Requirements

Every applicable audit rule must record:

- Rule identifier and version
- Whether the rule was applicable
- Whether it ran successfully
- Input source and observation period
- Pass, fail, warning, or unable-to-evaluate result
- Evidence supporting the result
- Affected GTM entity, page, event, or GA4 field

An empty findings array is not proof that the implementation is healthy. It is a
clean result only when all required checks ran and recorded passes.

## `(not set)` Product Rule

The product-owner direction is that seeing `(not set)` in required analytics
data is a severe measurement failure. Detecting the value must start a root-cause
diagnostic run rather than only subtracting points from a score.

The diagnostic must attempt to determine whether the value was caused by:

- A tag, trigger, variable, or Measurement ID that is missing or misconfigured
- A workspace change that was never versioned or published
- A configuration or event tag firing too early, before required values exist
- A tag firing too late, after navigation, unload, or the relevant action
- A trigger condition that did not occur or no longer matches the DOM
- A missing, late, malformed, or overwritten data-layer value
- Consent state or a consent-management platform blocking execution
- Website policy such as CSP, sandboxed frames, redirects, or JavaScript errors
- Browser policy, privacy protection, or a content-blocking extension
- A network request being cancelled, rejected, or sent to the wrong property
- GA4 receiving the event without the required parameter
- GA4 processing or reporting semantics rather than a browser implementation
  failure

The rule must still identify:

- The GA4 dimensions and parameters considered required
- Whether one affected event is enough to trigger the rule
- Whether sampling, thresholding, privacy controls, or delayed processing can
  legitimately produce the value
- The minimum evidence required to label a root cause as confirmed

### Working Score Rule

- Score `0`: the diagnostic confirms a current, reproducible implementation
  failure affecting a required field. Examples include a missing or unpublished
  tag, incorrect firing order, missing data-layer value at trigger time, consent
  blocking, a browser request not being sent, or the wrong GA4 property.
- Score `1`: required GA4 data contains `(not set)`, but the current controlled
  diagnostic cannot reproduce or conclusively locate the failure. The issue may
  be intermittent, historical, browser-specific, or related to GA4 processing.
- A normal score is unavailable until the critical condition is resolved or the
  affected field is explicitly removed from the required measurement contract.

Acceptance rule:

1. Any `(not set)` value in a declared required field creates a critical finding.
2. The report names the field, event, affected volume, percentage, and reporting
   window.
3. The tool automatically starts or schedules the applicable diagnostic checks.
4. The report shows the evidence timeline, root-cause classification, and
   confidence level.
5. The report explains which causes were ruled out and which could not be tested.
6. The report provides a recommended setup or fix and a re-verification action.
7. The audit cannot display a normal healthy score.

## Root-Cause Diagnostic Pipeline

The diagnostic engine must correlate four views of the same measurement:

1. **Expected contract:** blueprint event, required parameters, trigger, consent,
   destination, and verification steps.
2. **GTM configuration:** published version, tag and trigger definitions,
   variables, firing order, dependencies, consent settings, and Measurement ID.
3. **Browser execution:** DOM state, user action, data-layer state, consent,
   trigger eligibility, tag execution, console errors, and network requests.
4. **Analytics receipt:** GA4 property, event, parameters, receipt window, and
   reporting output.

### Evidence Timeline

For each tested page or interaction, record timestamps relative to navigation or
the user action for:

- Page navigation and redirects
- GTM script requested, loaded, or blocked
- DOM ready and relevant element availability
- Consent default and consent updates
- Relevant data-layer pushes
- Trigger conditions becoming true
- Tag execution
- Analytics request creation, destination, payload summary, and response
- Navigation or unload that could cancel a late request
- GA4 receipt check and reporting check

This timeline is how the product determines whether a tag fired too early, too
late, or not at all.

### Root-Cause Classifications

Every diagnostic finding should use one of these classifications:

- `gtm_configuration`
- `not_published`
- `trigger_not_exercised`
- `trigger_mismatch`
- `fired_too_early`
- `fired_too_late`
- `data_layer_missing`
- `data_layer_overwritten`
- `consent_blocked`
- `website_policy_blocked`
- `browser_blocked`
- `javascript_error`
- `network_failed`
- `wrong_destination`
- `ga4_not_received`
- `ga4_invalid_payload`
- `ga4_reporting_semantics`
- `inconclusive`

Each classification must include `confirmed`, `probable`, or `possible`
confidence and link that confidence to collected evidence.

### Website Versus Browser Blocking

The tool should compare results from a controlled clean browser with evidence
from the customer's affected browser or diagnostic session when available.

- A CSP violation, sandbox restriction, website JavaScript error, or repeatable
  failure in the clean browser indicates a website-side cause.
- A successful clean-browser run combined with a client-side blocked-request
  signal indicates a browser, privacy setting, or extension cause.
- Without evidence from the affected browser, the product must label a
  browser-specific cause as possible rather than confirmed.

The diagnostic script must not attempt to disable privacy controls or content
blockers. It only reports the observable failure and remediation options.

## Created Tag Verification

A tag created through GTM Auditor is not done when the API returns a tag ID. It
must move through independently verified stages:

| Stage | Required evidence |
|---|---|
| Blueprint approved | Approved requirement, event, parameters, trigger, and owner |
| Workspace synchronized | GTM API paths and fingerprints for every created entity |
| Version created | Container version identifier and change summary |
| Published | Published version identifier and timestamp |
| Trigger exercised | Browser observation proving the trigger conditions occurred |
| Browser fired | Tag execution or matching analytics request observed |
| GA4 received | Expected event and identifiers observed in GA4 within the verification window |
| Verified | All required stages passed |

### Failure Classification

- `not_published`: the entity exists only in a workspace or unpublished version.
- `trigger_not_exercised`: the test did not perform the action required to fire
  the tag; this is inconclusive, not a tag failure.
- `tag_did_not_fire`: the trigger conditions occurred but no matching tag
  execution or request was observed.
- `request_blocked`: the tag attempted to send, but consent, CSP, an extension,
  or a network error blocked it.
- `ga4_not_received`: the browser sent the expected request, but the event did
  not appear in GA4 within the defined verification window.
- `invalid_payload`: GA4 received the event but required parameters were absent,
  invalid, or `(not set)`.

### Required Alert

When a tag does not verify, the alert must include:

- Customer, domain, container, and tag
- Blueprint and deployment version
- Failed verification stage
- Page and action that were tested
- Whether the trigger condition occurred
- Browser and network evidence
- GA4 lookup evidence and time window
- Most likely cause
- Recommended fix
- Person or team responsible for the fix
- A button or action to re-run the verification

The message should say "tag did not fire" only when the trigger was exercised.
Otherwise it must say the test did not exercise the trigger.

The first view of an alert must be understandable by a non-technical marketer.
It must not require knowledge of GTM entity types, data-layer syntax, browser
network tools, or GA4 processing internals. Technical evidence and raw
identifiers belong in an expandable detail view and the developer handoff.

All alerts and errors follow the content and handoff requirements in
[`docs/ALERTS_AND_HANDOFFS.md`](ALERTS_AND_HANDOFFS.md).

## Recommended Setup and Fixes

The recommendation engine may use:

- Current GTM tag, trigger, and variable configuration
- Published and workspace diffs
- DOM landmarks and stable attributes from the tested page
- Data-layer events and keys
- Consent state
- Analytics network requests
- GA4 event and parameter observations
- Naming convention profiles
- Known blueprint templates

Recommendations must be structured and explain why the proposed trigger or
variable is more reliable. Prefer stable data-layer events and semantic DOM
attributes over brittle positional CSS selectors.

AI-generated recommendations are drafts. They must pass schema validation and
must not create or publish arbitrary JavaScript without explicit review.

## Page and DOM Coverage

The crawler cannot truthfully promise to read every possible page. Audit scope
must be declared and measured.

The page inventory should be built from:

- XML sitemaps
- User-provided seed URLs
- Internal links discovered within the allowed domains
- Explicit high-value conversion and application routes
- Optional authenticated test journeys configured by the customer

Every crawl records:

- Pages requested, visited, skipped, blocked, failed, and deduplicated
- Crawl depth and page limit
- Redirects and canonical URLs
- Page-template grouping
- Interactive actions exercised
- Pages requiring authentication or manual test steps

An audit is complete only when it meets the agreed coverage threshold. Otherwise
it is `partial` and the report states what was not covered.

## Cross-Domain Completion

An audit that includes multi-domain journeys is complete only when every declared
required transition has been tested or explicitly marked out of scope.

For each transition, the audit must determine whether:

- The source and destination use the intended Google tag and GA4 property
- The outgoing link or form is decorated when cross-domain linking is required
- The linker parameter survives every redirect and URL rewrite
- The destination accepts the incoming linker state
- The analytics client and session identifiers remain continuous
- The transition creates an unexpected `session_start`
- Source, medium, campaign, and referrer attribution remain correct
- Consent state remains valid and consistent
- The first destination event contains the required session and page context

A confirmed break in a required same-session journey is a score `0` failure. A
session break visible in analytics that cannot be reproduced in a controlled
transition is score `1` and remains critical until diagnosed.

The full transition model, evidence requirements, classifications, and privacy
rules are in [`docs/CROSS_DOMAIN_MEASUREMENT.md`](CROSS_DOMAIN_MEASUREMENT.md).

## Diagnostic Script Safety

Any script installed on a customer's site is a read-only observation tool. It
must not:

- Access or attempt to access the website database
- Modify page content or application records
- Capture passwords, payment details, form values, authentication tokens, or
  cookies
- Use `eval()` or execute instructions downloaded from an AI response
- Inject arbitrary custom code
- Send unredacted DOM content or personal information
- Bypass consent controls

It may send allowlisted structured observations such as element identifiers,
element types, redacted data-layer keys, trigger events, tag identifiers, and
analytics request metadata to an authenticated HTTPS endpoint.

## Scoring Requirements

- A score is calculated only from checks that completed successfully.
- Missing required sources block or qualify the score.
- Critical measurement-integrity gates override the weighted score.
- The scoring version is stored with every audit.
- The report explains every deduction and gate.
- Re-running the same fixtures with the same rule and scoring versions produces
  the same result.
- A score of 100 means all required checks ran and passed; it never means no data
  was available.

## Security and Tenant Acceptance

- Every audit and observation is bound to the authenticated tenant and customer.
- The server derives tenant ownership from the session, never request input.
- Google tokens remain encrypted at rest.
- GTM edit and publish permissions are separate from read access.
- Publication requires an explicit authorized action and an audit log.
- Crawl targets reject localhost, private networks, non-HTTP protocols, and
  domains outside the approved customer scope.
- Evidence shown in reports is escaped and redacted.
- No secrets or personal data are written to findings or application logs.

## Minimum End-to-End Acceptance Scenarios

The audit feature is not done until automated fixtures and controlled test sites
prove at least these scenarios:

1. Healthy container, browser execution, and GA4 receipt produce a clean audit.
2. GTM permission failure produces a failed or partial audit with no health score.
3. Empty API payloads cannot produce a score of 100.
4. A required GA4 field containing `(not set)` activates the critical score gate.
5. A tag exists in a workspace but is not published.
6. A published tag's trigger is not exercised and is reported as inconclusive.
7. A trigger is exercised but the tag does not fire.
8. A browser request is sent but the GA4 event is not received.
9. An event is received without required parameters.
10. An unexpected domain sends data to the property.
11. A cross-domain link preserves the linker and analytics session through all
    redirects.
12. A redirect strips or corrupts the linker and produces a diagnosed session
    break.
13. A customer white-label domain cannot be confused with or linked to another
    tenant's domain.
14. A crawl with insufficient page coverage is marked partial.
15. A user from another tenant cannot access the audit, blueprint, evidence, or
    deployment.

## Definition of Done for the First Trustworthy Release

- Google discovery populates real customer resources.
- The user explicitly maps containers, properties, streams, and domains.
- The audit runner fetches real GTM and GA4 data.
- Required-source failures produce honest outcome states.
- Every existing audit rule has passing and failing fixtures.
- Scoring gates and deductions are versioned and explained.
- Manual audit queueing, cron processing, status polling, and credit accounting
  work end to end.
- Audit reports identify evaluated and unevaluated checks.
- A controlled test account proves browser-to-GA4 verification for at least one
  created event tag.
- Tenant isolation, CSRF, OAuth state, token encryption, rate limiting, and
  output escaping pass review.
- The implementation status and this definition are updated to match the shipped
  behaviour.
- Marketer-facing alerts and developer handoffs pass the content acceptance
  scenarios in `docs/ALERTS_AND_HANDOFFS.md`.

## Decisions Still Required

- Required GA4 fields subject to the `(not set)` hard gate
- Minimum page and interaction coverage for a complete audit
- Minimum required cross-domain journeys and transition test coverage
- Verification delay and retry window for GA4 receipt
- First supported tag, trigger, and variable types
- Whether browser verification is mandatory for every plan or only selected
  plans
- Whether publishing remains human-confirmed for all tenants
