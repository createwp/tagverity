# MCP and Browser Diagnostics Architecture

**Document type:** Living architecture decision
**Last updated:** 2026-07-28
**Status:** Recommended direction; implementation not started

## Decision

MCP is viable and useful for GTM Auditor, but it should not be the technology
that continuously observes every browser page.

Use MCP as the standardized orchestration layer through which an authorized AI
host can request diagnostics, inspect structured evidence, explain findings,
draft blueprints, and prepare developer handoffs.

Use deterministic browser instrumentation, API integrations, queues, and rules
to perform the actual collection and first-pass analysis.

## Why MCP Fits

Model Context Protocol uses a client-server architecture to expose typed tools,
read-only resources, and reusable prompts to AI applications. A tool has a
defined input and output schema and performs a specific operation when called.
MCP does not prescribe how continuous browser telemetry is collected or how an
AI model uses the returned context.

That makes MCP a good interface for capabilities such as:

- Start a controlled diagnostic session
- Run a saved customer journey
- Inspect a GTM container or blueprint
- Trace an expected event from DOM interaction to GA4 receipt
- Verify a cross-domain transition
- Explain a critical finding
- Draft a recommended GTM workspace change
- Generate a marketer explanation or developer handoff

Official references:

- [MCP introduction](https://modelcontextprotocol.io/docs/getting-started/intro)
- [MCP architecture](https://modelcontextprotocol.io/docs/learn/architecture)
- [MCP server tools and resources](https://modelcontextprotocol.io/docs/learn/server-concepts)
- [MCP authorization guidance](https://modelcontextprotocol.io/docs/tutorials/security/authorization)
- [MCP security best practices](https://modelcontextprotocol.io/docs/tutorials/security/security_best_practices)

## What MCP Does Not Replace

MCP does not replace:

- A browser extension or diagnostic script
- Chrome browser instrumentation
- The Playwright crawler
- GTM and GA4 API clients
- The audit queue
- Deterministic audit rules
- Tenant authorization
- Data retention and privacy controls
- Human approval for GTM changes

Using an MCP call for every click, network request, or page view would add
latency, increase AI usage, complicate reliability, and transmit far more data
than the product needs.

## Recommended Architecture

```text
Browser extension / diagnostic session / controlled crawler
  -> redacted structured observations
  -> PHP ingestion API and MySQL queue
  -> deterministic correlation and rules engine
  -> findings, timelines, and verification results
  -> optional MCP server
       -> AI diagnosis
       -> marketer explanation
       -> blueprint recommendation
       -> developer handoff
```

### Data Plane

The data plane collects and verifies facts without requiring an AI model:

- DOM landmarks and stable attributes
- Data-layer events and selected allowlisted keys
- GTM container and entity identifiers
- Consent state changes
- Analytics request presence, timing, destination, and redacted parameter status
- Redirect chains and cross-domain linker status
- JavaScript errors relevant to tagging
- GA4 receipt checks

### Control Plane

The existing PHP application controls:

- Authentication and tenant scope
- Customer and domain ownership
- Diagnostic-session creation
- Job queues and retry policy
- Evidence storage and redaction
- Rules and scoring
- Plan limits
- Alerts and handoff status
- Approval for GTM editing or publishing

### Intelligence Plane

AI is invoked only when its reasoning or language capability adds value:

- Multiple evidence sources disagree
- A root cause remains ambiguous
- A marketer requests a plain-language explanation
- A blueprint or developer handoff needs to be drafted
- The tool needs to recommend a robust trigger from observed DOM patterns
- A user asks for alternatives or implementation guidance

The intelligence plane may be exposed through MCP, but the core application
must continue working when the AI service is unavailable.

## Browser Collection Options

### Option 1: Controlled Headless Browser

A Playwright browser runs saved pages and journeys on demand or on a schedule.

**Strengths**

- Repeatable and centrally controlled
- No browser installation for the customer
- Good for public pages, redirects, clean-browser comparisons, and scheduled
  regression checks

**Limitations**

- Browser runtime is an infrastructure cost
- Authenticated and personalized journeys require controlled test access
- It cannot reproduce a customer's extensions or local privacy settings
- It should not crawl every page continuously

### Option 2: Browser Extension or DevTools Panel

An agency user, marketer, or tester explicitly starts a diagnostic session while
browsing a real customer journey.

**Strengths**

- Observes the actual browser, DOM, navigation, and network behaviour
- Can test authenticated applications and complex interactions
- Useful for comparing a customer's browser with the clean crawler
- Server cost is low because browsing occurs on the user's device

**Limitations**

- Requires installation and clear permissions
- Capture must be visibly active and user-controlled
- Browser APIs only expose data covered by granted permissions
- Evidence must be aggressively redacted before upload

Chrome provides APIs for observing web requests and a debugger transport for
Chrome DevTools Protocol. CDP exposes browser domains including DOM and Network,
and the Network domain provides request, response, timing, and blocked-reason
information.

Official references:

- [Chrome webRequest API](https://developer.chrome.com/docs/extensions/reference/api/webRequest)
- [Chrome debugger API](https://developer.chrome.com/docs/extensions/reference/api/debugger)
- [Chrome DevTools Protocol](https://chromedevtools.github.io/devtools-protocol/)
- [CDP Network domain](https://chromedevtools.github.io/devtools-protocol/tot/Network/)

### Option 3: Website Diagnostic Script

An explicitly enabled script observes controlled sessions on the customer site.

**Strengths**

- No extension required for the tester
- Can observe real website states at scale
- Useful for a temporary QA or staging mode

**Limitations**

- Requires website or GTM installation
- Creates stronger consent, privacy, security, and data-volume responsibilities
- Cannot see all browser-level blocking in the same way as an extension or CDP
- Should not become unrestricted real-user monitoring in the MVP

### Recommended Product Mix

1. Use the controlled crawler for repeatable public and saved journeys.
2. Add an opt-in browser extension or DevTools-style diagnostic for real,
   authenticated, and browser-specific sessions.
3. Consider the website script later for temporary controlled QA, not as the
   first or only collector.
4. Place MCP above all collectors so AI workflows see one consistent tool and
   evidence model.

## Proposed MCP Capabilities

### Read-Only Resources

- Customer measurement profile
- Domain-transition graph
- Current tagging blueprint
- GTM inventory snapshot
- Audit findings
- Diagnostic timelines
- Verification results
- Naming-convention profile
- Developer handoff documents

### Read and Diagnostic Tools

- `get_customer_measurement_map`
- `list_gtm_entities`
- `start_diagnostic_session`
- `run_saved_journey`
- `trace_measurement_event`
- `verify_cross_domain_transition`
- `compare_browser_sessions`
- `verify_ga4_receipt`
- `explain_finding`
- `generate_developer_handoff`

### Write Tools for a Later Phase

- `create_blueprint_draft`
- `prepare_gtm_workspace_draft`
- `apply_approved_workspace_changes`
- `create_container_version`
- `publish_approved_version`

Write tools require separate permissions, strict tenant validation, an immutable
audit trail, and explicit user confirmation. Publication should remain a
separate tool from draft creation so authorization cannot be implied.

The host-neutral conversational workflow, tool boundaries, and host support are
defined in
[`NATURAL_LANGUAGE_MCP_INTERFACE.md`](NATURAL_LANGUAGE_MCP_INTERFACE.md).

### GTM Write Execution Boundary

MCP does not create GTM entities by itself. Every MCP write tool is a typed,
authorized wrapper around an internal application operation, which then calls
the Google Tag Manager API using the customer's encrypted Google connection.

```text
User or AI host
  -> MCP tool request
  -> GTM Auditor authorization and blueprint validation
  -> internal GTM operation
  -> Google Tag Manager API
  -> dedicated customer workspace
  -> stored result and audit log
```

The web application may call the same internal GTM operations directly without
MCP. This keeps MCP optional and prevents business rules from being duplicated
inside an AI-specific integration.

Creating or updating workspaces, tags, triggers, and variables requires the GTM
edit scope. Container-version management and publication use separate broader
scopes. Existing read-only Google connections must complete incremental consent
before any write capability is enabled.

No MCP caller receives the customer's Google refresh token. The MCP server
authorizes the caller for the requested tenant and action, while the core
application separately obtains and uses the Google access token for the GTM API.

## Cost-Control Strategy

### Keep Collection Deterministic

Do not send full DOM snapshots, complete HAR files, or every data-layer message
to an AI model. Convert observations into a compact schema and run ordinary PHP
rules first.

Example observation:

```json
{
  "event": "purchase",
  "trigger_exercised": true,
  "tag_fired": false,
  "consent": "granted",
  "request_seen": false,
  "javascript_error_seen": true,
  "root_cause": "javascript_error"
}
```

### Invoke AI Selectively

Use AI when:

- The deterministic classification is `inconclusive`
- A user opens `Explain this issue`
- A fix or implementation document must be drafted
- A new blueprint is generated
- Evidence from multiple sources needs synthesis

Do not invoke AI merely to confirm a known pass or repeat the same explanation.

### Cache by Version and Journey

Reuse results while all of these remain unchanged:

- Published GTM version
- Blueprint version
- Website release or page-template signature
- Saved journey definition
- Consent mode
- Rule version

Invalidate only the affected checks after a change.

### Deduplicate Pages

Group pages by template and behaviour. Test representative product, category,
article, landing, checkout, and application states rather than treating every
URL as unique.

### Sample Continuous Observations

For any future real-user observation mode:

- Capture only declared measurement events and journeys
- Apply per-customer volume limits
- Sample successful repetitions
- Retain failure evidence more readily than repetitive passes
- Aggregate counts before persistence
- Keep short raw-evidence retention periods

### Schedule by Risk

- Re-test immediately after a GTM publication or website release
- Test critical conversion journeys more frequently
- Test stable informational pages less frequently
- Pause repeated tests after access, consent, or environment blockers are known

## Quality Strategy

Low cost must not mean low confidence. Quality comes from correlating independent
evidence:

- Blueprint expectation
- GTM configuration
- Browser execution
- Network delivery
- GA4 receipt

AI improves explanation and diagnosis, but it must not invent missing evidence.
Every conclusion carries confidence and links back to deterministic observations.

The product must remain able to say:

- Confirmed failure
- Probable cause
- Possible cause
- Could not evaluate
- Passed with evidence

## Security and Privacy

- Diagnostic capture is opt-in and visibly active.
- Tenant and customer scope is assigned by the authenticated server, not browser
  input alone.
- Browser permissions use the smallest required host and capability scope.
- Raw cookies, OAuth tokens, `_gl` values, form values, passwords, payment data,
  and personal information are never uploaded.
- Network payloads are parsed through an allowlist and redacted locally where
  possible.
- MCP HTTP access requires authorization and tenant-scoped permissions.
- MCP tokens are validated for the MCP server and are not passed through as
  Google or application credentials.
- Read tools and write tools use separate scopes.
- AI cannot publish GTM changes without explicit authorized confirmation.
- Tool calls and evidence access are logged.

## Phased Delivery

### Phase 1: Internal Capability APIs

- Define one structured diagnostic evidence schema.
- Build deterministic event tracing and rules.
- Keep the application independent of MCP.

### Phase 2: Controlled Browser Diagnostics

- Use the existing planned Playwright service for saved journeys.
- Add an explicit real-browser diagnostic prototype.
- Compare clean and affected browser sessions.

### Phase 3: Read-Only MCP Server

- Expose customer-safe inventory, findings, timelines, and diagnostic tools.
- Implement tenant authorization, rate limits, and audit logging.
- Use MCP for AI explanations and handoffs.

### Phase 4: Approved GTM Write Tools

- Add draft-only blueprint and workspace tools.
- Add separate version and publish permissions.
- Require diffs, validation, confirmation, and rollback details.
- Connect the same remote MCP server to approved ChatGPT and Claude Code clients
  rather than building host-specific GTM logic.

## Definition of Done for MCP Integration

- The browser collectors and audit engine work without MCP or AI.
- MCP tools use typed, versioned inputs and outputs.
- Every MCP request is authenticated and tenant-scoped.
- Read-only and write capabilities have separate permissions.
- Evidence is redacted before it reaches the MCP layer.
- Repeated known passes do not invoke AI.
- AI outages do not prevent deterministic audits or verification.
- Tool execution is logged with customer, user, action, and result.
- No MCP tool can publish GTM changes without explicit confirmation.
- Cost per diagnostic journey and per AI-assisted finding can be measured.

## Recommendation

Do not make MCP a dependency of the MVP audit runner. Build stable internal
diagnostic operations first, then expose those same operations through a
read-only MCP server.

For "check tags while users browse," start with an explicit diagnostic browser
session rather than always-on tracking. This produces high-quality DOM, browser,
network, consent, and cross-domain evidence with low server cost and much lower
privacy risk. Use the remote crawler for repeatability and AI through MCP only
for on-demand diagnosis and communication.
