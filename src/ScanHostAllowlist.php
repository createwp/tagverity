<?php
namespace GTMAuditor;

/**
 * Builds the set of hosts/origins that are legitimately associated with a
 * site scan, not just its `root_url`.
 *
 * `site_scans.root_url` is whatever URL the user typed, normalized at scan
 * time. The scanner follows redirects, though, so every *scanned page* in
 * `scan_data.pages[]` is stored under its final URL — which can be a
 * different host than `root_url` (e.g. the user types `example.com`, the
 * site redirects to `www.example.com`: `root_url` stays
 * `https://example.com/` but every page is `https://www.example.com/...`).
 *
 * Anything that needs to decide whether a URL/origin "belongs" to a scan —
 * validating a page URL the user picked, validating the origin a live
 * mapper session message came from, etc. — must check membership in this
 * full set, not equality against `root_url` alone.
 */
final class ScanHostAllowlist {
    /**
     * @param string $rootUrl The site scan's `root_url`.
     * @param string|null $scanDataJson The site scan's `scan_data` column
     *     (raw JSON, or null when the scan has none/hasn't completed).
     * @return string[] Deduplicated, lowercase, trailing-dot-stripped
     *     hostnames: `root_url`'s own host plus every `pages[].url`'s host.
     *     Never throws — invalid/missing JSON or a missing `pages` key
     *     simply yields just the root's own host (or an empty array if even
     *     that can't be parsed).
     */
    public function hosts(string $rootUrl, ?string $scanDataJson): array {
        $hosts = [];

        $rootHost = $this->normalizeHost(parse_url($rootUrl, PHP_URL_HOST));
        if ($rootHost !== null) {
            $hosts[$rootHost] = true;
        }

        foreach ($this->pageUrls($scanDataJson) as $pageUrl) {
            $host = $this->normalizeHost(parse_url($pageUrl, PHP_URL_HOST));
            if ($host !== null) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * @param string $rootUrl The site scan's `root_url`.
     * @param string|null $scanDataJson The site scan's `scan_data` column
     *     (raw JSON, or null when the scan has none/hasn't completed).
     * @return string[] Deduplicated, canonical `scheme://host[:port]`
     *     origins (lowercase scheme/host, port omitted when it's the
     *     scheme's default) gathered from `root_url` plus every
     *     `pages[].url`. Never throws, same graceful fallback as hosts().
     */
    public function origins(string $rootUrl, ?string $scanDataJson): array {
        $origins = [];

        $rootOrigin = $this->canonicalOrigin($rootUrl);
        if ($rootOrigin !== null) {
            $origins[$rootOrigin] = true;
        }

        foreach ($this->pageUrls($scanDataJson) as $pageUrl) {
            $origin = $this->canonicalOrigin($pageUrl);
            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * @return string[] Every `pages[].url` found in `$scanDataJson`, or an
     *     empty array when the JSON is null/blank/invalid or has no usable
     *     `pages` list.
     */
    private function pageUrls(?string $scanDataJson): array {
        if ($scanDataJson === null || $scanDataJson === '') {
            return [];
        }

        $decoded = json_decode($scanDataJson, true);
        if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
            return [];
        }

        $urls = [];
        foreach ($decoded['pages'] as $page) {
            if (is_array($page) && isset($page['url']) && is_string($page['url']) && $page['url'] !== '') {
                $urls[] = $page['url'];
            }
        }

        return $urls;
    }

    /**
     * @param string|false|null $host A `parse_url(..., PHP_URL_HOST)` result.
     */
    private function normalizeHost($host): ?string {
        if (!is_string($host) || $host === '') {
            return null;
        }
        return strtolower(rtrim($host, '.'));
    }

    /**
     * Builds the canonical origin (lowercase scheme + host, with the port
     * included only when it is not the scheme's default) for a URL, in the
     * same form a browser's `Origin:` header uses.
     */
    private function canonicalOrigin(string $url): ?string {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        if (!is_string($scheme) || $scheme === '' || $host === null) {
            return null;
        }

        $scheme = strtolower($scheme);
        $port = parse_url($url, PHP_URL_PORT);
        $defaultPorts = ['http' => 80, 'https' => 443];

        $origin = "{$scheme}://{$host}";
        if (is_int($port) && (!isset($defaultPorts[$scheme]) || $port !== $defaultPorts[$scheme])) {
            $origin .= ":{$port}";
        }

        return $origin;
    }
}
