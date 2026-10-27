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
 * This is deliberately narrow: it only ever authorizes the root host and
 * its single apex/www sibling (`example.com` <-> `www.example.com`), never
 * a blanket union of every distinct host the scan happened to record pages
 * under. A bounded scan can legitimately follow a redirect through an
 * unrelated third-party host (a tracker, a payment processor, anything) on
 * its way to a page that's really still "on" the scanned site in every
 * sense that matters here — that host must never become an authorized
 * origin for the live mapper's cross-origin token exchange just because a
 * page URL happened to pass through it.
 *
 * Anything that needs to decide whether a URL/origin "belongs" to a scan —
 * validating a page URL the user picked, validating the origin a live
 * mapper session message came from, etc. — must check membership in this
 * restricted set, not equality against `root_url` alone.
 */
final class ScanHostAllowlist {
    /**
     * @param string $rootUrl The site scan's `root_url`.
     * @param string|null $scanDataJson The site scan's `scan_data` column
     *     (raw JSON, or null when the scan has none/hasn't completed).
     * @return string[] The root's own lowercase, trailing-dot-stripped host,
     *     plus its one apex/www sibling host — but only when that sibling
     *     actually appears as a `pages[].url` host in the scan. No other
     *     host, no matter how many distinct hosts `pages[]` contains, is
     *     ever included. Never throws — invalid/missing JSON or a missing
     *     `pages` key simply yields just the root's own host (or an empty
     *     array if even the root URL can't be parsed).
     */
    public function hosts(string $rootUrl, ?string $scanDataJson): array {
        $rootHost = $this->normalizeHost(parse_url($rootUrl, PHP_URL_HOST));
        if ($rootHost === null) {
            return [];
        }

        $sibling = $this->wwwSibling($rootHost);
        $hosts = [$rootHost => true];

        foreach ($this->pageUrls($scanDataJson) as $pageUrl) {
            $host = $this->normalizeHost(parse_url($pageUrl, PHP_URL_HOST));
            if ($host !== null && ($host === $rootHost || $host === $sibling)) {
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
     *     scheme's default) for `root_url` itself plus every `pages[].url`
     *     whose host is a member of hosts()'s restricted result — i.e. the
     *     same apex/www-only restriction as hosts(), just preserving each
     *     URL's own scheme/port rather than collapsing to bare hostnames.
     *     Never throws, same graceful fallback as hosts().
     */
    public function origins(string $rootUrl, ?string $scanDataJson): array {
        $allowedHosts = $this->hosts($rootUrl, $scanDataJson);
        $origins = [];

        $rootOrigin = $this->canonicalOrigin($rootUrl);
        if ($rootOrigin !== null) {
            $origins[$rootOrigin] = true;
        }

        foreach ($this->pageUrls($scanDataJson) as $pageUrl) {
            $host = $this->normalizeHost(parse_url($pageUrl, PHP_URL_HOST));
            if ($host === null || !in_array($host, $allowedHosts, true)) {
                continue;
            }
            $origin = $this->canonicalOrigin($pageUrl);
            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * The one apex/www counterpart of a normalized host: strips a leading
     * `www.` if present, otherwise prepends one.
     */
    private function wwwSibling(string $host): string {
        if (strncasecmp($host, 'www.', 4) === 0) {
            return substr($host, 4);
        }
        return 'www.' . $host;
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
     *
     * Public because callers that validate a single candidate URL (e.g. an
     * admin-supplied `start_url`) against `origins()`'s membership set need
     * to compute that same candidate's canonical origin themselves — using
     * the raw scheme/port instead would wrongly reject a URL that spells
     * out a default port (e.g. `https://example.com:443/`) against the
     * default-port-omitted form `origins()` returns.
     */
    public function canonicalOrigin(string $url): ?string {
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
