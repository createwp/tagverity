# TagVerity Documentation

`GTM Auditor` remains the repository and internal implementation name until
brand clearance and a deliberate technical rename are completed.

This directory contains the product specification and the living record of the
application as it is implemented.

## Documents

- [Product design specification](superpowers/specs/2026-06-26-gtm-auditor-design.md)
  defines the intended product, architecture, data model, pricing, and phased
  roadmap.
- [Project status](PROJECT_STATUS.md) records what currently exists in the
  repository, what is operational, known gaps, validation results, and decisions
  made during implementation.
- [Product vision](PRODUCT_VISION.md) defines the expanded tagging operations
  platform, including blueprinting, GTM synchronization, verification, and
  AI-assisted tagging.
- [Product strategy recommendations](PRODUCT_STRATEGY_RECOMMENDATIONS.md)
  evaluates the expanded vision, proposes the initial market wedge and release
  boundary, and identifies improvements and features to defer.
- [Implementation plan](IMPLEMENTATION_PLAN.md) defines the repository-specific
  work packages, schema changes, files, API contracts, Material-inspired design
  system, testing gates, and first trustworthy release sequence.
- [Product naming direction](PRODUCT_NAMING.md) records the recommended
  customer-facing name, positioning language, alternatives, collision findings,
  and the clearance required before adoption.
- [Audit definition of done](AUDIT_DEFINITION_OF_DONE.md) defines when an audit
  result is complete, trustworthy, actionable, and safe to show to a customer.
- [Cross-domain measurement](CROSS_DOMAIN_MEASUREMENT.md) defines domain mapping,
  transition testing, session-continuity evidence, white-label isolation, and
  cross-domain failure diagnosis.
- [Onboarding wizard and Tagging Partner](ONBOARDING_WIZARD.md) defines
  business-type setup packs, site discovery, blueprint generation, and
  developer implementation documentation.
- [Alerts and developer handoffs](ALERTS_AND_HANDOFFS.md) defines the
  marketer-friendly message format, action routing, technical handoff content,
  and acceptance standards for user-facing findings and errors.
- [MCP and browser diagnostics](MCP_AND_BROWSER_DIAGNOSTICS.md) defines where MCP
  fits, how browsing sessions collect tag evidence, and how infrastructure and
  AI costs remain controlled.
- [Natural-language MCP interface](NATURAL_LANGUAGE_MCP_INTERFACE.md) defines how
  ChatGPT, Claude Code, and other approved hosts can plan, preview, apply,
  verify, and publish tagging changes through controlled conversational tools.

## Maintenance Rule

Update `PROJECT_STATUS.md` whenever a feature changes the implemented workflow,
data model, integration state, runtime requirements, or known limitations. Keep
the design specification focused on intended product behaviour and use the
status document for implementation reality. Update the product vision and audit
definition of done when product rules or acceptance criteria change.
