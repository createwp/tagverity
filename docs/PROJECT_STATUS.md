# GTM Auditor Project Status

**Document type:** Living implementation record
**Last updated:** 2026-07-31
**Product specification:**
[`docs/superpowers/specs/2026-06-26-gtm-auditor-design.md`](superpowers/specs/2026-06-26-gtm-auditor-design.md)

## Purpose

GTM Auditor began as a multi-tenant audit product for Google Tag Manager
containers and Google Analytics 4 properties. Its direction has expanded into
an all-round tagging operations platform for agencies, standalone businesses,
and the product owner's own agency workflows.

The expanded direction is documented in [`docs/PRODUCT_VISION.md`](PRODUCT_VISION.md).
The acceptance standard for trustworthy audits is documented in
[`docs/AUDIT_DEFINITION_OF_DONE.md`](AUDIT_DEFINITION_OF_DONE.md).

An audit combines configuration and analytics data to produce structured
findings with:

- Severity: `critical`, `warning`, or `info`
- Category: container health, trigger quality, data quality, or implementation
- A plain-language explanation for marketers
- A recommended fix
- Technical developer notes where the plan permits them
- Fix ownership: GTM, developer, GA4 admin, hosting, or multiple parties
- Supporting evidence from the source that detected the problem

## Product Model

The tenant hierarchy is:

```text
Account
  -> Users
  -> Clients
       -> Google connections and discovered resources
       -> Domains and resource mappings
       -> Audit jobs
            -> Findings
```

Accounts can be `standalone` or `agency`. Both use the same client-based code
path; registration creates a default client for the new account.

The planned commercial tiers are Starter, Growth, Pro, and Agency, with a
one-time audit option. Feature access and limits are represented in both the
`plans` database table and `includes/plans.php`.

### Expanded Product Direction

The target product now includes:

- A searchable inventory of GTM accounts, containers, workspaces, versions,
  tags, triggers, and variables
- Tagging blueprints that preserve requirements, naming conventions,
  dependencies, trigger logic, and implementation notes
- Controlled creation and synchronization of tags, triggers, and variables into
  dedicated GTM workspaces
- Deployment review, preview, versioning, publication approval, and rollback
  history
- Browser-level verification that a published tag actually fired
- GA4-level verification that the expected event was received
- Page and DOM discovery for recommending robust trigger implementations
- AI-assisted event planning, tag generation, diagnosis, and repair suggestions
- A business-type onboarding wizard that creates preconfigured measurement packs
  and site-specific developer implementation documentation
- Human-friendly alerts for non-technical marketers with clear ownership,
  actions, and versioned technical handoffs for developers
- MCP as an optional AI orchestration layer over deterministic browser,
  crawler, GTM, GA4, and rules-engine capabilities
- A host-neutral remote MCP interface through which ChatGPT, Claude Code, and
  other approved clients can turn plain-language requirements into controlled
  blueprint, GTM workspace, verification, and publication workflows

The current implementation now provides scan-led onboarding, visual mapping,
a central tags-and-triggers workspace, and the first controlled release-review slice. External deployment remains
locked until a concrete adapter and browser verification exist.

## Technical Architecture

### Application

- Raw PHP with PDO and MySQL; no application framework or ORM
- Session-based authentication and role checks
- Server-rendered PHP pages
- Shared stylesheet at `assets/css/app.css`
- Shared browser logic at `assets/js/app.js`
- Composer autoloading for classes in the `GTMAuditor\` namespace
- WAMP64 for local development and SiteGround for production hosting

### Integrations

- Google OAuth 2.0 for GTM, GA4, BigQuery, and GCP discovery permissions
- Google Tag Manager Management API
- GA4 Admin and Data APIs
- BigQuery, planned for the Pro phase
- Stripe for subscriptions and one-time audits
- PHPMailer with SendGrid SMTP for transactional email
- Dompdf for planned PDF reports
- A separate Node.js and Playwright crawl service, planned for Phase 2

### Background Processing

Manual and scheduled audits are stored in `audit_jobs`. SiteGround cron runs
`cron/run-audits.php`, which asks `AuditJobRunner` to atomically claim the oldest
queued job using a transaction and `SELECT ... FOR UPDATE`.

The intended processing flow is:

```text
Audit request
  -> queued audit_jobs row
  -> cron claims the job
  -> Google and optional external data are fetched
  -> AuditEngine runs the applicable checks
  -> findings are persisted
  -> health score and job status are updated
```

## Important Files

| File | Responsibility |
|---|---|
| `docs/superpowers/specs/2026-06-26-gtm-auditor-design.md` | Intended product and roadmap |
| `sql/001_initial_schema.sql` | Initial 20-table database schema |
| `includes/config.php` | Environment bootstrap, constants, sessions, asset URLs |
| `includes/db.php` | Shared PDO connection |
| `includes/auth.php` | Current user, login, logout, and role guards |
| `includes/permissions.php` | Client and audit tenant-access guards |
| `includes/plans.php` | Plan feature matrix and limits |
| `includes/crypto.php` | AES-256-GCM refresh-token encryption |
| `includes/rate-limit.php` | Database-backed request limiting |
| `src/GoogleApiClient.php` | Google API wrapper methods |
| `src/AuditEngine.php` | Audit rules and health-score calculation |
| `src/AuditJobRunner.php` | Queue claiming, audit orchestration, and persistence |
| `api/trigger-audit.php` | Validates and queues manual audits |
| `api/job-status.php` | Reports whether an account has running audits |
| `api/oauth-callback.php` | Validates OAuth state and stores encrypted tokens |
| `src/SiteScanner.php` | Bounded public-HTML discovery with private-network protection |
| `src/OnboardingPlanner.php` | Questionnaire and draft measurement-plan generation |
| `src/SitePreviewRenderer.php` | Sandboxed static preview sanitisation and element-selection bridge |
| `src/ElementTemplateBuilder.php` | Deterministic event, trigger, and destination-tag draft generation |
| `src/PublishReadiness.php` | Destination, consent, verification, and deployment readiness checks |
| `src/TrackingWorkspace.php` | Merges proposed events, mapped triggers, saved event choices, and privacy policy |
| `onboarding.php` | Scan, verification, and measurement-plan onboarding flow |
| `element-mapper.php` | Visual element selection and intention-led trigger mapping |
| `tracking-workspace.php` | Central event, parameter, trigger, destination, and PII-policy configuration |
| `publish-tags.php` | Multi-select release review, approval, readiness, and publication gate |
| `dashboard.php` | Client audit summary and manual audit trigger |
| `audit-report.php` | Findings report grouped by severity and ownership |

## Implemented Functionality

### Authentication and Accounts

- Email and password registration
- Account, owner user, and default-client creation in one transaction
- Email and password login
- Session rotation on login and secure session-cookie settings
- Logout and authenticated-page redirects
- Database-backed login and registration rate limits
- Profile, account-name, and password updates
- Account-scoped `superadmin` role above owner/admin, while preserving client
  tenant-ownership checks

### Client and Plan Foundations

- Account-scoped client listing and creation
- Server-side maximum-client enforcement
- Starter, Growth, Pro, and Agency feature matrices
- Dashboard credit display and plan-aware report content
- Billing and plan-selection interface

### Google Integration Foundations

- OAuth consent URL generation with the required GTM, GA4, BigQuery, and cloud
  scopes
- OAuth state validation
- AES-256-GCM encryption of stored refresh tokens
- GTM account/container discovery methods
- GTM live-container version retrieval
- GA4 property and data-stream discovery methods
- Generic GA4 reporting queries and total-session retrieval

### Audit and Reporting Foundations

- MySQL-backed audit queue
- CLI-only cron entry point
- Concurrent-worker protection while claiming jobs
- Structured findings persistence
- Dashboard health score, finding counts, audit history, and credit usage
- Audit report filtering, severity summaries, recommendations, evidence, and
  plan-gated developer notes

### Scan-led Onboarding MVP

- Authenticated, client-scoped tracking setup page
- Bounded public-site scan of up to eight pages, with redirect, response-size,
  port, timeout, and private-network restrictions
- Basic `robots.txt` exclusions and sitemap discovery
- Detection of likely services, products, forms, multi-step forms, search,
  filters, SPA signals, ecommerce signals, analytics tags, and consent tools
- Canonical final-URL and form-fingerprint deduplication so redirects, sitemap
  variants, and repeated homepage URLs do not inflate page or form counts
- Service and product names restricted to relevant schema, meaningful
  breadcrumbs, or page headings rather than unrelated FAQ and organisation names
- Visible scan coverage and limitations instead of presenting a partial scan as
  complete knowledge of the site
- A generated verification questionnaire for business type, goals,
  destinations, offer names, product groups, form names, and ecommerce scope
- Stable draft event names enriched with page, form, service, product, route,
  step, field, and element parameters
- Automatic draft coverage for form view, start, field interaction,
  abandonment, and verified submission without collecting field values
- GA4-compatible ecommerce event recommendations when ecommerce is selected
- Server-side answer validation, tenant checks, CSRF protection, rate limiting,
  and explicit draft-only status

### Visual Element Mapping MVP

- Sandboxed static previews for pages captured by the latest site scan
- Removal of source scripts, event handlers, embedded frames, refreshes,
  executable URLs, and external form actions before preview rendering
- Browser-side hover and click selection with generated CSS selectors that
  prefer stable IDs and testing attributes
- Page-only or site-wide trigger scope, allowing one stable selector to cover
  repeated navigation and CTA elements without duplicating tags per page
- Plain-language questions for the element name, measurement goal, tracking
  intention, and optional ecommerce or search data key
- Deterministic recommendations for click, visibility, form lifecycle,
  ecommerce-value, and site-search triggers
- Reusable draft event, trigger, and destination-tag templates for GA4,
  PostHog, Google Ads, Meta, or a generic data layer
- Editing of existing mappings without automatic tag creation or publication
- Preview requests restricted to pages in the client's latest scan
- Full-screen mapping workspace with the website preview on the left, the tag
  panel on the right, and saved draft tags beneath the preview
- Capture of stable element IDs/classes plus form ID, classes, name, selector,
  field name/type, autocomplete hint, and associated label

### Controlled Tag Releases MVP

- Multi-select of draft mappings so unselected mappings remain outside the release
- Event-level selection within each mapping, including individual form lifecycle
  events, so unchecked events remain drafts even when other events from the same
  visual tag enter a release
- Editable release-time event names with lowercase GA4-compatible validation;
  immutable snapshots preserve both the recommended source name and approved name
- Every scan-recommended measurement-plan event is generated as an individually
  selectable release item with a matching page, click, visibility, form,
  ecommerce, search, or result-interaction trigger recipe and destination tags
- Immutable release snapshots with client-scoped version numbers and draft or
  approved states
- Readiness checks for browser verification, destinations, consent, GTM
  permissions, and deployment-adapter availability
- Publication is blocked and never labelled live while any required check or
  real external adapter is absent
- Light-only, Material-inspired global palette with Roboto, Google blue, white
  surfaces, accessible status colours, and material-style elevation

### Central Tags and Triggers Workspace

- One source-of-truth page connects onboarding proposals, visual mappings, and
  release review through a four-step happy path
- Each event can be included or excluded, renamed, and limited to an explicit
  subset of its available parameters before release review
- Tag rows are compact accordions with one editor open at a time; include or
  exclude remains available directly from the collapsed summary
- Single click and visibility mappings keep the user's mapper name as the
  analytics-safe custom event name while inheriting a reviewed system parameter
  template; explicitly saved names are never replaced by a later recommendation
- Compatible mapped elements can switch between click and visibility triggers
  without losing the custom event name; the parameter schema and trigger logic
  change together
- Trigger scope, selector, verification requirement, and destination tag
  templates are visible beside the event instead of being hidden in the mapper
- Status language distinguishes `Proposed—not active`, `Configured—not live`,
  and release-level `Approved—not live`
- Raw form values are always disabled; hashed email, phone, first name, and last
  name are separately opt-in and default off
- A browser-only SHA-256 demonstration explains normalisation and hashing without
  transmitting or saving the example value
- Mapper deep links open an existing mapping and provide a clear return to the
  central workspace; saved choices preselect release review
- Mapped tags can be removed through a confirmed soft-archive action; prior
  release snapshots remain immutable and releases containing an archived tag are
  blocked from later publication

### Current Audit Rules

`AuditEngine` currently contains 16 checks:

1. Duplicate GA4 configuration tags
2. Paused tags
3. Tags firing on unverified domains
4. Tags that have never fired
5. GA4 event tags firing before configuration
6. Missing Consent Mode configuration
7. Purchase triggers without value or transaction filters
8. Referenced variables that are not defined
9. Very low scroll-depth thresholds
10. Fragile CSS selectors in click triggers
11. Missing History Change triggers for detected SPAs
12. Duplicate trigger conditions
13. Excessive `(not set)` landing pages
14. Purchases missing transaction IDs
15. Internal query parameters in page locations
16. Unexpected domains sending data to GA4

The health score begins at 100. Critical findings subtract 15 points, warnings
subtract 5, and informational findings subtract 1. The result is clamped between
0 and 100.

## Current User Flow

The implemented path is:

```text
Register or log in
  -> account and default client
  -> dashboard
  -> Set up tracking
  -> scan a public website sample
  -> verify the detected business meaning
  -> review a draft measurement plan
  -> select page elements in a safe visual preview
  -> save reusable element mappings
  -> configure events, parameters, triggers, destinations, and privacy centrally
  -> review selected events for a versioned release
  -> save or approve the release
  -> resolve readiness blockers before external publication
```

The earlier Google connection and audit path still exists, but its discovery and
data-hydration steps remain incomplete. Onboarding and visual mapping create
draft plans and templates. The release workflow can version and approve selected
drafts, but it does not yet observe live SPA behaviour, create platform tags, or
publish externally.

## Incomplete or Stubbed Work

### Phase 1 Blockers

- `api/oauth-callback.php` does not yet extract the Google email or subject ID.
- OAuth completion does not yet run auto-discovery or populate GTM containers,
  GA4 properties, streams, BigQuery datasets, domains, or mapping tables.
- `src/AuditJobRunner.php` currently passes empty GTM and GA4 datasets to the
  audit engine. A job can therefore complete with no findings and a score of 100
  without performing a real audit.
- `domain-map.php` is a text stub; the SVG relationship map and domain
  verification workflow are not implemented.
- Billing pages exist, but `api/create-checkout.php` and the Stripe webhook flow
  do not exist.
- PDF export is a placeholder link.
- Sidebar and dashboard links reference missing `audits.php`, `monitoring.php`,
  and `bigquery.php` pages.

### Later Phases

- Tag, trigger, variable, and destination template registries
- Live browser and SPA observation for routes, dynamic forms, field
  abandonment, and successful completion signals
- Ecommerce visual variable mapping and native platform connectors
- Consent-management, server-side collection, identity, CAPI, CRM, and email
  preference integrations
- Headless crawl service and implementation-category audit checks
- Redirect and SPA behaviour analysis
- Scheduled monitoring and alert delivery
- BigQuery discovery and event-level queries
- PDF generation and white labelling
- Webhook and Slack alerts
- Agency client workspaces and bulk audit views

## Known Implementation Concerns

These observations require resolution before the audit workflow is considered
reliable:

- The audit-credit query in `api/trigger-audit.php` discards the prepared
  statement result and uses `FOUND_ROWS()`, so credit enforcement is incorrect.
- Dashboard polling only begins for jobs already marked `running`. A newly
  queued job may not automatically refresh after cron completes it.
- The current Google login button uses the connection callback rather than a
  complete application sign-in flow.
- The OAuth connection row uses an empty `google_sub` until identity extraction
  is implemented, limiting reliable multi-connection upserts.
- The product design calls for jQuery, while the implemented frontend currently
  uses vanilla JavaScript and `fetch()`.
- `assets/js/app.js` includes a native `window.confirm()` helper, while the
  project working conventions require application modals.
- Some navigation and feature interfaces were built ahead of their backing
  endpoints.

This is an implementation-status record, not a full security or code review.
Additional findings should be recorded when formal review begins.

## Security Model

The project expects these rules to remain non-negotiable:

- Use PDO prepared statements for all variable SQL values.
- Escape user-controlled HTML output with `htmlspecialchars()`.
- Require authentication on protected pages and endpoints.
- Verify client or audit ownership before accessing tenant-scoped data.
- Validate CSRF tokens on state-changing requests.
- Enforce plan restrictions on the server.
- Keep credentials and encryption keys in `.env`, never source control.
- Encrypt Google refresh tokens before persistence.
- Apply rate limits to authentication, OAuth, and audit triggers.
- Keep cron, source, SQL, includes, environment files, and uploads inaccessible
  from the public web.

## Local Development Snapshot

As of 2026-07-30:

- Project URL: `http://localhost/gtm-auditor/`
- Apache runtime: PHP 8.1.0
- The system PATH points to PHP 7.4.26; validation commands must explicitly use
  `C:\wamp64\bin\php\php8.1.0\php.exe` or update the shell PATH.
- All PHP files pass syntax validation under the configured local PHP runtime.
- Composer dependencies are installed.
- MySQL is reachable and the two onboarding tables were added successfully;
  migration 002 was run twice to confirm idempotency.
- Migration 003 adds saved visual element mappings and was also run twice to
  confirm idempotency.
- Migration 004 adds the account-scoped `superadmin` role and was run twice to
  confirm idempotency.
- Migration 005 adds immutable, versioned tag releases and was run twice to
  confirm idempotency.
- Migration 006 adds page or global trigger scope and was run twice to confirm
  idempotency on the local MySQL version.
- Migrations 007 and 008 add per-event configuration and a client privacy policy;
  both were run twice to confirm idempotency.
- Migration 009 adds a compatible click/visibility trigger override and was run
  twice to confirm idempotency.
- The release APIs were exercised through Apache: an approved release was
  created and its publication attempt correctly returned HTTP 409 with four
  actionable blockers; the temporary test release was removed afterward.
- `tests/SiteScannerRegressionTest.php` covers duplicate forms, canonical URL
  variants, service-name filtering, product-name filtering, and form fields.
- `tests/ElementTemplateBuilderTest.php` covers page-restricted and site-wide
  trigger generation, runtime page URLs, and global verification requirements.
- `tests/ReleaseEventBuilderTest.php` covers scan-recommended page, click, and
  form trigger generation, recommendation selection, per-event filtering,
  approved-name overrides, destination updates, parameter opt-out, and validation.
- `tests/TrackingWorkspaceSmokeTest.php` loads the local schema and verifies
  saved configuration merging while raw PII remains disabled.
- The local database contains four plans and one account/user/client, with no
  Google connections, audit jobs, or findings.
- Login and registration pages render through Apache.
- Focused PHP regression and smoke tests cover the new onboarding and tracking
  workflow services; a full end-to-end browser suite is still pending.

## Repository Snapshot

The local repository is connected to
[`createwp/tagverity`](https://github.com/createwp/tagverity) as `origin`.

Repository history began with the original design specification. The reviewed
application and living-documentation baseline was added on 2026-07-29 before
implementation-plan work began. Local secrets, Composer dependencies, runtime
files, and outdated local agent metadata are excluded through `.gitignore`.

## Documentation Maintenance

When implementation work changes the project:

1. Update the relevant implemented or incomplete section in this document.
2. Record material architecture decisions in the decision log below.
3. Update the validation snapshot when runtime or verification commands change.
4. Remove resolved concerns instead of leaving obsolete warnings in place.
5. Update the product specification only when intended product behaviour changes.

## Decision Log

| Date | Decision |
|---|---|
| 2026-06-26 | Approved the initial GTM Auditor product design and four-phase roadmap. |
| 2026-07-27 | Established this living status document to track implementation reality separately from the product specification. |
| 2026-07-28 | Expanded the product direction from auditing into a tagging operations platform with blueprints, controlled GTM synchronization, deployment verification, and AI-assisted tagging. |
| 2026-07-28 | Defined that an audit must never present missing source data as a clean result and that tag verification must distinguish configured, published, browser-fired, and GA4-received states. |
| 2026-07-28 | Defined `(not set)` as a critical diagnostic trigger. A confirmed reproducible implementation failure scores 0; a critical but inconclusive or non-reproducible failure scores 1. |
| 2026-07-28 | Required root-cause timelines across GTM configuration, DOM and data-layer timing, consent, browser execution, network delivery, and GA4 receipt. |
| 2026-07-28 | Made cross-domain session continuity a first-class audit capability covering subdomains, separate root domains, redirects, forms, applications, checkout flows, and customer white-label domains. |
| 2026-07-28 | Added a business-aware onboarding wizard and positioned the product as an ongoing Tagging Partner that produces measurement blueprints, GTM drafts, and developer-ready implementation packs. |
| 2026-07-28 | Established non-technical marketers as the primary alert audience and required every developer-owned issue to provide a versioned technical handoff with evidence and acceptance tests. |
| 2026-07-28 | Adopted a hybrid diagnostics architecture: deterministic browser collection and rules first, with MCP exposing on-demand tools to AI rather than invoking AI continuously while users browse. |
| 2026-07-28 | Confirmed that GTM writes are executed through the Google Tag Manager API; MCP may expose approved AI-facing wrappers but remains optional and never receives or passes through customer Google tokens. |
| 2026-07-28 | Adopted one host-neutral remote MCP server for plain-language tagging through ChatGPT, Claude Code, and compatible clients, with separate preview, apply, version, verify, and publish actions. |
| 2026-07-29 | Selected `TagVerity` as the customer-facing working name, subject to domain and trademark clearance; retained `GTM Auditor` as the technical repository name for now. |
| 2026-07-30 | Chose scan-led onboarding as the first tagging-platform vertical slice: preserve scan evidence, ask only for business verification, and create a draft measurement plan without automatic publication. |
| 2026-07-30 | Added a sandboxed static visual mapper as the next low-cost slice; live scripts remain disabled and every generated selector and template requires later browser verification. |
| 2026-07-30 | Added an account-scoped superadmin role without cross-tenant bypass; platform-wide tenant impersonation remains a separate audited capability. |
| 2026-07-30 | Separated approval from publication: approval freezes a release snapshot, while publication requires verification, consent, destination, permission, and adapter checks. |
| 2026-07-30 | Adopted a light-only Material-inspired interface and full-screen visual mapper to prioritise website preview space. |
| 2026-07-31 | Made Tags & triggers the central source of truth between proposal, visual mapping, privacy configuration, and controlled release review. |
| 2026-07-31 | Adopted a unified stroke-SVG icon family and a sapphire, deep-navy, orange-accent, cool-neutral SaaS palette based on the approved visual references. |

## Change Log

### 2026-07-27

- Created the initial implementation-status record after reviewing the full
  repository, local schema, runtime, and current application flow.

### 2026-07-28

- Added the expanded product vision.
- Added the first audit definition of done.
- Recorded the proposed tagging blueprint, controlled deployment, browser
  observation, GA4 verification, and AI safety boundaries.
- Added the `(not set)` root-cause pipeline, evidence timeline, confidence model,
  and website-versus-browser blocking distinction.
- Added the cross-domain transition graph, session-continuity checks, linker
  diagnosis, attribution checks, and white-label tenant-isolation requirements.
- Added guided onboarding for ecommerce, lead generation, subscription, and
  membership businesses with hybrid profiles and developer handoffs.
- Added marketer-friendly alert, error-message, action-routing, and developer
  handoff content standards.
- Added the MCP and real-browser diagnostics feasibility decision, low-cost
  architecture, phased rollout, and AI usage controls.
- Clarified that GTM tags, triggers, variables, versions, and publication are API
  operations that optional MCP tools can orchestrate through the core app.
- Added the natural-language MCP interface, cross-host tool contract, approval
  boundaries, tenant controls, voice limitation, and staged delivery sequence.
- Added proposed product strategy guidance covering the measurement contract,
  lineage, trust and coverage model, drift detection, change impact, initial
  agency wedge, release boundary, and deferred scope.
- Added the first repository-specific implementation plan, beginning with audit
  target and migration safety, then Google discovery, real source hydration,
  trustworthy scoring, a Material-inspired interface, and one controlled
  browser-to-GA4 verification journey.

### 2026-07-29

- Selected `TagVerity` as the customer-facing working name, while keeping `GTM
  Auditor` as the internal implementation name until domain and trademark
  clearance is complete.
- Connected the local repository to `https://github.com/createwp/tagverity.git`
  as `origin` and prepared the reviewed application and documentation baseline
  for publication.

### 2026-07-30

- Added the scan-led onboarding migration, public-site scanner, verification
  questionnaire, stable-event measurement-plan generator, protected APIs,
  responsive setup page, and sidebar entry.
- Added explicit scan coverage, privacy boundaries, private-network protection,
  and draft-only publication status.
- Added safe visual page previews, element selection, intention-led trigger
  recommendations, saved mappings, and GA4, PostHog, Google Ads, Meta, and
  generic data-layer tag drafts.
- Added migration 004 and role handling for an account-scoped superadmin login.
- Added migration 005, tag release APIs, multi-select review and approval,
  publication blockers, release history, and GTM write/version/publish scopes.
- Converted the shared interface to a light Material-inspired palette and added
  the full-screen mapper, right-side tag panel, bottom draft tray, and richer
  form/field identifier capture.
- Fixed duplicate page/form scan results and filtered service/product discovery
  to exclude FAQ, breadcrumb-home, and organisation-name noise.
- Added page/site-wide visual trigger scope with multi-page verification
  blockers for global selectors.
- Added event-level release selection and editing, including a selectable base
  `page_view`; release snapshots keep unchecked events out and preserve the
  original recommended name for audit history.

### 2026-07-31

- Added the central Tags & triggers workspace, event include/exclude controls,
  editable names, parameter selection, visible trigger and destination details,
  and unambiguous not-live lifecycle labels.
- Converted tag editors to compact single-open accordions and preserved mapper
  names as custom event names while inheriting parameters from compatible system
  events; click and visibility templates can be switched in place.
- Added raw-PII blocking, explicit hashed-identifier opt-in, selected field
  policy, browser-only SHA-256 education, and privacy snapshots in releases.
- Connected onboarding, visual mapping, and release review with return links,
  mapper deep links, and shared saved configuration.
- Replaced emoji and text-symbol interface icons with a shared dependency-free
  SVG system across navigation, authentication, dashboards, alerts, empty states,
  connections, clients, and audit reports.
- Refined the light SaaS visual system around sapphire actions, deep navy
  grounding, restrained orange accents, cool neutral surfaces, softer borders,
  modern elevation, and an accessible in-app confirmation modal.
- Added confirmed soft deletion for mapped tags and a publication guard that
  prevents deleted tags from being reintroduced through an older release.
- Made Dashboard, Setup tracking, Tags & triggers, Element mapper, and Releases
  fluid-width so their content uses the available desktop workspace; retained
  readable side panels and responsive single-column layouts at narrower widths.
- Converted Setup tracking into a four-stage, single-panel wizard with clickable
  completed steps, Previous/Continue controls, a dedicated business-meaning
  review, and a visual final handoff to Tags & triggers instead of a long event
  dictionary.
- Simplified Tags & triggers into a non-linear workspace by removing its large
  process stepper and status guide, using compact counters and hover/focus status
  help, and moving SHA-256 examples into a field-aware browser-only lightbox.
- Changed release review to show only new or modified tracking compared with the
  latest approved/published version, and made visual preview plus live trigger
  verification an explicit pre-publication stage.
- Added a versioned identifier processor used by both the browser demonstration
  and event enrichment contract: allowlisted email and names are trimmed and
  lowercased, phones require E.164, Google destinations apply Gmail-specific
  rules, consent is checked before processing, only SHA-256 outputs enter event
  user-data payloads, and releases store processing rules rather than values.
- Extended that contract to actual browser event objects: common form-field
  aliases resolve to canonical email, phone, full-name, first-name, and
  last-name fields; names collapse repeated whitespace; raw identifier keys are
  removed recursively; and only consented, allowlisted hashes can enter the
  destination-ready `user_data` payload. External delivery remains disabled
  until a verified collector or deployment adapter is implemented.
- Added one shared responsive application shell for every authenticated page:
  a fixed mobile header, accessible hamburger control, focus-managed slide-out
  navigation, backdrop and Escape-key dismissal, scroll locking, 44-pixel touch
  targets, stacked cards and actions, horizontally scrollable tables and tabs,
  and reduced-motion support.
- Standardised page headers around one shared gutter, title/subtitle hierarchy,
  compact action area, sentence-case labels, and client context in the subtitle.
  Removed the duplicate Tags & triggers body heading and status legend, then
  combined its filters and three useful counts into one compact toolbar.
- Aligned the Account settings tabs, alerts, and active form card to one shared
  constrained content column so their left and right edges remain consistent.
- Expanded Tags & triggers to show every event recommended by the measurement
  plan in one continuous list with mapped events, using inline source labels,
  source/tag/trigger filters, search, page-size controls, pagination, compact
  add/remove and edit actions, trigger coverage, and release-ready platform tags.
- Separated trigger type from delivery destination throughout the workspace:
  element clicks remain click triggers, a missing destination is shown as Not
  assigned instead of Data layer, and releases with unassigned delivery are blocked.
