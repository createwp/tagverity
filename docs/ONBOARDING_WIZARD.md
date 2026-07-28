# Onboarding Wizard and Tagging Partner

**Document type:** Living product and acceptance requirements
**Last updated:** 2026-07-28
**Status:** Direction agreed; implementation not started

## Objective

The onboarding wizard should turn a customer's business model, measurement
goals, website technology, domains, and existing analytics setup into a
practical tagging blueprint.

The wizard is the beginning of an ongoing Tagging Partner relationship. It
should leave the customer, marketer, and developer with a shared source of truth
for what is measured, why it is measured, how it is implemented, and how it is
verified.

## Principles

- Start with business outcomes, not a list of GTM tag types.
- Scan and understand the existing website and tags before recommending changes.
- Use preconfigured packs as a starting point, not a rigid final answer.
- Support hybrid businesses that need multiple packs.
- Prefer Google's recommended event names and prescribed parameters where they
  fit the business requirement.
- Create structured blueprints before creating GTM entities.
- Generate developer documentation specific to the actual website and stack.
- Make assumptions visible and require confirmation for important decisions.
- Keep all GTM changes in a reviewable workspace until explicitly approved.
- Verify every implemented event in the browser and analytics destination.

Google provides recommended events for online sales, lead generation, signup,
login, group membership, purchase, refund, and other common interactions. Using
recommended names and parameters where appropriate improves compatibility with
predefined GA4 reports and audiences.

Official references:

- [GA4 recommended events](https://support.google.com/analytics/answer/9267735)
- [GA4 recommended event reference](https://developers.google.com/analytics/devguides/collection/ga4/reference/events)
- [GA4 ecommerce setup](https://support.google.com/analytics/answer/12200568)

## Wizard Experience

The wizard should be resumable and show progress. A user can return to earlier
answers without losing generated work. Complex terminology should be translated
into business language, with technical details available when needed.

### Step 1: Customer and Ownership

- Select or create the customer
- Confirm tenant and customer ownership
- Identify primary marketer, developer, analytics owner, and approver
- Select the target environment: development, staging, or production

### Step 2: Business Model

- Ecommerce
- Lead generation
- Subscription
- Membership or community
- Hybrid selection
- Custom model for requirements that do not fit a pack

The wizard should ask how the business earns revenue rather than relying only on
an industry label.

### Step 3: Business Goals

Examples include:

- Increase purchases or order value
- Generate and qualify leads
- Improve trial-to-paid conversion
- Understand product adoption
- Increase membership engagement or renewal
- Reduce checkout or signup abandonment
- Improve campaign attribution
- Build remarketing audiences

Each selected goal should map to measurable questions, events, parameters, and
success criteria.

### Step 4: Customer Journey

The user identifies important steps such as:

- Landing and acquisition pages
- Product, pricing, service, or content views
- Search and discovery
- Signup, login, or account creation
- Form start and completion
- Cart and checkout
- Trial start, activation, upgrade, renewal, cancellation, and payment failure
- Membership join, access, engagement, and renewal
- Offline lead stages or CRM outcomes

The wizard should support branches rather than assuming one linear funnel.

### Step 5: Website and Application Technology

Capture or detect:

- CMS, ecommerce platform, framework, or custom application
- Server-rendered versus single-page navigation
- Existing data layer and event bus
- Form provider, checkout provider, subscription billing, and authentication
- GTM containers and Google tags already installed
- Consent-management platform and supported regions
- Staging and production differences
- Developer deployment process and documentation preference

The customer's website may use any technology even though GTM Auditor itself is
built with PHP and jQuery.

### Step 6: Domains and Journeys

- Primary domains and subdomains
- Application, checkout, form, payment, and portal domains
- Customer white-label domains
- Required same-session transitions
- Intentional new-session boundaries
- Authenticated journeys that need controlled test access

This step creates the initial domain-transition graph described in
[`docs/CROSS_DOMAIN_MEASUREMENT.md`](CROSS_DOMAIN_MEASUREMENT.md).

### Step 7: Destinations and Consent

- GA4 properties and streams
- Google Ads and other approved marketing destinations
- Product analytics destinations
- Required regional consent behaviour
- Advertising and analytics consent requirements
- Data retention and privacy restrictions
- Parameters that must never contain personal information

### Step 8: Existing Setup Scan

Before generating a plan, inspect:

- Published GTM container and available workspaces
- Existing tags, triggers, variables, and naming patterns
- GA4 events and parameters currently received
- Website DOM, data-layer activity, consent, and network requests
- Cross-domain configuration and observed transitions
- Duplicate, missing, broken, or unused implementations

The wizard should offer to reuse compliant existing resources instead of
creating duplicates.

### Step 9: Blueprint Review

Present the proposed measurement system before creating anything in GTM:

- Measurement questions and KPIs
- Funnel or journey stages
- Events and parameters
- Required versus optional fields
- Naming conventions
- Data-layer requirements
- Proposed tags, triggers, and variables
- Consent dependencies
- Domain and cross-domain requirements
- Known gaps and developer blockers
- Verification criteria

The user can accept, edit, defer, or remove individual blueprint items.

### Step 10: Implementation Route

Offer controlled paths:

- Documentation only for the customer's developer
- GTM workspace draft plus developer requirements
- Managed implementation with review and approval
- Audit-only mode for customers who do not grant edit access

No route publishes automatically by default.

## Initial Business Packs

### Ecommerce Pack

The baseline journey covers:

- Product-list and product-detail views
- Product selection
- Promotion views and selections
- Wishlist activity
- Cart add, remove, and view
- Checkout start
- Shipping and payment stages
- Purchase and transaction identifiers
- Full and partial refunds
- Customer signup and login where relevant
- Cross-domain checkout continuity

The blueprint must define event-level and item-level data, currency, value,
transaction identifiers, item arrays, discount handling, and duplicate-purchase
protection.

### Lead Generation Pack

The baseline journey covers:

- Lead-form discovery
- Form start, validation errors, and successful submission
- `generate_lead` for a valid lead submission
- Appointment, demo, phone, email, and messaging actions
- Lead source and campaign context
- Qualified, disqualified, working, converted, and unconverted lead stages where
  offline or CRM data is available
- Cross-domain form or booking-provider continuity

Form field values and personal information must not be collected as analytics
parameters or diagnostic evidence.

### Subscription Pack

The baseline journey covers:

- Pricing and plan views
- Signup and account creation
- Trial start and activation milestone
- Checkout and initial subscription purchase
- Upgrade, downgrade, add-on, pause, renewal, cancellation, and reactivation
- Failed payment and recovered payment
- Key product-adoption events
- Customer login and authenticated application journeys
- Marketing-site to application and checkout continuity

Business-specific lifecycle events may require a custom taxonomy. They should be
documented separately from GA4 recommended events rather than disguised as a
standard event.

### Membership and Community Pack

The baseline journey covers:

- Signup, login, and profile completion
- Membership selection and purchase
- Trial or free-to-paid conversion
- Joining a group or programme
- Content discovery and meaningful engagement
- Course, module, or onboarding progress where applicable
- Renewal, cancellation, expiration, and reactivation
- Member portal and white-label domain continuity

The wizard must ask what meaningful engagement means for that membership rather
than applying generic scroll or time thresholds.

## Universal Measurement Layer

Every pack starts with a shared foundation:

- Correct Google tag and GA4 destination
- Page and screen views
- Session and campaign attribution
- Consent defaults and updates
- Internal search where applicable
- Outbound and file interactions where relevant
- Required domain and cross-domain transitions
- Error and failed-conversion states that affect the journey
- Naming conventions
- Environment and staging controls
- Verification and monitoring contract

## Generated Outputs

### Measurement Plan

- Business goals
- Measurement questions
- KPIs and supporting events
- Funnel and journey map
- Platforms and owners

### Event Dictionary

For every event:

- Event name and business meaning
- Trigger condition
- Required and optional parameters
- Data type and example value
- Source of each value
- Consent requirement
- Destination
- Whether it is a recommended or custom event
- Validation and failure rules

### Tagging Blueprint

- Proposed GTM tags, triggers, variables, folders, and dependencies
- Naming convention
- Reused versus newly created resources
- Environment and domain scope
- Deployment and rollback plan

### Developer Implementation Pack

The developer document must be specific to the website and include:

- Detected or confirmed technology stack
- Exact pages, components, templates, or application states involved
- Data-layer contract with typed example payloads
- When each payload must be emitted relative to rendering and navigation
- Required stable element attributes when DOM triggers are unavoidable
- SPA route-change and state-reset requirements
- Ecommerce item and transaction structures where applicable
- Cross-domain link, form, redirect, and consent requirements
- Personal-data fields that must never be sent
- Development, staging, and production behaviour
- Acceptance tests and expected browser observations
- Ownership and deployment dependencies

The documentation should be available as a durable versioned page and an
exportable handoff. Updating a blueprint should produce a visible documentation
diff.

Developer handoffs generated from onboarding or later alerts must follow
[`docs/ALERTS_AND_HANDOFFS.md`](ALERTS_AND_HANDOFFS.md) so marketers receive a
clear summary while developers receive the technical evidence and acceptance
criteria they need.

### QA and Verification Plan

- Test pages and journeys
- Required actions
- Expected data-layer event
- Expected GTM tag
- Expected analytics request
- Expected GA4 event and parameters
- Cross-domain continuity expectations
- Failure alerts and re-test procedure

## Technology-Specific Guidance

Implementation guidance should use a small library of reviewed patterns for
common platforms and application types. Examples may include WordPress,
WooCommerce, Shopify, custom server-rendered sites, and single-page applications.

Generated documentation must separate:

- Platform-supported automatic events
- Configuration available in GTM or the analytics platform
- Data-layer work required from the developer
- Custom code that needs explicit review

The system must not assume that a platform integration is complete merely
because a plugin or pixel is installed.

## Blueprint Readiness

Each blueprint item should show readiness:

- `ready`: all values, triggers, consent, destinations, and tests are defined.
- `developer_required`: website or application work is needed before GTM setup.
- `configuration_required`: an analytics, consent, or GTM setting is missing.
- `blocked`: ownership, access, or a business decision is unresolved.
- `existing_reusable`: a compliant existing tag, trigger, or variable can be
  reused.
- `verification_required`: implementation exists but has not passed testing.

This gives marketers a clear view of developer dependencies without exposing
them to raw technical configuration.

## Tagging Partner Workspace

After onboarding, the customer workspace should show:

- Measurement coverage by business goal and journey stage
- Blueprint readiness and developer blockers
- Existing and planned tags
- Naming-convention compliance
- Deployment status
- Browser and GA4 verification status
- Cross-domain continuity
- Open alerts and recommended fixes
- Suggested next measurement improvements
- Documentation and change history

## Safety and Approval

- Do not capture form values or personal information during discovery.
- Do not infer permission to track an interaction merely because it exists in the
  DOM.
- Do not create duplicate tags when a compliant resource can be reused.
- Do not generate arbitrary production JavaScript as the default solution.
- Do not apply GTM changes before blueprint approval.
- Do not publish without explicit authorized confirmation.
- Keep generated code, documentation, GTM entities, and verification rules tied
  to the same blueprint version.

## Definition of Done for Onboarding

Onboarding is complete when:

- Customer ownership and implementation roles are confirmed.
- Business model, goals, and customer journeys are approved.
- Website technology, destinations, consent, and domain scope are known.
- Existing GTM and analytics setup has been scanned or explicitly skipped.
- The measurement plan and event dictionary are approved.
- Every blueprint item has a readiness state and owner.
- Developer requirements are documented with typed examples and acceptance tests.
- Cross-domain requirements are represented in the transition graph.
- The implementation route is selected.
- No GTM changes have been published without explicit approval.
- The blueprint and generated documents have immutable version identifiers.

## Data Model Direction

Likely future concepts include:

- `business_profiles`
- `measurement_packs`
- `measurement_pack_items`
- `client_measurement_profiles`
- `customer_journeys`
- `measurement_goals`
- `event_definitions`
- `implementation_requirements`
- `implementation_documents`
- `blueprint_versions`

These are product concepts, not an approved schema migration.

## Open Decisions

- Which business pack ships first?
- Which website technologies receive reviewed implementation templates first?
- Which product analytics destinations are supported initially?
- Which CRM systems can return offline lead-stage events?
- Which blueprint items can be created automatically versus requiring review?
- Which generated documents need PDF or external sharing in the first release?
