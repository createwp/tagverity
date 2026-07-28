# Natural-Language MCP Interface

**Document type:** Living product and integration design
**Last updated:** 2026-07-28
**Status:** Approved direction; implementation not started

## Product Intent

GTM Auditor should provide one remote MCP server that allows compatible AI
hosts, including ChatGPT and Claude Code, to operate the tagging platform using
plain-language requests.

Example request:

> For Acme's production shop, create GA4 view_item, add_to_cart, begin_checkout,
> and purchase tracking. Use our ecommerce naming convention and do not publish
> anything until the setup passes verification.

The AI host interprets the request and selects typed MCP tools. The MCP server
does not accept arbitrary code or directly manipulate a website. It calls the
same authenticated application operations used by the GTM Auditor web
interface.

## Host Support

### ChatGPT

ChatGPT supports custom remote MCP apps and write actions in eligible plans and
surfaces. A custom app is configured with the remote MCP endpoint and its
authentication method. Write actions may require user confirmation.

Current limitation: ChatGPT Voice mode does not support apps. Users can still
use typed prompts or ordinary speech-to-text dictation in a standard chat, but
the product must not promise that a live Voice mode conversation can call the
MCP tools.

Official references:

- [Developer mode and MCP apps in ChatGPT](https://help.openai.com/en/articles/12584461)
- [Apps in ChatGPT](https://help.openai.com/en/articles/11487775-connectors-in)

### Claude Code

Claude Code can connect to a remote MCP server over Streamable HTTP and then
use its tools from ordinary natural-language prompts. HTTP is the recommended
transport for a cloud service. The connection can authenticate with OAuth or
an approved authorization header.

Claude Code is primarily a terminal interface. Voice input is therefore a host
or operating-system input feature, not an MCP capability.

Official reference:

- [Connect Claude Code to tools through MCP](https://code.claude.com/docs/en/mcp)

## Architecture

```text
ChatGPT / Claude Code / another approved MCP host
  -> GTM Auditor remote MCP server
  -> application authentication and tenant authorization
  -> measurement blueprint and policy validation
  -> shared internal GTM operations
  -> Google Tag Manager API
  -> dedicated customer workspace
  -> browser and GA4 verification
  -> audit log and human-readable result
```

The remote MCP server is a presentation and orchestration boundary. It must not
contain a second implementation of the product's tenant rules, blueprint rules,
or GTM operations.

## Conversation Workflow

1. Resolve the authenticated account, user, client, container, environment, and
   permission level. Never infer tenant scope from a customer name alone.
2. Convert the user's request into a structured measurement requirement.
3. Inspect the current blueprint, naming conventions, GTM inventory, domains,
   and available data-layer or DOM evidence.
4. Return a preview containing proposed additions, reused entities, conflicts,
   unresolved questions, and expected verification steps.
5. Ask the user to approve the exact change set.
6. Apply the approved changes to a dedicated GTM workspace through the GTM API.
7. Create a version only after validation succeeds.
8. Verify browser firing, request delivery, cross-domain continuity where
   relevant, and GA4 receipt.
9. Publish only through a separate, explicitly authorized action.

## Initial Tool Contract

### Discovery and Planning

- `list_authorized_clients`
- `get_measurement_context`
- `list_gtm_entities`
- `get_naming_conventions`
- `create_measurement_requirement_draft`
- `create_blueprint_draft`
- `preview_gtm_changes`

### Approved Workspace Changes

- `create_gtm_workspace`
- `apply_approved_workspace_changes`
- `validate_workspace_draft`
- `create_container_version`

### Verification

- `run_saved_journey`
- `trace_measurement_event`
- `verify_cross_domain_transition`
- `verify_ga4_receipt`
- `explain_verification_failure`

### Publication

- `publish_approved_version`

Planning tools must not perform writes. Workspace writes, version creation, and
publication are separate actions with separate permissions and confirmation
requirements.

## Required Safety Controls

- Authenticate the MCP user with OAuth and map that identity to an application
  user and account.
- Derive allowed clients and containers server-side.
- Store Google refresh tokens only in the core application; never return or
  pass them to an MCP host.
- Use least-privilege MCP scopes for read, draft, apply, version, and publish.
- Default new integrations to read and preview access.
- Require an immutable change-set ID before applying a previewed change.
- Reject stale approval when the GTM workspace or blueprint changed after the
  preview.
- Make apply operations idempotent so a retried tool call cannot duplicate a
  tag, trigger, or variable.
- Return a machine-readable diff and a marketer-friendly summary.
- Require explicit confirmation immediately before publish.
- Log the user, host, tenant, client, container, tool, inputs, change set,
  result, and timestamp.
- Treat website content and retrieved instructions as untrusted data and never
  allow them to override authorization or approval rules.

## Human-Friendly Responses

Every response should state:

- What the tool understood
- Which customer, site, container, and environment it will affect
- What will be created, reused, updated, or left unchanged
- What information is missing
- Whether the operation is a preview, workspace change, version, or publication
- What the user must approve next
- How the result will be tested
- Who needs to act when verification fails

Example approval message:

> I found Acme's production container and prepared 4 GA4 event tags, 4 triggers,
> and 2 data-layer variables in a new workspace. Nothing has been published.
> Purchase cannot be completed until the site provides transaction_id, value,
> and currency. Approve this workspace draft, or generate the developer handoff
> first.

## Cost Model

The user's ChatGPT or Claude host performs the natural-language interpretation.
GTM Auditor receives typed tool calls, so it does not need to pay for another AI
request for every conversation step.

Use the application's own AI provider only when product-specific reasoning adds
value, such as generating a new blueprint from ambiguous requirements,
recommending a robust DOM trigger, or explaining conflicting evidence. Cache
these results by blueprint, GTM version, site signature, and journey version.

One host-neutral remote MCP server can serve multiple supported AI products.
Avoid separate ChatGPT and Claude implementations unless a host-specific user
interface later provides material product value.

## Delivery Sequence

1. Expose read-only customer context and GTM inventory.
2. Add requirement drafting, blueprint drafting, and change previews.
3. Add approved writes to dedicated workspaces.
4. Add deterministic browser and GA4 verification.
5. Add version creation.
6. Add publication only after the preceding controls have production evidence.

## Definition of Done

- The same prompt produces the same structured plan regardless of supported
  host, subject to model wording differences.
- No prompt can select an unauthorized client, container, or Google connection.
- A planning or preview call cannot change GTM.
- Applied changes match an approved, immutable diff.
- Retries do not create duplicate GTM entities.
- Every applied entity links back to its blueprint requirement.
- Publication is impossible without a separate permission and confirmation.
- The user receives browser and GA4 verification results after deployment.
- Failures produce a marketer explanation and, when needed, a developer handoff.
- Tool calls and GTM API results are fully auditable.
- The core web application remains usable without MCP.
