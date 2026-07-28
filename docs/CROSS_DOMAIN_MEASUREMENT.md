# Cross-Domain Measurement and Session Continuity

**Document type:** Living product and acceptance requirements
**Last updated:** 2026-07-28
**Status:** Direction agreed; implementation not started

## Objective

GTM Auditor must make cross-domain measurement visible and testable. It should
show whether a user journey remained one analytics user and one session as it
moved between subdomains, separate root domains, applications, checkout
providers, booking systems, embedded flows, and customer white-label domains.

The feature must explain where continuity broke rather than only reporting that
session counts look wrong.

## Supported Journey Types

The domain model must support:

- Primary website to subdomain
- Marketing website to web application
- Website or application to a separate checkout domain
- Website to booking, form, payment, or customer portal domain
- Customer-owned domain to a shared white-label platform
- Customer custom domain to another custom or platform domain
- Multiple brands measured into one approved GA4 property
- One GTM container intentionally serving multiple approved domains
- Different containers coordinating measurement into the same GA4 property
- Journeys that intentionally start a new session

## Domain Transition Graph

Domains are nodes and expected user transitions are directed edges:

```text
www.customer.com
  -> app.customer.com
  -> checkout.vendor.com

brand-a.com
  -> brand-a.platform.example

brand-b-custom.com
  -> shared.platform.example/brand-b
```

Each node should record:

- Tenant and customer ownership
- Hostname and approved URL patterns
- Domain type: primary, subdomain, app, checkout, form, portal, payment,
  white-label, or third-party
- Expected GTM containers and Google tag IDs
- Expected GA4 property and stream
- Consent-management configuration
- Whether the domain can receive incoming linker state
- Verification status and last successful observation

Each edge should record:

- Source and destination
- Link, form, JavaScript navigation, redirect, iframe, or other transition type
- Seed page and interaction needed to exercise the transition
- Whether the same analytics session is required
- Whether forms require decoration
- Expected redirect chain
- Expected attribution behaviour
- Maximum time allowed between source and destination
- Required consent state
- Last verification result

## Why Continuity Breaks

For separate root domains, cross-domain measurement passes first-party
measurement identifiers through a linker parameter named `_gl`. Without working
cross-domain measurement, analytics can identify the same visitor as two users
and two sessions. Subdomain implementations can also create self-referrals or
session problems when tagging and cookie-domain configuration are inconsistent.

Official references:

- [GA4 cross-domain measurement](https://support.google.com/analytics/answer/10071811)
- [Google tag cross-domain developer guide](https://developers.google.com/tag-platform/devguides/cross-domain)

## Transition Verification Flow

For every required edge, a controlled browser should:

1. Start on the configured source page in a new test context.
2. Record the source Google tag IDs, client identifier, session identifier,
   consent state, traffic-source context, and relevant cookies in redacted form.
3. Confirm that the expected link, form, or navigation control exists.
4. Exercise the real user action rather than constructing the destination URL.
5. Record whether `_gl` was added at click or submission time.
6. Follow and record every redirect without losing intermediate URLs.
7. Check whether `_gl` remained present, was moved, encoded, corrupted, expired,
   or removed at each hop.
8. Confirm that the destination loaded successfully.
9. Record whether the destination accepted the incoming linker state.
10. Compare source and destination client and session identifiers.
11. Inspect the first destination analytics requests and required parameters.
12. Check GA4 receipt and whether an unexpected new session or self-referral was
    created.
13. Store a redacted evidence timeline and classification.

Linker parameters are added at interaction time and are time-sensitive, so the
test must exercise the actual transition promptly. Static HTML inspection alone
is not sufficient.

## Continuity Evidence

A transition passes only when the required evidence supports continuity:

- Correct source and destination tag IDs
- Correct GA4 property and stream
- Linker decoration occurred where required
- Linker survived the full redirect chain
- Destination accepted incoming linker state
- Client identifier continuity
- Session identifier continuity
- No unexpected new `session_start`
- No self-referral or unwanted referral from an owned domain
- Campaign and traffic-source attribution remained consistent
- Consent state did not silently change or block identifier transfer
- Required landing-page and event parameters were present

The report should show a simple journey view with each transition marked pass,
fail, inconclusive, or not tested. Expanding a transition should reveal the
technical evidence timeline.

## Failure Classifications

- `source_tag_missing`
- `destination_tag_missing`
- `tag_id_mismatch`
- `ga4_property_mismatch`
- `domain_not_configured`
- `outgoing_link_not_decorated`
- `form_not_decorated`
- `javascript_navigation_not_decorated`
- `click_propagation_blocked`
- `linker_stripped_by_redirect`
- `linker_malformed`
- `linker_expired`
- `destination_not_accepting_linker`
- `cookie_domain_mismatch`
- `consent_state_mismatch`
- `client_id_changed`
- `session_id_changed`
- `unexpected_session_start`
- `self_referral`
- `attribution_changed`
- `destination_event_missing`
- `required_parameter_missing`
- `transition_not_exercised`
- `inconclusive`

Each failure must carry `confirmed`, `probable`, or `possible` confidence and the
evidence that supports that level.

## Malformed Transition Diagnosis

The tool should explicitly detect:

- `_gl` never added to an eligible link
- `_gl` removed by HTTP to HTTPS, www, locale, authentication, or application
  redirects
- Query strings discarded by a redirect or routing rule
- Double encoding or decoding of the linker value
- Linker placed in a query string while the destination expects a fragment, or
  the reverse
- Forms that were not configured for decoration
- JavaScript navigation that bypasses normal click decoration
- Event handlers that stop propagation before the linker can decorate the URL
- Delayed navigation that allows the linker to expire
- Destination tagging that runs before incoming linker state is processed
- Different Measurement IDs or GA4 properties on either side
- Consent default or update timing that changes identifier availability
- Cookie-domain settings that split subdomain sessions
- White-label routing that sends one customer into another customer's analytics
  configuration

## White-Label Requirements

White-label domains create a stronger tenancy requirement than ordinary
cross-domain linking.

- Every hostname and path pattern must be explicitly assigned to a tenant and
  customer.
- A shared platform domain must map the request to the correct customer before
  selecting its tagging blueprint or measurement destination.
- Linker allowlists must be customer-scoped; one customer's domains must not be
  accepted automatically by another customer's setup.
- A transition must verify both session continuity and destination ownership.
- Unexpected customer, container, property, or stream mapping is critical.
- Evidence and identifiers from one tenant must never appear in another
  tenant's report.
- Shared containers must have explicit domain-routing rules and tests for every
  customer association.

## Relationship to `(not set)` and Attribution

A broken transition can surface as:

- `(not set)` landing-page or session dimensions
- New sessions on the destination domain
- Self-referrals
- `(direct) / (none)` attribution
- Lost campaign parameters
- Purchases separated from their acquisition session
- Missing or duplicated session-start events
- Different client or session identifiers across the journey

When these symptoms appear, the diagnostic engine should search the customer's
declared transition graph and correlate the affected destination pages with
recent continuity failures.

## Scoring

- Score `0`: a required same-session transition is reproducibly broken and the
  evidence confirms where continuity failed.
- Score `1`: analytics shows a critical continuity symptom, but the controlled
  journey cannot reproduce or conclusively classify the break.
- `partial`: one or more required transitions could not be exercised or lacked
  sufficient evidence.
- Normal scoring is available only when all required transitions passed or were
  explicitly declared not applicable.

## Privacy and Evidence Storage

The raw `_gl` value and raw analytics identifiers must not be displayed or
stored in findings. They can contain linkable measurement identifiers.

Store only what is required to prove continuity:

- Parameter presence, position, length, and validation result
- A one-way keyed hash for comparing source and destination identifiers
- Redacted URL and redirect-chain evidence
- Tag IDs, property IDs, and stream IDs where the tenant is authorized to see
  them
- Consent-state labels
- Timestamps and classification

Strip authentication tokens, personal information, form values, and unrelated
query parameters before persistence.

## Minimum Acceptance Scenarios

1. Same-session navigation between two root domains passes.
2. Same-session navigation between subdomains passes.
3. A redirect preserves `_gl` through every hop.
4. A redirect strips `_gl` and the tool identifies the exact hop.
5. A link works but a cross-domain form is not decorated.
6. JavaScript navigation bypasses linker decoration.
7. The destination uses the wrong GA4 property.
8. Client continuity passes but session continuity fails.
9. A self-referral or attribution reset is detected.
10. Consent differs across the source and destination.
11. A customer white-label transition preserves the session and correct tenant
    mapping.
12. A white-label domain mapped to the wrong customer is blocked and reported.
13. A required transition cannot be exercised and the audit becomes partial.
14. Stored evidence proves continuity without retaining raw linker or client
    identifiers.

## Data Model Direction

The existing `cross_domain_configs` table is a useful starting point but is not
enough for multi-hop journeys or white-label routing. The eventual model will
likely need:

- Domain nodes with tenant/customer ownership and type
- Directed transition edges
- Transition test definitions
- Redirect-hop observations
- Hashed identifier comparisons
- Verification runs and outcomes
- Attribution before-and-after observations

This is a product requirement, not an approved schema migration. The database
design should be finalized alongside the first cross-domain implementation.
