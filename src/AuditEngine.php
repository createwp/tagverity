<?php
namespace GTMAuditor;

// ─── Audit Engine ─────────────────────────────────────────────────────────────
/**
 * The core audit orchestrator.
 *
 * Receives pre-fetched data from all active data sources and runs the full
 * ruleset across four categories. Each rule emits a structured Finding array
 * which is ultimately stored in the audit_findings table.
 *
 * Usage:
 *   $engine = new AuditEngine($gtm_data, $ga4_data, $bq_data, $client, $audit_job_id);
 *   $findings = $engine->run();
 */
class AuditEngine
{
    /** @var array GTM API payload: {tags[], triggers[], variables[], container{}} */
    private array $gtm;
    /** @var array GA4 rows keyed by check name e.g. ['landing_page' => [...rows]] */
    private array $ga4;
    /** @var array BigQuery result sets (Phase 3) */
    private array $bq;
    /** @var array Client row from DB */
    private array $client;
    /** @var int   audit_jobs.id */
    private int   $jobId;
    /** @var array[] Accumulated findings */
    private array $findings = [];

    /** GA4 property ID (numeric string) passed in alongside gtm data */
    private string $ga4PropertyId;

    /** Verified domain URLs for this client (to detect unexpected domains) */
    private array $verifiedDomains;

    public function __construct(
        array  $gtm,
        array  $ga4,
        array  $bq,
        array  $client,
        int    $jobId,
        string $ga4PropertyId    = '',
        array  $verifiedDomains  = []
    ) {
        $this->gtm             = $gtm;
        $this->ga4             = $ga4;
        $this->bq              = $bq;
        $this->client          = $client;
        $this->jobId           = $jobId;
        $this->ga4PropertyId   = $ga4PropertyId;
        $this->verifiedDomains = $verifiedDomains;
    }

    /**
     * Execute all audit categories and return the findings array.
     *
     * @return array[]  Array of finding rows ready for DB insert
     */
    public function run(): array
    {
        if (!empty($this->gtm)) {
            $this->runContainerHealthChecks();
            $this->runTriggerQualityChecks();
        }
        if (!empty($this->ga4)) {
            $this->runDataQualityChecks();
        }
        return $this->findings;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Category 1 — Container Health (GTM API)
    // ══════════════════════════════════════════════════════════════════════════

    private function runContainerHealthChecks(): void
    {
        $tags      = $this->gtm['tags']      ?? [];
        $triggers  = $this->gtm['triggers']  ?? [];
        $variables = $this->gtm['variables'] ?? [];

        // Collect all firing trigger IDs referenced by tags
        $allFiringTriggerIds = [];
        foreach ($tags as $tag) {
            foreach ($tag['firingTriggerId'] ?? [] as $tid) {
                $allFiringTriggerIds[(string)$tid] = true;
            }
        }

        // Index trigger IDs for quick lookup
        $triggerIndex = [];
        foreach ($triggers as $t) {
            $triggerIndex[(string)($t['triggerId'] ?? '')] = $t;
        }

        // ── Check 1.1: Duplicate GA4 Configuration tags ───────────────────────
        $ga4ConfigTags = array_filter($tags, fn($t) => ($t['type'] ?? '') === 'gaawc');
        if (count($ga4ConfigTags) > 1) {
            $names = implode(', ', array_column(array_values($ga4ConfigTags), 'name'));
            $this->addFinding(
                'container_health', 'critical',
                'Duplicate GA4 Configuration tags detected',
                'You have ' . count($ga4ConfigTags) . ' GA4 Configuration tags in this container. This causes every event to be sent to GA4 twice, inflating all your metrics.',
                'Remove all duplicate GA4 Configuration tags. Keep only one that fires on All Pages.',
                'gtm',
                $names,
                ['tag_count' => count($ga4ConfigTags), 'tag_names' => $names],
                'In GTM → Tags, search for type "Google Analytics: GA4 Configuration". Delete or archive all copies except the primary one. Check for tag sequencing (Setup Tag) dependencies before removing.'
            );
        }

        // ── Check 1.2: Paused tags (especially long-paused) ──────────────────
        foreach ($tags as $tag) {
            if (!empty($tag['paused'])) {
                $this->addFinding(
                    'container_health', 'warning',
                    'Paused tag detected',
                    'The tag "' . $tag['name'] . '" is paused. Paused tags do not fire and may indicate an incomplete implementation or forgotten fix.',
                    'Review this tag and either reactivate it (if intentional) or delete it to keep the container clean.',
                    'gtm',
                    $tag['name'],
                    ['tag_id' => $tag['tagId'] ?? null, 'tag_type' => $tag['type'] ?? null],
                    'Check if this tag was paused intentionally (e.g. for testing). If it should fire, unpause it. Tag ID: ' . ($tag['tagId'] ?? 'unknown')
                );
            }
        }

        // ── Check 1.3: Tags firing on unverified/unexpected domains ──────────
        if (!empty($this->verifiedDomains)) {
            foreach ($tags as $tag) {
                $domainRestrictions = $tag['domainName'] ?? null;
                // If a tag fires on ALL pages with no domain restriction → check if container
                // has known bad/unexpected domains in its settings
                // (Full implementation requires cross-referencing crawl results - Phase 2)
                // For now: flag if no domain restriction is set on GA4 config tags
                if (($tag['type'] ?? '') === 'gaawc' && empty($domainRestrictions)) {
                    $this->addFinding(
                        'container_health', 'info',
                        'GA4 Configuration tag has no domain restriction',
                        'The tag "' . $tag['name'] . '" fires on all domains. If this container is published on staging or third-party checkout domains, GA4 may receive unexpected traffic.',
                        'Add a domain restriction in the GTM container settings, or add a page hostname trigger condition to restrict firing to your verified domains only.',
                        'gtm',
                        $tag['name'],
                        ['tag_id' => $tag['tagId'] ?? null],
                        'In GTM → Admin → Container Settings, add your production domain(s) under "Domains". Alternatively, add a trigger condition: Page Hostname contains yourdomain.com.'
                    );
                }
            }
        }

        // ── Check 1.4: Tags that have never fired ─────────────────────────────
        // (Only detectable if GA4 data shows 0 events for a given tag name)
        // Requires GA4 correlation — flagged as info for now
        foreach ($tags as $tag) {
            // Tags with no firing triggers configured
            if (empty($tag['firingTriggerId']) && empty($tag['firingRuleId'])) {
                $this->addFinding(
                    'container_health', 'warning',
                    'Tag has no firing triggers configured',
                    'The tag "' . $tag['name'] . '" has no firing conditions set. It will never fire and should be removed or properly configured.',
                    'Add a firing trigger to this tag, or delete it if it is no longer needed.',
                    'gtm',
                    $tag['name'],
                    ['tag_id' => $tag['tagId'] ?? null, 'tag_type' => $tag['type'] ?? null],
                    'Navigate to GTM → Tags → "' . $tag['name'] . '" and add an appropriate firing trigger. If unused, delete it to reduce container bloat.'
                );
            }
        }

        // ── Check 1.5: GA4 event tags firing BEFORE GA4 config tag ───────────
        // GA4 event tags depend on the config tag being loaded first.
        // We detect this by checking tag sequencing (setupTag) and trigger order.
        $ga4EventTags = array_filter($tags, fn($t) => ($t['type'] ?? '') === 'gaawe');
        $configTagIds = array_column(array_values($ga4ConfigTags), 'tagId');

        foreach ($ga4EventTags as $eventTag) {
            $setupTags = array_column($eventTag['setupTag'] ?? [], 'tagName');
            // Check if at least one GA4 config tag is referenced as a setup tag
            $hasConfigSetup = false;
            foreach ($setupTags as $setupTagName) {
                foreach ($ga4ConfigTags as $configTag) {
                    if ($setupTagName === $configTag['name']) {
                        $hasConfigSetup = true;
                        break 2;
                    }
                }
            }
            if (!$hasConfigSetup && !empty($ga4ConfigTags)) {
                $this->addFinding(
                    'container_health', 'warning',
                    'GA4 event tag not sequenced after GA4 Configuration tag',
                    'The event tag "' . $eventTag['name'] . '" may fire before the GA4 Configuration tag is initialised. This can cause event data to be lost on the first page.',
                    'In the tag settings, under "Advanced Settings → Tag Sequencing", set the GA4 Configuration tag as a Setup Tag for this event tag.',
                    'gtm',
                    $eventTag['name'],
                    ['event_tag_id' => $eventTag['tagId'] ?? null],
                    'Edit tag "' . $eventTag['name'] . '" → Advanced Settings → Tag Sequencing → Fire a tag before [GA4 Config Tag].'
                );
            }
        }

        // ── Check 1.6: Missing Consent Mode on GA4 Configuration tag ─────────
        foreach ($ga4ConfigTags as $tag) {
            $hasConsentSettings = !empty($tag['consentSettings']['consentStatus']);
            if (!$hasConsentSettings) {
                $this->addFinding(
                    'container_health', 'warning',
                    'GA4 Configuration tag missing Consent Mode settings',
                    'The tag "' . $tag['name'] . '" does not have Consent Mode configured. Without this, GA4 tracking may not comply with GDPR/CCPA requirements when users decline cookies.',
                    'Add Consent Mode v2 settings to the GA4 Configuration tag. Set "ads_storage", "analytics_storage", and "ad_user_data" as required.',
                    'gtm',
                    $tag['name'],
                    ['tag_id' => $tag['tagId'] ?? null],
                    'In GTM → Tag → "' . $tag['name'] . '" → Consent Settings, enable "Require additional consent for tag to fire" and map to your CMP consent variables.'
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Category 2 — Trigger & Variable Quality (GTM API)
    // ══════════════════════════════════════════════════════════════════════════

    private function runTriggerQualityChecks(): void
    {
        $tags      = $this->gtm['tags']      ?? [];
        $triggers  = $this->gtm['triggers']  ?? [];
        $variables = $this->gtm['variables'] ?? [];

        // Index defined variable names
        $definedVarNames = array_column($variables, 'name');

        // ── Check 2.1: Purchase trigger missing value/transaction filter ──────
        foreach ($triggers as $trigger) {
            $name = strtolower($trigger['name'] ?? '');
            $type = $trigger['type'] ?? '';

            // Heuristic: triggers named "purchase" or "ecommerce" that fire on all events
            if ((strpos($name, 'purchase') !== false || strpos($name, 'ecommerce') !== false || strpos($name, 'conversion') !== false)
                && $type === 'customEvent') {
                $hasFilter = !empty($trigger['filter']) || !empty($trigger['autoEventFilter']);
                if (!$hasFilter) {
                    $this->addFinding(
                        'trigger_quality', 'critical',
                        'Purchase/conversion trigger fires without a value filter',
                        'The trigger "' . $trigger['name'] . '" appears to be a purchase or conversion trigger but has no filter condition. It may be firing on all custom events, not just actual purchases.',
                        'Add a filter condition to this trigger: Event Name equals "purchase" (or your specific event name), and verify it only fires when a transaction_id is present.',
                        'gtm',
                        $trigger['name'],
                        ['trigger_id' => $trigger['triggerId'] ?? null, 'trigger_type' => $type],
                        'Edit trigger → Add filter: {{Event}} equals "purchase". Optionally add: {{ecommerce.transaction_id}} is not undefined.'
                    );
                }
            }
        }

        // ── Check 2.2: Variables referenced in tags but not defined ──────────
        foreach ($tags as $tag) {
            // Extract {{variable_name}} references from tag parameters
            $tagJson = json_encode($tag);
            preg_match_all('/\{\{([^}]+)\}\}/', $tagJson, $matches);
            $referencedVars = array_unique($matches[1] ?? []);

            foreach ($referencedVars as $varName) {
                // Skip built-in GTM variables (they start with common known names)
                $builtIns = ['Page URL', 'Page Path', 'Page Hostname', 'Page Title', 'Referrer',
                             'Click Element', 'Click Classes', 'Click ID', 'Click Target', 'Click URL', 'Click Text',
                             'Event', 'Container ID', 'Container Version', 'Random Number', 'HTML ID',
                             'Scroll Depth Threshold', 'Scroll Depth Units', 'Scroll Direction',
                             'Video Current Time', 'Video Duration', 'Video Percent', 'Video Status', 'Video Title', 'Video URL',
                             'Form Classes', 'Form Element', 'Form ID', 'Form Target', 'Form Text', 'Form URL'];
                if (in_array($varName, $builtIns)) continue;
                if (!in_array($varName, $definedVarNames)) {
                    $this->addFinding(
                        'trigger_quality', 'critical',
                        'Tag references an undefined variable',
                        'The tag "' . $tag['name'] . '" references the variable {{' . $varName . '}} which is not defined in this container. This will cause the tag to fire with an empty value or fail silently.',
                        'Create the missing variable "' . $varName . '" in GTM → Variables, or fix the tag to reference the correct existing variable.',
                        'gtm',
                        $tag['name'] . ' → {{' . $varName . '}}',
                        ['tag_id' => $tag['tagId'] ?? null, 'missing_variable' => $varName],
                        'Navigate to GTM → Variables → New, create a variable named "' . $varName . '". Common types: Data Layer Variable, JavaScript Variable, URL parameter.'
                    );
                }
            }
        }

        // ── Check 2.3: Scroll depth trigger at very low threshold ─────────────
        foreach ($triggers as $trigger) {
            if ($trigger['type'] === 'scrollDepth') {
                $thresholds = explode(',', $trigger['verticalThresholdPercent'] ?? '');
                foreach ($thresholds as $pct) {
                    if (is_numeric($pct) && (int)$pct <= 10) {
                        $this->addFinding(
                            'trigger_quality', 'warning',
                            'Scroll depth trigger at very low threshold',
                            'The scroll depth trigger "' . $trigger['name'] . '" fires at ' . (int)$pct . '% scroll depth. This threshold is so low it fires on almost every page view, artificially inflating engagement metrics.',
                            'Increase the scroll depth threshold to at least 25–50% to measure meaningful engagement.',
                            'gtm',
                            $trigger['name'],
                            ['threshold' => (int)$pct, 'trigger_id' => $trigger['triggerId'] ?? null],
                            'Edit trigger → Vertical Scroll Depths → set minimum threshold to 25, 50, 75, 90 for meaningful scroll milestones.'
                        );
                        break;
                    }
                }
            }
        }

        // ── Check 2.4: Click triggers using fragile CSS selectors ─────────────
        foreach ($triggers as $trigger) {
            if ($trigger['type'] === 'click' || $trigger['type'] === 'linkClick') {
                foreach ($trigger['filter'] ?? [] as $filter) {
                    $key   = $filter['parameter'][0]['value'] ?? '';
                    $value = $filter['parameter'][1]['value'] ?? '';
                    // Fragile selector patterns: nth-child, complex descendant selectors, IDs with numbers
                    if (preg_match('/nth-child|nth-of-type|:not\(|>.*>.*>/', $value)) {
                        $this->addFinding(
                            'trigger_quality', 'warning',
                            'Click trigger uses a fragile CSS selector',
                            'The trigger "' . $trigger['name'] . '" uses a complex CSS selector ("' . $value . '") that is likely to break if the page layout changes.',
                            'Replace the CSS selector with a more stable targeting method: add a data-gtm attribute to the HTML element, or use the element\'s text content as a more stable identifier.',
                            'developer',
                            $trigger['name'],
                            ['trigger_id' => $trigger['triggerId'] ?? null, 'selector' => $value],
                            'Add a data attribute to the target element: <button data-gtm-id="cta-click">. Then trigger on Click Element contains [data-gtm-id="cta-click"].'
                        );
                    }
                }
            }
        }

        // ── Check 2.5: Missing History Change trigger (SPA detection) ─────────
        // Simple heuristic: if tags fire on DOM Ready or Window Loaded but no historyChange trigger exists
        $hasDomReadyTrigger    = false;
        $hasHistoryChangeTrigger = false;
        foreach ($triggers as $trigger) {
            if ($trigger['type'] === 'domReady' || $trigger['type'] === 'windowLoaded') $hasDomReadyTrigger = true;
            if ($trigger['type'] === 'historyChange') $hasHistoryChangeTrigger = true;
        }
        if ($hasDomReadyTrigger && !$hasHistoryChangeTrigger) {
            $this->addFinding(
                'trigger_quality', 'info',
                'No History Change trigger found — possible SPA tracking gap',
                'This container uses DOM Ready or Window Loaded triggers but has no History Change trigger. If your site uses a JavaScript framework (React, Vue, Angular), route changes will not be tracked as page views.',
                'Add a History Change trigger and configure your GA4 page_view tag to fire on it. Also enable "History Change" in the container settings.',
                'gtm',
                'History Change trigger',
                ['has_dom_ready' => $hasDomReadyTrigger],
                'GTM → Triggers → New → History Change. Add this as a firing trigger to your GA4 page_view tag alongside the standard Page View trigger. Also check GA4 Enhanced Measurement to avoid double-counting.'
            );
        }

        // ── Check 2.6: Duplicate trigger conditions ───────────────────────────
        $triggerSignatures = [];
        foreach ($triggers as $trigger) {
            $sig = $trigger['type'] . ':' . json_encode($trigger['filter'] ?? []);
            if (isset($triggerSignatures[$sig])) {
                $this->addFinding(
                    'trigger_quality', 'info',
                    'Duplicate trigger conditions detected',
                    'Triggers "' . $triggerSignatures[$sig] . '" and "' . $trigger['name'] . '" have identical firing conditions. This creates maintenance confusion and may cause duplicate tag firing.',
                    'Merge these triggers into a single trigger and update any tags referencing the duplicate.',
                    'gtm',
                    $trigger['name'] . ' + ' . $triggerSignatures[$sig],
                    ['trigger_ids' => [$trigger['triggerId'] ?? null]]
                );
            } else {
                $triggerSignatures[$sig] = $trigger['name'];
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Category 3 — GA4 Data Quality (GA4 Data API + BigQuery)
    // ══════════════════════════════════════════════════════════════════════════

    private function runDataQualityChecks(): void
    {
        // ── Check 3.1: (not set) on landing_page above threshold ──────────────
        $landingPageRows = $this->ga4['landing_page'] ?? [];
        $totalSessions   = (int)($this->ga4['total_sessions'] ?? 0);

        if ($landingPageRows && $totalSessions > 0) {
            foreach ($landingPageRows as $row) {
                if (($row['landingPage'] ?? '') === '(not set)') {
                    $notSetCount = (int)($row['sessions'] ?? 0);
                    $pct         = round($notSetCount / $totalSessions * 100, 1);
                    if ($pct >= 5) {
                        $severity = $pct >= 15 ? 'critical' : 'warning';
                        $this->addFinding(
                            'data_quality', $severity,
                            '(not set) landing page affecting ' . $pct . '% of sessions',
                            $pct . '% of sessions (' . number_format($notSetCount) . ') show "(not set)" as the landing page. This usually means page_view events are firing before session_start, causing GA4 to have no landing page to attribute.',
                            'Ensure the GA4 Configuration tag fires on All Pages and that no other tags push page_view events before GA4 is initialised. Check tag firing order in GTM.',
                            'gtm',
                            'landing_page dimension',
                            ['not_set_sessions' => $notSetCount, 'total_sessions' => $totalSessions, 'pct' => $pct],
                            'Root cause: page_view fires before session_start. Fix: ensure GA4 Config tag fires FIRST via tag sequencing. Also check if Enhanced Measurement "Page views" is on while also using a manual page_view tag.'
                        );
                    }
                    break;
                }
            }
        }

        // ── Check 3.2: purchase events missing transaction_id ─────────────────
        $purchaseRows = $this->ga4['purchase_transactions'] ?? [];
        if (!empty($purchaseRows)) {
            $missingTxn = 0;
            foreach ($purchaseRows as $row) {
                if (empty($row['transactionId']) || $row['transactionId'] === '(not set)') {
                    $missingTxn += (int)($row['ecommercePurchases'] ?? 0);
                }
            }
            if ($missingTxn > 0) {
                $this->addFinding(
                    'data_quality', 'critical',
                    'Purchase events missing transaction_id',
                    $missingTxn . ' purchase event(s) in the last 30 days are missing a transaction_id. Without this, revenue deduplication is impossible and purchase counts will be inflated on repeat visits.',
                    'Ensure your purchase dataLayer push always includes a unique transaction_id. Check the GTM variable mapping for your purchase tag.',
                    'developer',
                    'purchase event → transaction_id',
                    ['missing_count' => $missingTxn],
                    'In the GTM purchase tag, map {{ecommerce.transaction_id}} or {{dlv - transaction_id}} to the "transaction_id" field. Verify the dataLayer.push() in your checkout completion code includes a non-empty order ID.'
                );
            }
        }

        // ── Check 3.3: page_location sending internal query parameters ─────────
        $pageLocationRows = $this->ga4['page_location'] ?? [];
        $internalParams   = ['ref', 'fbclid', 'gclid', 'utm_source', 'preview', 'nocache', 'debug'];
        $internalParamHits = 0;
        $sampleUrl = '';
        foreach ($pageLocationRows as $row) {
            $url = $row['pageLocation'] ?? '';
            foreach ($internalParams as $param) {
                if (strpos($url, "?{$param}=") !== false || strpos($url, "&{$param}=") !== false) {
                    $internalParamHits++;
                    if (!$sampleUrl) $sampleUrl = $url;
                    break;
                }
            }
        }
        if ($internalParamHits > 0) {
            $this->addFinding(
                'data_quality', 'warning',
                'page_location contains unstripped query parameters',
                $internalParamHits . ' page URL(s) are being sent to GA4 with internal or tracking query parameters (e.g. ?ref=, ?fbclid=). This fragments your pageview data across many URLs that should be consolidated.',
                'Configure GA4 URL exclusions, or strip these parameters in GTM before sending to GA4 using a custom variable.',
                'ga4_admin',
                'page_location dimension',
                ['affected_urls' => $internalParamHits, 'sample_url' => $sampleUrl],
                'In GA4 Admin → Data Settings → Reporting Identity → Exclude query parameters, add: ref, fbclid, nocache, preview. Or use a GTM Custom JavaScript variable to strip them before passing to the tag.'
            );
        }

        // ── Check 3.4: Unexpected sending domains ─────────────────────────────
        $domainRows = $this->ga4['hostname'] ?? [];
        if (!empty($this->verifiedDomains) && !empty($domainRows)) {
            foreach ($domainRows as $row) {
                $hostname = $row['hostName'] ?? '';
                if (empty($hostname) || $hostname === '(not set)') continue;
                // Check if this hostname is in our verified domains list
                $isKnown = false;
                foreach ($this->verifiedDomains as $domain) {
                    $domainHost = parse_url($domain, PHP_URL_HOST) ?: $domain;
                    if ($hostname === $domainHost || str_ends_with($hostname, '.' . $domainHost)) {
                        $isKnown = true;
                        break;
                    }
                }
                if (!$isKnown) {
                    $sessions = (int)($row['sessions'] ?? 0);
                    $this->addFinding(
                        'data_quality', 'critical',
                        'Unexpected domain sending data to GA4',
                        'GA4 is receiving ' . $sessions . ' session(s) from "' . $hostname . '" which is not in your verified domain list. This could be a staging environment, third-party checkout, or a copied GTM container running on an unauthorised site.',
                        'Investigate this domain. If it is a staging environment, add a hostname filter to your GTM trigger to prevent staging from sending data to production GA4.',
                        'multiple',
                        $hostname,
                        ['hostname' => $hostname, 'sessions' => $sessions],
                        'Short-term fix: In GTM, add a trigger condition "Page Hostname does not contain staging." Long-term: create a separate GTM container for staging with a separate GA4 property.'
                    );
                }
            }
        }
    }

    // ─── Finding factory ──────────────────────────────────────────────────────

    /**
     * Append a finding to the internal collection.
     *
     * @param string $category
     * @param string $severity         critical | warning | info
     * @param string $title
     * @param string $description      Marketer-readable description
     * @param string $recommendation
     * @param string $fix_owner        gtm | developer | ga4_admin | hosting | multiple
     * @param string $affected_item
     * @param array  $evidence
     * @param string $developer_notes
     */
    protected function addFinding(
        string $category,
        string $severity,
        string $title,
        string $description,
        string $recommendation,
        string $fix_owner,
        string $affected_item    = '',
        array  $evidence         = [],
        string $developer_notes  = ''
    ): void {
        $this->findings[] = [
            'audit_job_id'    => $this->jobId,
            'category'        => $category,
            'severity'        => $severity,
            'title'           => $title,
            'description'     => $description,
            'recommendation'  => $recommendation,
            'developer_notes' => $developer_notes,
            'fix_owner'       => $fix_owner,
            'affected_item'   => substr($affected_item, 0, 499),
            'evidence'        => json_encode($evidence),
        ];
    }

    /**
     * Compute a 0–100 health score from the findings collected so far.
     * Critical = -15, warning = -5, info = -1. Clamped to [0, 100].
     *
     * @return int
     */
    public function healthScore(): int
    {
        $score = 100;
        foreach ($this->findings as $f) {
            $score -= match ($f['severity']) {
                'critical' => 15,
                'warning'  => 5,
                'info'     => 1,
                default    => 0,
            };
        }
        return max(0, $score);
    }

    /** Return the accumulated findings array. */
    public function getFindings(): array
    {
        return $this->findings;
    }
}
