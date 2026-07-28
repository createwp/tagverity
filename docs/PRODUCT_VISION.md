# TagVerity Product Vision

**Document type:** Living product direction
**Last updated:** 2026-07-29
**Status:** Direction agreed; detailed requirements still evolving

## Vision

TagVerity should become an all-round tagging operations platform, not only a
tool that reports problems after they happen.

The platform should help a user understand, design, create, deploy, verify,
monitor, reuse, and improve analytics tagging across multiple customers and
multiple GTM resources. It should serve both marketing analytics and product
analytics use cases.

The intended outcome is:

```text
Understand the website and measurement requirements
  -> design a tagging blueprint
  -> create or reuse tags, triggers, and variables
  -> synchronize a draft to a GTM workspace
  -> review and approve the changes
  -> publish through a controlled process
  -> verify execution in the browser
  -> verify receipt in GA4
  -> monitor continuously and recommend fixes
```

## Product Pillars

### 1. Audit

Inspect GTM configuration, page behaviour, and analytics data. Explain problems
in plain language, show evidence, identify who owns the fix, and distinguish a
verified clean result from an audit that lacked data.

The primary audience is a non-technical marketer. Every finding and system error
must explain what happened, why it matters, where it occurred, who should act,
and the next step. Technical evidence should be available through progressive
disclosure rather than replacing the plain-language explanation.

When a developer owns the fix, the user must be able to generate a versioned
technical handoff containing reproduction steps, expected and actual behaviour,
evidence, implementation requirements, and acceptance tests. Detailed content
standards are maintained in
[`docs/ALERTS_AND_HANDOFFS.md`](ALERTS_AND_HANDOFFS.md).

### 2. Inventory

Give each tenant a searchable view of its customers and their connected Google
and GTM resources:

- GTM accounts and containers
- Workspaces and published versions
- Tags, triggers, variables, folders, and templates
- GA4 properties and data streams
- Domains, pages, and observed events
- Naming patterns, dependencies, and configuration history

A user should be able to open a trigger and understand its conditions without
having to reverse-engineer raw GTM JSON.

### 3. Tagging Blueprint

A blueprint is the durable design record for an analytics implementation. It is
separate from a specific GTM workspace so it can be reviewed, reused, copied,
and adapted later.

A blueprint should record:

- Business requirement and measurement question
- Event name and naming convention
- Marketing or product analytics purpose
- Platforms that receive the event
- Required and optional parameters
- Data types and example values
- Consent requirements
- Trigger conditions in human-readable and structured form
- Variables and data-layer dependencies
- Tag sequencing and setup dependencies
- Expected pages or components
- Verification steps and success criteria
- GTM resources created from the blueprint
- Version and change history

Reusable blueprint templates should support common patterns such as lead forms,
button clicks, ecommerce, funnels, video engagement, account signup, feature
usage, and subscription lifecycle events.

### 4. Controlled GTM Deployment

The platform should create tags, triggers, and variables through the GTM API,
but it must not treat direct production publication as the default action.

The deployment workflow should be:

1. Select the customer and target container.
2. Create or select a dedicated GTM workspace.
3. Translate the approved blueprint into structured GTM entities.
4. Validate naming, references, consent, and supported tag types.
5. Show a before-and-after diff.
6. Create the entities in the workspace.
7. Run GTM workspace status and preview checks.
8. Require an authorized user to approve the version.
9. Create a container version.
10. Publish only when the user has explicit publish permission and confirms the
    action.
11. Store the resulting GTM paths, fingerprints, version, and audit trail.

The GTM API supports workspaces and management of tags, triggers, variables,
versions, preview, and publication. Editing requires broader OAuth scopes than
the application's current read-only connection. Existing users will need an
incremental consent flow before write actions are enabled.

Official references:

- [GTM API overview](https://developers.google.com/tag-platform/tag-manager/api/v2)
- [GTM API authorization scopes](https://developers.google.com/tag-platform/tag-manager/api/v2/authorization)
- [GTM API REST resources](https://developers.google.com/tag-platform/tag-manager/api/reference/rest)
- [Create a GTM workspace](https://developers.google.com/tag-platform/tag-manager/api/reference/rest/v2/accounts.containers.workspaces/create)
- [Create a GTM tag](https://developers.google.com/tag-platform/tag-manager/api/reference/rest/v2/accounts.containers.workspaces.tags/create)
- [Create a GTM trigger](https://developers.google.com/tag-platform/tag-manager/api/reference/rest/v2/accounts.containers.workspaces.triggers/create)

### 5. Verification and Monitoring

A tag should have an observable lifecycle rather than a single active flag:

```text
designed
  -> workspace_draft
  -> version_created
  -> published
  -> trigger_exercised
  -> browser_fired
  -> analytics_received
  -> verified
```

This allows the product to locate failures accurately:

- Present in the blueprint but not synchronized
- Present in a workspace but not versioned
- Versioned but not published
- Published but the trigger condition was not exercised
- Trigger exercised but the tag did not fire
- Tag fired but no expected network request was sent
- Request sent but event not visible in GA4
- Event received but required parameters are missing or `(not set)`

Alerts should name the failed stage, show the evidence, and recommend the next
action. A generic "tag failed" message is not sufficient.

For critical data-quality symptoms such as `(not set)`, verification must build
an evidence timeline across GTM configuration, page and data-layer timing,
consent, trigger evaluation, browser execution, network delivery, and GA4
receipt. Its purpose is to explain whether a tag fired too early, too late, not
at all, or was blocked by the website, browser, consent state, or network.

Root-cause results must carry a confidence level. The product may call a cause
confirmed only when the evidence directly supports it. A clean controlled
browser can prove that the site works in that environment, but browser-specific
blocking remains probable or possible unless the affected browser supplies a
diagnostic observation.

Cross-domain and subdomain journeys are part of verification. The product must
model expected transitions between primary sites, applications, checkout
providers, forms, portals, and customer white-label domains. It should test each
transition and prove whether the same analytics user, session, attribution, and
consent context survived. Detailed requirements are maintained in
[`docs/CROSS_DOMAIN_MEASUREMENT.md`](CROSS_DOMAIN_MEASUREMENT.md).

### 6. AI-Assisted Tagging

AI should help translate a user's requirements and observed website structure
into a tagging blueprint. It may:

- Propose event and parameter taxonomies
- Apply or recommend naming conventions
- Suggest robust triggers from DOM evidence
- Identify reusable variables and trigger patterns
- Generate structured GTM entity drafts
- Explain conflicts and likely failure causes
- Recommend repairs when browser and GA4 observations disagree
- Produce implementation instructions for developers

AI must not directly generate and publish arbitrary executable JavaScript to a
customer's production site. The safe default is structured generation from an
allowlisted catalogue of tag, trigger, variable, and parameter templates.
Custom HTML or JavaScript requires explicit review and must never be published
autonomously.

### 7. Guided Onboarding and Tagging Partner

The product should guide a new customer through a business-aware onboarding
wizard. The wizard combines preconfigured measurement packs with the customer's
actual commercial model, user journey, website technology, domains, consent
requirements, and existing implementation.

Initial business packs are:

- Ecommerce
- Lead generation
- Subscription business
- Membership or community business

Customers may select more than one pack because real businesses are often
hybrids. The output is not only a list of tags. It is a complete measurement
blueprint, implementation plan, GTM draft, verification plan, and site-specific
developer handoff.

The developer handoff should state exactly what the website must expose through
the data layer, when each event occurs, required parameter types, consent rules,
example payloads, ownership, and QA steps. This makes the product an ongoing
Tagging Partner rather than a one-time configuration generator.

Detailed requirements are maintained in
[`docs/ONBOARDING_WIZARD.md`](ONBOARDING_WIZARD.md).

## Tenant and Resource Hierarchy

The target hierarchy is:

```text
Tenant account
  -> Users and roles
  -> Customers
       -> Domains and page inventories
       -> Google connections
       -> GTM account associations
            -> Containers
                 -> Workspaces
                 -> Versions
                 -> Tags, triggers, and variables
       -> GA4 property associations
       -> Blueprints
       -> Deployment and verification history
```

One tenant can have many customers. One customer can have many GTM accounts and
containers. The data model should also permit a container to be intentionally
associated with more than one customer or domain, rather than assuming strict
one-to-one ownership.

Likely future entities include:

- `gtm_accounts`
- `client_gtm_containers`
- `gtm_workspaces`
- `gtm_snapshots`
- `tag_blueprints`
- `blueprint_items`
- `tag_deployments`
- `deployment_entities`
- `page_inventory`
- `verification_runs`
- `verification_observations`
- `naming_convention_profiles`

These are conceptual entities, not an approved migration design. Schema changes
must be introduced through new numbered migrations after the workflow is agreed.

## Website Understanding

The product needs a browser-level view of the public website to understand the
DOM, links, data layer, consent behaviour, and outgoing analytics requests.

There are two complementary components:

### Remote Crawler

A controlled Playwright service visits declared URLs and records a redacted,
structured observation of:

- Page URL and template signature
- Relevant DOM landmarks and stable attributes
- Forms, buttons, links, and interactive elements
- Data-layer events and selected keys
- GTM and analytics network requests
- Redirects and consent-state changes
- JavaScript errors relevant to tagging

It should discover pages through sitemaps, configured seed URLs, and internal
links with explicit depth and page limits. The product must report measured
coverage, not claim it read every page when pages were blocked, authenticated,
dynamic, duplicated, or outside the configured scope.

### Optional Diagnostic Script

An optional first-party browser script may be installed through GTM or directly
on the customer's site for controlled test sessions. It can observe DOM events,
data-layer events, and analytics requests that a remote crawl cannot reliably
exercise.

The diagnostic script must:

- Be read-only with respect to page content and application state
- Never access or attempt to access the website database
- Never include database credentials or server-side access
- Never use `eval()` or download arbitrary executable instructions
- Never inject AI-generated code into the page
- Send only allowlisted, structured observations to the TagVerity endpoint
- Redact form values, personal information, authentication tokens, cookies, and
  sensitive query parameters
- Use HTTPS and authenticate the customer/site association
- Support an obvious disable and removal path
- Respect consent requirements and configured observation boundaries

### MCP Orchestration

Model Context Protocol can expose TagVerity's diagnostic, inventory,
verification, and documentation capabilities to an AI host through typed tools
and read-only resources. MCP is the orchestration interface, not the browser
collector itself.

The browser extension, controlled crawler, or optional diagnostic script gathers
deterministic evidence. The application stores a compact redacted record and
runs rule-based checks. MCP tools allow an authorized AI workflow to request a
scan, inspect evidence, trace a failed event, draft a fix, or generate a
developer handoff.

Continuous page observation must not invoke an AI model for every page view.
Use AI only for ambiguous diagnosis, explanation, blueprint generation, and
explicit user requests. Detailed architecture and cost controls are maintained
in [`docs/MCP_AND_BROWSER_DIAGNOSTICS.md`](MCP_AND_BROWSER_DIAGNOSTICS.md).

## Safety Principles

- Default to read-only access.
- Request GTM edit and publish scopes separately and only when needed.
- Draft into a dedicated workspace; do not mutate the default workspace.
- Require a human-readable diff before applying changes.
- Require explicit user approval before publishing.
- Store an immutable audit trail of requested, generated, approved, and applied
  changes.
- Reject unresolved references, unsupported tag types, unsafe custom code, and
  missing consent configuration.
- Do not crawl private networks, localhost, or non-HTTP protocols.
- Do not collect form values, credentials, or unnecessary personal data.
- Provide rollback information for every published deployment.

## Operational Constraint

The GTM API currently documents a default quota of 10,000 requests per project
per day and 0.25 queries per second per project. Inventory synchronization and
deployment must therefore use queued work, caching, change detection, and
deliberate pacing rather than refreshing every entity on every page load.

Reference: [GTM API limits and quotas](https://developers.google.com/tag-platform/tag-manager/api/v2/limits-quotas)

## Proposed Delivery Sequence

### Foundation: Trustworthy Audit

- Complete resource discovery and real audit data hydration
- Implement honest source status and partial-audit handling
- Finalize scoring and verification definitions
- Establish fixtures and end-to-end audit acceptance tests

### Guided Onboarding

- Add the customer measurement profile and hybrid business-type selection
- Build the initial ecommerce, lead generation, subscription, and membership
  packs
- Generate a measurement plan, event dictionary, and developer handoff
- Connect onboarding outputs to tagging blueprints and verification contracts

### Blueprint and Inventory

- Synchronize GTM resources into a searchable inventory
- Model naming conventions and reusable trigger configurations
- Create read-only blueprint views and reusable templates

### Controlled Editing

- Add incremental OAuth for GTM editing
- Create dedicated workspaces and structured draft entities
- Add diffs, conflict checks, validation, and approval history

### Browser Verification

- Build the safe crawler and page inventory
- Build an opt-in browser diagnostic session for real user journeys
- Correlate trigger exercise, browser firing, network requests, and GA4 receipt
- Add actionable alerts and recommended fixes

### MCP Integration

- Expose stable internal diagnostic capabilities as typed MCP tools
- Keep tenant authorization and write approvals in the core application
- Add read-only evidence resources before any GTM write tools
- Use MCP for on-demand AI workflows rather than continuous telemetry

### AI-Assisted Tagging

- Generate blueprints from requirements and observed DOM evidence
- Restrict output to structured, validated templates by default
- Add explicit review gates for custom code and publishing

## Open Product Decisions

- Which tag and trigger types are supported in the first blueprint release?
- Is publishing always manual, or can a tenant opt into policy-controlled
  automatic publication after validation?
- Which GA4 dimensions and parameters are "required" for the score rule around
  `(not set)`?
- What crawl coverage is required for an audit to be called complete?
- Which cross-domain journeys are required for each customer, and which are
  expected to preserve a session rather than intentionally start a new one?
- How are authenticated applications and test accounts handled during crawl?
- How long after publication should GA4 receipt verification wait before raising
  an alert?
- What information may the diagnostic script collect for product analytics
  without capturing personal data?
- Which platforms and development stacks receive first-party implementation
  guide templates in the first onboarding release?
- Which developer-handoff formats ship first: shareable web page, PDF, email, or
  issue-tracker export?
- Is the first real-browser diagnostic delivered as a Chrome extension, a
  DevTools panel, or an explicitly enabled website script?
