# GTM Auditor — Design Spec
**Date:** 2026-06-26
**Status:** Approved

---

## Overview

A multi-tenant SaaS platform that audits Google Tag Manager containers and GA4 properties for implementation quality, data integrity, and configuration issues. Users authenticate via Google OAuth — the system auto-discovers their GTM containers, GA4 properties, and BigQuery datasets, then runs a structured audit that produces actionable findings with fix instructions tailored to the responsible party (marketer, developer, or GA4 admin).

Target customers: marketing agencies (managing multiple clients), standalone businesses, and the product owner operating in agency mode with sub-clients.

---

## 1. Architecture

Four distinct layers:

### 1.1 SaaS Core (PHP on SiteGround)
Standard multi-tenant web app handling auth, dashboards, audit history, reports, and billing. PHP 8.2+ / MySQL, no framework — same stack and conventions as magical-utm. Orchestrates all audit jobs via a MySQL-backed job queue polled by SiteGround cron every 5 minutes.

### 1.2 Google Integration Layer
One OAuth 2.0 consent screen at account connection time captures all required scopes. Refresh tokens stored AES-256 encrypted in MySQL. Background jobs exchange refresh tokens for fresh access tokens before every API call.

Four data sources (availability varies by plan tier):
- **GTM Management API** — full container config: tags, triggers, variables, pause status, domain restrictions
- **GA4 Data API** — aggregated reporting data: dimension quality, session counts, event volumes
- **GA4 Real-time API** — live traffic check (last 30 min), confirms data is flowing
- **BigQuery API** — raw unsampled event-level SQL queries for root cause analysis

### 1.3 Headless Crawl Microservice *(Phase 2)*
Node.js + Playwright on a separate $6–10/mo DigitalOcean VPS (or Browserless.io initially). PHP sends a crawl job via HTTP POST with a shared-secret auth header; microservice visits the page with Chromium, intercepts network requests to `analytics.google.com`, follows redirect chains, and returns a structured JSON findings payload. Completely isolated — can be swapped or scaled without touching PHP code.

### 1.4 Audit Engine
A PHP class that receives data from all active sources and runs a ruleset of checks across four categories. Produces structured findings with severity, fix ownership, plain-language descriptions, and developer-ready instructions. Stores results in MySQL.

### 1.5 Data Flow
```
User triggers audit (manual or scheduled cron)
  → PHP inserts row into audit_queue (status: queued)
  → Cron picks up job every 5 min
  → Parallel API calls: GTM API + GA4 Data API + BigQuery (if connected)
  → [Phase 2] Crawl job dispatched to Node.js microservice
  → All results passed to Audit Engine
  → Findings written to audit_findings table
  → audit_jobs row marked complete
  → User notified (dashboard update + email if monitoring tier)
```

---

## 2. Data Model

### 2.1 Tenant Hierarchy
```sql
accounts     -- top-level workspace (type: agency | standalone)
             -- id, name, type, plan_id, owner_user_id, created_at

users        -- belong to an account
             -- id, account_id, email, name, password_hash,
             --   role (owner|admin|member|viewer), google_sub,
             --   created_at

clients      -- belong to an account; agencies have many, standalone have one
             -- id, account_id, name, created_at
```

A standalone company is an agency-type account with one client (themselves). Same code path throughout — no special casing.

### 2.2 Google Connections
```sql
google_connections
  -- id, account_id, google_email, google_sub
  -- refresh_token (AES-256 encrypted), scopes (JSON)
  -- token_expires_at, connected_at, last_used_at
```

One connection can serve multiple websites if they share the same Google account (common for agencies).

### 2.3 GTM & GA4 Resources
```sql
gtm_containers
  -- id, client_id, google_connection_id
  -- gtm_account_id, container_id, gtm_public_id (GTM-XXXXX)
  -- name, last_synced_at

ga4_properties
  -- id, client_id, google_connection_id
  -- property_id, display_name, last_synced_at

ga4_streams
  -- id, ga4_property_id
  -- stream_id, measurement_id (G-XXXXXXXX), stream_url

bigquery_connections
  -- id, ga4_property_id, gcp_project_id, dataset_id
  -- status (discovered|connected|error), discovered_at
```

### 2.4 Domains & Mapping
```sql
domains
  -- id, client_id, url, name, verified (bool), verified_by_user_id
  -- verified_at, is_yours (bool — user confirmed ownership)

domain_containers       -- many-to-many: which container fires on which domain
  -- domain_id, container_id

domain_ga4_properties   -- many-to-many: which property receives from which domain
  -- domain_id, property_id

cross_domain_configs
  -- id, client_id, primary_domain_id, linked_domain_id
  -- linker_enabled (bool), domains_configured (JSON)
  -- verified (bool), last_verified_at
```

### 2.5 Audit Jobs & Findings
```sql
audit_jobs
  -- id, client_id
  -- triggered_by (manual|scheduled), status (queued|running|complete|failed)
  -- data_sources (JSON: which APIs were used), credits_used
  -- health_score (0-100), started_at, completed_at, error_message
  -- (findings reference specific domains via affected_item field)

audit_findings
  -- id, audit_job_id
  -- category (container_health|trigger_quality|data_quality|implementation)
  -- severity (critical|warning|info)
  -- title, description (marketer-readable plain language)
  -- recommendation (what to do), developer_notes (technical instructions)
  -- fix_owner (gtm|developer|ga4_admin|hosting|multiple)
  -- affected_item (tag name, trigger name, domain, etc.)
  -- evidence (JSON — raw API snippet, BQ row count, URL)
```

### 2.6 Crawl Results *(Phase 2)*
```sql
crawl_results
  -- id, audit_job_id, page_url
  -- tags_fired (JSON), network_requests (JSON)
  -- redirect_chain (JSON), errors (JSON)
  -- crawled_at
```

### 2.7 Monitoring & Billing
```sql
monitoring_schedules
  -- id, client_id, frequency (weekly|daily|hourly)
  -- last_run_at, next_run_at, active (bool)

monitoring_alerts
  -- id, client_id, audit_job_id, channel (email|webhook|slack)
  -- sent_at, finding_severity_threshold

plans
  -- id, name, price_monthly, audit_credits_monthly
  -- has_crawl, has_bigquery, has_realtime_monitoring
  -- has_developer_notes, has_whitelabel, has_client_workspaces
  -- max_websites, max_clients, monitoring_frequency

credit_usage
  -- id, account_id, month (YYYY-MM), credits_used
```

---

## 3. Audit Engine — Checks & Findings

All findings include: severity, plain-language description (marketers), developer notes (technical), fix owner tag, and evidence from the data source that detected it.

### Category 1 — Container Health *(GTM API)*
- Tag firing on unexpected/unverified domain
- GA4 Configuration tag using wrong Measurement ID
- Duplicate GA4 Configuration tags in same container
- Paused tags (flagged with days-since-modified)
- Tags that have never fired (candidates for removal)
- Tag firing order issues (GA4 Config must fire before event tags)
- Missing consent mode configuration on GA4 tag

### Category 2 — Trigger & Variable Quality *(GTM API)*
- Purchase/conversion trigger missing value filter (fires on all pages)
- Variables referenced in tags but not defined in container
- Scroll depth trigger at very low threshold (inflates engagement)
- Click triggers using fragile CSS selectors
- History Change trigger missing (SPA sites only)
- Duplicate trigger conditions

### Category 3 — GA4 Data Quality *(GA4 Data API + BigQuery)*
- `(not set)` on `landing_page` dimension above threshold
  - Root cause: `page_view` firing before `session_start` (BQ confirms)
- `session_start` missing from X% of sessions (BQ: exact count)
- `purchase` events missing `transaction_id`
- `page_location` sending internal query params (?ref=, ?fbclid=)
- Cross-domain sessions breaking (new session starts on linked domain)
- Domains sending unexpected hits (staging, third-party checkout)
- Domains in streams with no incoming data (possible missed tracking)

### Category 4 — Implementation *(Headless crawl — Phase 2, GA4 API)*
- GTM snippet not present on page
- GTM snippet loading after main content (late async load)
- `dataLayer` not initialised before GTM snippet
- SPA framework detected with no History Change trigger
- `history.pushState` race condition (page_location stale at trigger time)
- `dataLayer` not cleared between SPA route changes
- GA4 Enhanced Measurement + GTM both tracking History Changes (double-fire)
- Redirect chain stripping `_gl` cross-domain parameter
- HTTP→HTTPS / www→non-www redirect not preserving query params
- JavaScript redirect firing before linker can decorate link
- Cookie consent blocking GA4 with no Consent Mode configured

### Fix Ownership Tags
Every finding is tagged with who acts on it:

| Tag | Who acts |
|---|---|
| `GTM` | Marketing / tag manager (fix in GTM UI, no developer needed) |
| `Developer` | Development team (code change required) |
| `GA4 Admin` | Analytics admin (fix in GA4 Admin UI) |
| `Hosting/DNS` | DevOps / IT (server config, redirect rules) |
| `Multiple` | Requires coordination between two or more of the above |

---

## 4. Google OAuth & Auto-Discovery

### 4.1 OAuth Scopes (single consent screen)
```
https://www.googleapis.com/auth/tagmanager.readonly
https://www.googleapis.com/auth/analytics.readonly
https://www.googleapis.com/auth/analytics
https://www.googleapis.com/auth/bigquery.readonly
https://www.googleapis.com/auth/cloud-platform.read-only
```

### 4.2 Auto-Discovery Flow
After OAuth completes:
1. **Resource Manager API** — list all GCP projects user has access to
2. **GTM API** — list all GTM accounts + containers linked to the Google account
3. **GA4 Admin API** — list all GA4 properties + data streams (each stream has a `defaultUri` domain and Measurement ID)
4. **BigQuery API** — for each GCP project, search for datasets named `analytics_{GA4_PROPERTY_ID}` (GA4's fixed naming convention)
5. Present visual domain map — user confirms which domains are theirs

### 4.3 Visual Domain Map & Verification
Auto-discovered connections rendered as a relationship diagram:
- Nodes: GTM containers, GA4 properties, known domains
- Edges: which container fires on which domain, which property receives from which domain
- Colour coding: Green (verified), Yellow (unconfirmed), Red (problem detected — e.g. unexpected domain)

User confirms each domain ("Is this yours?" Yes / No / Investigate). Responses stored in `domains.is_yours` and `domains.verified`. "No / Investigate" immediately creates a Critical finding in the next audit. Map is re-evaluated on every audit run — new unexpected domains trigger alerts.

---

## 5. Pricing & Plans

| | Starter | Growth | Pro | Agency |
|---|---|---|---|---|
| **Price** | $49/mo | $99/mo | $249/mo | $599/mo |
| **Websites** | 1 | 3 | 10 | Unlimited |
| **Clients** | — | — | — | Unlimited |
| **Audit credits/mo** | 5 | 20 | 100 | Unlimited |
| **GTM API** | ✓ | ✓ | ✓ | ✓ |
| **GA4 Reporting API** | ✓ | ✓ | ✓ | ✓ |
| **Headless crawl** | ✗ | ✓ | ✓ | ✓ |
| **BigQuery raw data** | ✗ | ✗ | ✓ | ✓ |
| **Monitoring** | Manual | Weekly | Daily | Hourly |
| **Alerts** | ✗ | Email | Email + Webhook | Email + Webhook + Slack |
| **Developer notes** | ✗ | ✗ | ✓ | ✓ |
| **White-label PDF reports** | ✗ | ✗ | ✗ | ✓ |
| **Client workspaces** | ✗ | ✗ | ✗ | ✓ |

**One-time audit:** $79 — Pro-level single audit, no subscription.
**Extra credits add-on:** $19 per 10 additional audits, available on any plan.

**Infrastructure cost per audit:** ~$0.11 (BigQuery query ~$0.01 + headless crawl ~$0.10). GTM and GA4 APIs are free within quota.

---

## 6. Tech Stack

### SaaS Core (SiteGround)
- **Language:** PHP 8.2+
- **Database:** MySQL (same patterns as magical-utm)
- **Frontend:** jQuery, vanilla CSS, Chart.js (health scores + charts), SVG (domain map)
- **Auth:** Custom session auth + Google OAuth 2.0
- **Key Composer packages:**
  - `google/apiclient` — GTM, GA4 Admin, GA4 Data, Resource Manager APIs
  - `google/cloud-bigquery` — BigQuery queries
  - `stripe/stripe-php` — billing
  - `dompdf/dompdf` — PDF report export (renders from same HTML as web UI)
  - `phpmailer/phpmailer` — alert emails
- **Background jobs:** SiteGround cron (every 5 min) polls `audit_queue` table

### Headless Crawl Microservice *(Phase 2)*
- **Runtime:** Node.js + Playwright
- **Server:** Express.js — single endpoint: `POST /crawl`
- **Auth:** Shared secret in `X-Crawl-Secret` header
- **Hosting:** DigitalOcean $6–10/mo droplet (or Browserless.io until volume justifies own VPS)

### Third-party Services
- **Stripe** — subscription billing + one-time payments
- **SendGrid** — transactional emails (audit complete, alerts, PDF delivery)

---

## 7. Build Sequence

### Phase 1 — MVP (~6 weeks) → Starter plan
- User auth (email/password + Google OAuth)
- Account → Client hierarchy + plan gating
- Google connection with auto-discovery
- Visual domain map + user verification flow
- GTM API audit (Category 1 + 2 checks)
- GA4 Reporting API audit (Category 3 checks, no BigQuery)
- Audit report UI with findings, fix ownership, recommendations
- Audit job queue + cron runner
- Stripe billing (one-time $79 + $49/mo Starter)
- Basic dashboard (health score, finding counts, audit history)

### Phase 2 — Growth plan (~4 weeks)
- Node.js headless crawl microservice
- Redirect chain detection + `_gl` stripping analysis
- SPA framework detection + History Change audit
- Category 4 implementation checks
- Weekly scheduled monitoring (monitoring_schedules + cron)
- Email alerts (new Critical findings)
- Crawl evidence in report (network log + redirect chain)

### Phase 3 — Pro plan (~4 weeks)
- BigQuery auto-discovery + connection
- Raw event SQL queries (session_start gaps, (not set) root causes)
- Session-level impact numbers on findings ("847 sessions affected last 30 days")
- Developer notes on every finding (technical fix instructions)
- Daily monitoring + webhook alerts
- PDF report export via dompdf

### Phase 4 — Agency plan (~4 weeks)
- Client workspace management (create/manage sub-clients)
- White-label PDF reports (custom logo, brand colours)
- Bulk audit dashboard (all clients, health scores at a glance)
- Hourly monitoring + Slack alerts
- Reseller management (create/invite client accounts)

---

## 8. Security Notes
- Google refresh tokens encrypted at rest (AES-256, key in environment variable — never in DB)
- All API calls use HTTPS only
- Crawl microservice authenticated via shared secret (not exposed to frontend)
- BigQuery queries always include `_TABLE_SUFFIX` partition filter to prevent runaway cost
- Audit findings marked with `account_id` — no cross-tenant data access possible
- CSRF protection on all write endpoints
- Rate limiting on OAuth callback and audit trigger endpoints
