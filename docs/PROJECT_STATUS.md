# GTM Auditor Project Status

**Document type:** Living implementation record
**Last updated:** 2026-07-29
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

The current implementation does not yet provide these expanded capabilities.

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
  -> connect Google account
  -> trigger an audit
  -> cron processes the queued job
  -> dashboard and audit report display results
```

The screens exist for most of this flow, but the Google discovery and audit data
hydration steps are not yet complete, so the full path is not operational.

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

As of 2026-07-27:

- Project URL: `http://localhost/gtm-auditor/`
- Apache runtime: PHP 8.1.0
- The system PATH points to PHP 7.4.26; validation commands must explicitly use
  `C:\wamp64\bin\php\php8.1.0\php.exe` or update the shell PATH.
- All 28 PHP files pass syntax validation under PHP 8.1.
- Composer dependencies are installed.
- MySQL is reachable and all 20 schema tables exist.
- The local database contains four plans and one account/user/client, with no
  Google connections, audit jobs, or findings.
- Login and registration pages render through Apache.
- There is no automated test suite.

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
