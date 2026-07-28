# GTM Auditor Implementation Plan

**Document type:** Repository-specific delivery plan
**Last updated:** 2026-07-28
**Status:** First draft for product-owner review
**Primary milestone:** First trustworthy audit and verification loop

## Starting Point

Do not begin with MCP, autonomous tag creation, monitoring, or billing. Begin by
making one audit trustworthy from resource selection through evidence-backed
reporting.

The current blocking sequence is:

```text
No explicit audit target
  -> OAuth discovery is incomplete
  -> AuditJobRunner has no selected resources to fetch
  -> empty GTM and GA4 arrays reach AuditEngine
  -> a job can complete with a misleading score of 100
```

The implementation order must reverse that chain:

```text
migration and test foundation
  -> Google identity and resource discovery
  -> explicit measurement profile and resource mapping
  -> real GTM and GA4 data hydration
  -> source and rule completion records
  -> trustworthy report
  -> one controlled browser-to-GA4 verification journey
```

## First Release Boundary

The first trustworthy release is complete when an authorized user can:

1. Connect a Google account and see the verified Google identity.
2. Select a customer and map one production measurement profile containing a
   domain, GTM container, GA4 property, and web stream.
3. Queue an audit against that exact profile.
4. See which GTM and GA4 sources succeeded, failed, or could not be evaluated.
5. Receive rule-level pass, fail, warning, not-applicable, and unable results.
6. Receive no health score when required evidence is unavailable.
7. Exercise one declared conversion journey in a controlled browser.
8. Trace the event from trigger conditions to request delivery and GA4 receipt.
9. Receive a marketer-readable result and developer handoff when it fails.
10. Re-run the journey and mark it verified after the fix.

The release will not create or publish GTM entities. It establishes the trusted
operations that blueprint deployment and MCP tools will later call.

## Architecture Decisions for This Milestone

### Measurement Profile

A customer may have multiple profiles, such as production website, staging
website, application, or white-label deployment. Each audit targets one profile
and snapshots its selected resources at queue time.

Initial profile fields:

- Customer
- Name
- Environment: `development`, `staging`, or `production`
- Primary domain
- Google connection
- GTM container
- GA4 property
- GA4 web stream
- Time zone
- Active or archived status

This prevents the worker from silently selecting the first available Google
connection or container.

### Execution and Trust Are Separate

Keep job execution state separate from audit trust:

- Execution: `queued`, `running`, `complete`, or `failed`
- Trust: `pending`, `verified`, `at_risk`, `broken`, or `incomplete`

`complete` means the worker finished. It does not mean the measurement is
healthy. `incomplete` means required evidence or coverage was unavailable, and
therefore no normal health score is allowed.

### Deterministic Core

Google collection, source status, rule execution, scoring, evidence storage,
and verification gates remain deterministic PHP operations. AI explanation is
not required for this release.

## Work Package 0: Repository and Migration Safety

### Objective

Create a reversible baseline and a repeatable way to evolve the installed
schema before changing runtime behaviour.

### Files

- Create `run-migrations.php`
- Create `tests/run.php`
- Create `tests/bootstrap.php`
- Create `tests/fixtures/`
- Update `.gitignore` only if local test output requires it

### Tasks

1. Review and commit the current untracked application and documentation as an
   intentional baseline before feature work.
2. Add an authenticated, environment-restricted migration runner following the
   project's numbered, idempotent migration convention.
3. Record each migration result and make repeat execution safe.
4. Add a dependency-free CLI test harness using PHP assertions and explicit
   pass/fail output. Do not add PHPUnit without approval.
5. Capture sanitized fixtures for a healthy container, missing-source audit,
   `(not set)` condition, trigger failure, and wrong destination.

### Exit Gate

- Migrations can run twice without corrupting the schema.
- The PHP CLI test command returns a non-zero exit code on failure.
- Existing PHP files still pass PHP 8.1 syntax validation.
- The baseline is recoverable through Git without discarding user work.

## Work Package 1: Audit Target and Evidence Schema

### Migration 002: Measurement Profiles and Audit Target

Create `measurement_profiles` with:

- `id`, `client_id`, `name`, `environment`
- `primary_domain_id`
- `google_connection_id`
- `gtm_container_id`
- `ga4_property_id`, `ga4_stream_id`
- `timezone`, `status`, `created_at`, `updated_at`

Add to `audit_jobs`:

- `measurement_profile_id`
- `trust_status`
- `ruleset_version`
- `scoring_version`
- `reporting_window_start`, `reporting_window_end`
- `coverage_summary` JSON
- `target_snapshot` JSON

Existing jobs remain readable. New jobs require a measurement profile after the
mapping interface ships.

### Migration 003: Source and Rule Results

Create `audit_source_results` with:

- Audit job and source identifier
- Required flag
- Status: `success`, `not_applicable`, `permission_denied`,
  `authentication_failed`, `quota_limited`, `timed_out`, `unavailable`, or
  `insufficient_coverage`
- Error code and human-readable message
- Coverage and metadata JSON
- Start and completion timestamps

Create `audit_rule_results` with:

- Audit job, rule identifier, and rule version
- Result: `pass`, `fail`, `warning`, `unable`, or `not_applicable`
- Severity, affected item, and evidence JSON
- Created timestamp

Keep `audit_findings` for user-facing actionable failures. A rule result proves
that a rule ran even when it passed and produced no finding.

### Migration 004: Resource Mapping Integrity

Add idempotent unique indexes for the selected-resource tables:

- GTM container uniqueness per customer and Google resource
- GA4 property uniqueness per customer and Google property
- GA4 stream uniqueness per property and stream ID
- Domain uniqueness per customer and normalized host

Do not remove the current client foreign keys in this release. A later resource
model migration can add many-to-many sharing when a real shared-container case
is implemented.

### Exit Gate

- An audit job names exactly which customer resources and environment it uses.
- Every required source has a persisted result.
- Every applicable rule has a persisted result.
- An empty findings list cannot be interpreted as success by itself.

## Work Package 2: Google Identity and Resource Discovery

### Files

- Modify `connections.php`
- Modify `api/oauth-callback.php`
- Modify `src/GoogleApiClient.php`
- Create `src/GoogleResourceService.php`
- Create `api/get-google-resources.php`
- Create `api/save-measurement-profile.php`
- Replace the stub in `domain-map.php`
- Create `assets/js/domain-map.js`
- Modify `assets/css/app.css`

### Tasks

1. Request `openid` and `email` and verify the returned ID token so
   `google_email` and `google_sub` are never stored as empty identifiers.
2. Use read-only GTM and Analytics scopes for the first release. Request
   BigQuery, GTM edit, version, and publish scopes later through incremental
   consent.
3. Store the scopes actually granted by Google, not only the locally requested
   scope list.
4. Make `GoogleApiClient` distinguish an empty successful response from an API
   failure. Do not swallow both into `[]`.
5. List available GTM containers and GA4 properties/streams for the selected
   Google connection.
6. Let the user select a customer, environment, domain, container, property,
   and stream before saving a measurement profile.
7. Normalize and validate domains server-side. Reject private, local, or
   unsupported targets.
8. Enforce account and customer ownership on every discovery and save endpoint.
9. Show missing permission, expired connection, and empty-resource states in
   marketer language with a specific reconnect or selection action.

### API Contract

`GET api/get-google-resources.php?connection_id=...`:

```json
{
  "ok": true,
  "data": {
    "connection": {"id": 1, "email": "user@example.com"},
    "gtm_containers": [],
    "ga4_properties": []
  }
}
```

`POST api/save-measurement-profile.php` accepts JSON and returns:

```json
{
  "ok": true,
  "data": {"measurement_profile_id": 1}
}
```

### Exit Gate

- OAuth reconnect updates the correct Google connection using `google_sub`.
- A user cannot view or assign another account's connection or resources.
- A saved profile displays the exact domain, container, property, stream, and
  environment that future audits will target.

## Work Package 3: Queue and Worker Correctness

### Files

- Modify `api/trigger-audit.php`
- Modify `api/job-status.php`
- Modify `src/AuditJobRunner.php`
- Create `src/AuditDataLoader.php`
- Modify `cron/run-audits.php` only if additional worker reporting is needed

### Tasks

1. Require an authorized `measurement_profile_id` when queueing an audit.
2. Fix credit counting by fetching the prepared `COUNT(*)` result directly.
3. Prevent duplicate queued or running jobs for the same profile unless the
   previous job is explicitly cancelled or failed.
4. Store an immutable target snapshot with the job.
5. Poll both queued and running jobs and report the current job status rather
   than only a global running boolean.
6. Load the Google connection and resources from the target snapshot.
7. Collect each source independently so a GA4 failure does not discard valid
   GTM evidence.
8. Mark trust `incomplete` and leave `health_score` null whenever a required
   source fails.
9. Delete any old findings and rule results inside the same transaction before
   a deliberate job retry.
10. Update connection `last_used_at` only after a successful authenticated API
    operation.

### Exit Gate

- A newly queued job is visible and polled immediately.
- Concurrent workers cannot claim the same job.
- Failed Google access produces an incomplete or failed report, never 100.
- Retrying a job does not duplicate findings.

## Work Package 4: Real GTM and GA4 Data Hydration

### GTM Collection

Extend `GoogleApiClient::getContainerVersion()` to return:

- Live version and container identifiers
- Tags, triggers, variables, folders, and relevant built-in variables
- Fingerprints and paused state
- A normalized collection status

Persist the live version identifier and a redacted normalized snapshot used by
the rule engine.

### GA4 Collection

Add focused methods rather than one unbounded reporting endpoint:

- Landing pages and sessions
- Total sessions
- Purchases and transaction IDs where applicable
- Hostnames and sessions
- Page locations needed by implemented rules
- Event names and counts needed by the selected contract
- Property metadata and quota information where available

Batch compatible reports where practical, cache by property and reporting
window, and record quota errors distinctly from empty reports.

Official references:

- [GTM API resources](https://developers.google.com/tag-platform/tag-manager/api/reference/rest)
- [GA4 Data API reports](https://developers.google.com/analytics/devguides/reporting/data/v1/basics)
- [GA4 Data API quotas](https://developers.google.com/analytics/devguides/reporting/data/v1/quotas)

### Exit Gate

- Sanitized fixtures prove the normalizer matches actual Google response shapes.
- Each current audit rule receives the keys and types it expects.
- A valid zero-row report remains a successful source with zero rows.
- Authentication, permission, quota, timeout, and malformed-response failures
  remain distinguishable in storage and the report.

## Work Package 5: Trustworthy Rules, Scoring, and Reporting

### Files

- Modify `src/AuditEngine.php`
- Create `src/AuditOutcome.php` only if result assembly cannot remain clear in
  `AuditJobRunner`
- Modify `audit-report.php`
- Modify `dashboard.php`
- Modify `assets/js/app.js`
- Modify `assets/css/app.css`
- Add rule fixtures under `tests/fixtures/`

### Tasks

1. Give every rule a stable identifier and version.
2. Record applicability and a rule result before creating a finding.
3. Remove explanations that claim a single root cause when only a symptom was
   observed. Use confirmed, probable, possible, or inconclusive language.
4. Apply the `(not set)` hard gate only to fields declared required by the
   measurement profile or contract.
5. Calculate a score only after every required source and applicable rule has a
   valid outcome.
6. Show trust status and coverage before the secondary numeric score.
7. Display source failures and untested rules prominently.
8. Show evidence through progressive disclosure so the first view remains
   useful to non-technical marketers.
9. Include fix owner and a developer handoff action for developer-owned issues.
10. Replace native confirmation patterns with the application modal system.

### Minimum Rule Fixtures

- Healthy container and GA4 data
- Required GTM source denied
- Valid GA4 response with zero rows
- Required landing page containing `(not set)`
- Missing purchase transaction ID for an ecommerce profile
- Undefined GTM variable
- Published container absent
- Unexpected hostname
- Rule not applicable to the selected business profile

### Exit Gate

- All current rules have at least one passing and one failing fixture where
  applicable.
- Score 100 is possible only when required checks explicitly passed.
- A partial audit has no normal health score.
- Reports answer what ran, what failed, what could not run, why it matters, and
  what happens next.

## Work Package 6: Material-Inspired Product Interface

### Design Direction

Use Material Design 3 as interaction guidance, not as a framework dependency.
Implement the design with the existing PHP, jQuery, and vanilla CSS stack.

The interface should remain a focused operational product: compact navigation,
clear hierarchy, list-detail views, supporting evidence panes, and predictable
controls. Avoid turning dashboard sections into decorative floating cards.

Official references:

- [Material Design 3](https://m3.material.io/)
- [Material interaction states](https://m3.material.io/foundations/interaction/states/overview)
- [Material canonical layouts](https://m3.material.io/foundations/layout/canonical-examples/overview)
- [Material motion](https://m3.material.io/styles/motion/overview)

### Design Tokens in `assets/css/app.css`

Add role-based tokens for:

- Primary, secondary, success, warning, error, and neutral colours
- Surface, surface-container, border, text, and muted text
- Focus rings and state layers
- Spacing and maximum content widths
- Elevation used only for modals, menus, and genuinely raised controls
- Motion durations such as fast, standard, and emphasized
- Standard and decelerating easing curves

Keep card radii at 8px or less and maintain distinct neutral, success, warning,
and error colours rather than a one-hue interface.

### Purposeful Motion

Use subtle motion to direct attention when:

- An audit changes from queued to running to complete
- A source connects or fails
- A verification timeline advances to the next stage
- A new critical finding enters the report
- A user expands evidence or a developer handoff
- A cross-domain transition succeeds or breaks
- A saved change is confirmed

Use restrained opacity, transform, progress, and state-layer transitions. Do
not use continuous pulsing, bouncing, decorative background motion, or movement
that changes layout dimensions.

### Accessibility and QA

- Every state uses at least two cues, such as icon plus colour or text plus
  progress, rather than colour alone.
- Preserve visible keyboard focus and usable hover, focus, pressed, loading,
  disabled, success, and error states.
- Implement `prefers-reduced-motion: reduce` so meaningful state remains visible
  without transforms or nonessential animation.
- Keep touch targets usable and text inside controls at stable dimensions.
- Test list-detail and supporting-pane layouts at compact, medium, and expanded
  widths.
- Verify every changed screen through desktop and mobile browser screenshots
  before accepting the work package.

### Exit Gate

- Motion communicates a state change or action outcome rather than decorating
  the page.
- No animation is required to understand the result.
- Reduced-motion users receive the same information.
- Loading states cannot resize controls or shift surrounding content.
- Audit status, source state, severity, and verification progress are visually
  consistent throughout the product.

## Work Package 7: First Browser-to-GA4 Verification Journey

### Dependency and Hosting Gate

The controlled browser requires a proven browser engine such as Playwright. It
is a new npm dependency and requires explicit approval before installation.
SiteGround shared hosting cannot be assumed to run the browser worker; use a
separate worker service or an approved external runtime.

### Initial Scope

Support one saved public journey with:

- Start URL and allowed domains
- Optional consent action
- One click or form action
- Expected data-layer event
- Expected GTM tag or analytics request
- Expected GA4 event name
- Required parameters
- Timeout and retry policy

### Components

- Separate controlled browser worker
- Authenticated PHP job creation and result-ingestion endpoints
- `verification_runs` and `verification_observations` migrations
- Redacted event timeline
- Request destination and payload-key validation
- GA4 receipt retry job
- Marketer result and developer evidence view

### GA4 Receipt Confidence

Use the browser request as immediate proof that the site sent the event. Use the
GA4 Realtime Data API with bounded retries to look for receipt in the selected
property. Realtime reports support event-level reporting but may not uniquely
identify one browser execution without a registered correlation dimension.

Therefore:

- Label a unique correlation match as confirmed.
- Label an event-name, stream, and time-window match as probable when other
  traffic could produce the same event.
- Use a declared `audit_verification_id` custom dimension or BigQuery event data
  for confirmed high-volume-property matching in a later increment.
- Never turn a timeout into proof that GA4 rejected the event; report the lookup
  window and evidence limitations.

References:

- [GA4 realtime reports](https://developers.google.com/analytics/devguides/reporting/data/v1/realtime-basics)
- [GA4 realtime method](https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runRealtimeReport)

### Exit Gate

- The trigger is proven exercised before a tag is called failed.
- DOM, data-layer, consent, tag/request, navigation, and receipt observations
  share one ordered timeline.
- Sensitive form values, cookies, tokens, and personal data are absent from
  stored observations.
- A passing fixture proves browser send and GA4 receipt.
- Failure fixtures distinguish trigger not exercised, tag not fired, request
  blocked, wrong destination, GA4 not observed, and invalid payload.

## Acceptance Test Matrix

The milestone cannot ship until these scenarios pass:

1. A healthy mapped profile completes with successful sources and explicit rule
   passes.
2. An empty source payload cannot produce a healthy audit unless zero rows are a
   valid, explicitly handled result.
3. Expired OAuth produces a reconnect action and no score.
4. GTM permission denial preserves available GA4 evidence but marks trust
   incomplete.
5. Another tenant cannot use a connection, profile, job, report, or evidence ID.
6. Two workers cannot process the same queued job.
7. A queued job is polled before it becomes running.
8. A required `(not set)` field activates the critical gate.
9. A trigger not exercised is inconclusive, not a tag failure.
10. A browser request sent to the wrong property is broken with confirmed
    destination evidence.
11. A GA4 receipt timeout reports its window and confidence limitation.
12. Desktop and mobile layouts show no overlap, clipped controls, or inaccessible
    evidence.
13. Reduced-motion mode preserves every state and result.

## Delivery Order After the First Trustworthy Release

1. Add measurement contracts and the first lead-generation and ecommerce
   onboarding packs.
2. Add searchable GTM inventory and measurement lineage.
3. Add blueprint drafting and naming-convention validation.
4. Add read-only MCP resources and diagnostic tools.
5. Add GTM workspace previews and approved structured writes.
6. Add deployment verification, drift detection, and repair workflow.
7. Add version creation and manual publication approval.
8. Add broader destinations, authenticated journeys, cross-domain coverage, and
   server-side tagging in separate increments.

## First Implementation Session

The first coding session should contain only:

1. Establish the Git baseline after reviewing the current untracked files.
2. Add `run-migrations.php`.
3. Implement Migration 002 for measurement profiles and audit target fields.
4. Add the dependency-free test harness.
5. Add tests proving the migration is idempotent and the profile-to-customer and
   profile-to-resource relationships reject invalid references.

Do not modify OAuth or `AuditJobRunner` until that foundation passes. The next
session can then implement verified Google identity and resource mapping against
a stable target model.

## Decisions Required Before Their Work Package Starts

- Which real customer or test property will be the first vertical-slice target?
- Is the first saved journey a lead submission or ecommerce purchase?
- Where will the Playwright browser worker run outside SiteGround?
- May the project add Playwright when Work Package 7 begins?
- Should a dedicated GA4 verification correlation dimension be part of customer
  onboarding?
- Should the initial Material-inspired refresh cover only the setup, dashboard,
  and audit report screens or all existing screens in the same milestone?
