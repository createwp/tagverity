<?php
namespace GTMAuditor;

final class SiteScanner {
    private int $maxPages;
    private int $maxBytes;
    private int $timeoutSeconds;
    private string $caBundle;
    private array $validatedHosts = [];

    public function __construct(int $maxPages = 8, int $maxBytes = 750000, int $timeoutSeconds = 4, string $caBundle = '') {
        $this->maxPages = max(1, min($maxPages, 20));
        $this->maxBytes = max(100000, min($maxBytes, 1500000));
        $this->timeoutSeconds = max(2, min($timeoutSeconds, 10));
        $this->caBundle = $caBundle;
    }

    public function normaliseRootUrl(string $url): string {
        $url = trim($url);
        if ($url !== '' && !str_contains($url, '://')) {
            $url = 'https://' . $url;
        }
        $validated = $this->validatePublicUrl($url);
        $parts = parse_url($validated);
        $root = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) $root .= ':' . (int)$parts['port'];
        $root .= $parts['path'] ?? '/';
        return $root;
    }

    public function scan(string $url): array {
        $rootUrl = $this->normaliseRootUrl($url);
        $rootHost = strtolower((string)parse_url($rootUrl, PHP_URL_HOST));
        $rootParts = parse_url($rootUrl);
        $origin = strtolower($rootParts['scheme']) . '://' . $rootHost;
        if (!empty($rootParts['port'])) {
            $origin .= ':' . (int)$rootParts['port'];
        }

        $rootUrl = $this->canonicalPageUrl($rootUrl);
        $queue = [$rootUrl];
        $discovered = [$rootUrl => true];
        $processed = [];
        $pages = [];
        $errors = [];

        $robots = $this->discoverRobotsAndSitemaps($origin, $rootHost);
        foreach ($robots['urls'] as $candidate) {
            $normalised = $this->normaliseCrawlUrl($candidate, $rootUrl, $rootHost);
            if ($normalised !== null && !isset($discovered[$normalised])) {
                $queue[] = $normalised;
                $discovered[$normalised] = true;
            }
        }

        while ($queue && count($pages) < $this->maxPages) {
            $pageUrl = array_shift($queue);
            if ($this->isDisallowed($pageUrl, $robots['disallow'])) {
                continue;
            }

            try {
                $response = $this->fetch($pageUrl);
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    $errors[] = ['url' => $pageUrl, 'code' => 'http_' . $response['status']];
                    continue;
                }
                if (stripos($response['content_type'], 'text/html') === false) {
                    continue;
                }

                $finalUrl = $this->canonicalPageUrl($response['url']);
                if (isset($processed[$finalUrl])) {
                    continue;
                }
                $processed[$finalUrl] = true;

                $page = $this->parsePage($finalUrl, $response['body']);
                $pages[] = $page;

                foreach ($page['links'] as $link) {
                    $candidate = $this->normaliseCrawlUrl($link, $finalUrl, $rootHost);
                    if ($candidate !== null && !isset($discovered[$candidate]) && count($discovered) < 80) {
                        $queue[] = $candidate;
                        $discovered[$candidate] = true;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['url' => $pageUrl, 'code' => $this->safeErrorCode($e->getMessage())];
            }
        }

        if (!$pages) {
            throw new \RuntimeException('site_unreachable');
        }

        return [
            'version' => 1,
            'root_url' => $rootUrl,
            'scanned_at' => gmdate('c'),
            'coverage' => [
                'pages_discovered' => count($discovered),
                'pages_scanned' => count($pages),
                'page_limit' => $this->maxPages,
                'errors' => array_slice($errors, 0, 20),
                'note' => 'This is a bounded public-HTML scan. Logged-in screens, dynamically rendered states, and completed transactions still require preview testing.',
            ],
            'summary' => $this->summarise($pages),
            'pages' => array_map(function (array $page): array {
                unset($page['links']);
                return $page;
            }, $pages),
        ];
    }

    public function fetchPreviewPage(string $url): array {
        $response = $this->fetch($url);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('preview_http_' . $response['status']);
        }
        if (stripos($response['content_type'], 'text/html') === false) {
            throw new \RuntimeException('preview_not_html');
        }
        return $response;
    }

    private function discoverRobotsAndSitemaps(string $origin, string $rootHost): array {
        $disallow = [];
        $sitemaps = [$origin . '/sitemap.xml'];
        try {
            $response = $this->fetch($origin . '/robots.txt');
            if ($response['status'] >= 200 && $response['status'] < 300) {
                $active = false;
                foreach (preg_split('/\R/', $response['body']) as $line) {
                    $line = trim(preg_replace('/\s+#.*$/', '', $line));
                    if (stripos($line, 'User-agent:') === 0) {
                        $active = trim(substr($line, 11)) === '*';
                    } elseif ($active && stripos($line, 'Disallow:') === 0) {
                        $path = trim(substr($line, 9));
                        if ($path !== '') {
                            $disallow[] = $path;
                        }
                    } elseif (stripos($line, 'Sitemap:') === 0) {
                        $candidate = trim(substr($line, 8));
                        if ($this->isSameHost($candidate, $rootHost)) {
                            $sitemaps[] = $candidate;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Missing robots.txt must not prevent a public scan.
        }

        $urls = [];
        foreach (array_slice(array_unique($sitemaps), 0, 3) as $sitemapUrl) {
            try {
                $response = $this->fetch($sitemapUrl);
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    continue;
                }
                $xml = new \DOMDocument();
                $previous = libxml_use_internal_errors(true);
                $loaded = $xml->loadXML($response['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                if (!$loaded) {
                    continue;
                }
                foreach ($xml->getElementsByTagName('loc') as $loc) {
                    $candidate = trim($loc->textContent);
                    $normalised = $this->normaliseCrawlUrl($candidate, $sitemapUrl, $rootHost);
                    if ($normalised !== null) {
                        $urls[] = $normalised;
                    }
                    if (count($urls) >= 40) {
                        break 2;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return ['disallow' => array_values(array_unique($disallow)), 'urls' => array_values(array_unique($urls))];
    }

    private function fetch(string $url, int $redirects = 0): array {
        $url = $this->validatePublicUrl($url);
        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        $port = (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $resolve = [];
        foreach ($this->validatedHosts[$host] as $ip) {
            $resolve[] = $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }
        $headers = [];
        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'TagVeritySiteScanner/1.0 (+measurement setup)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.7'],
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($tooLarge) {
            throw new \RuntimeException('response_too_large');
        }
        if ($ok === false) {
            throw new \RuntimeException('network_error:' . $error);
        }

        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            if ($redirects >= 3) {
                throw new \RuntimeException('too_many_redirects');
            }
            $next = $this->resolveUrl($headers['location'], $effectiveUrl ?: $url);
            return $this->fetch($next, $redirects + 1);
        }

        return [
            'url' => $effectiveUrl ?: $url,
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body,
        ];
    }

    private function parsePage(string $url, string $html): array {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $title = $this->firstText($xpath, '//title');
        $h1 = $this->firstText($xpath, '//h1');
        $schema = $this->extractSchema($xpath);
        $forms = $this->extractForms($xpath, $url);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $link) {
            $links[] = trim($link->getAttribute('href'));
        }

        $search = $xpath->query('//input[@type="search" or contains(translate(@name,"SEARCHQUERYQS","searchqueryqs"),"search") or @name="q" or @name="s"]')->length > 0;
        $filterText = strtolower($this->firstText($xpath, '//body'));
        $filters = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " filter ") or contains(concat(" ", normalize-space(@class), " "), " sort ")]')->length > 0
            || preg_match('/\b(sort by|filter by|grid view|list view)\b/', $filterText) === 1;
        $scripts = '';
        foreach ($xpath->query('//script') as $script) {
            $scripts .= ' ' . $script->textContent . ' ' . $script->getAttribute('src');
            if (strlen($scripts) > 300000) {
                break;
            }
        }

        return [
            'url' => $url,
            'title' => mb_substr($title, 0, 180),
            'h1' => mb_substr($h1, 0, 180),
            'page_type' => $this->guessPageType($url, $title . ' ' . $h1, $schema['types']),
            'schema_types' => $schema['types'],
            'schema_names' => $schema['names'],
            'schema_service_names' => $schema['service_names'],
            'schema_product_names' => $schema['product_names'],
            'schema_breadcrumb_names' => $schema['breadcrumb_names'],
            'forms' => $forms,
            'features' => ['site_search' => $search, 'filters' => $filters],
            'technologies' => $this->detectTechnologies($html . ' ' . $scripts),
            'links' => array_values(array_unique($links)),
        ];
    }

    private function extractForms(\DOMXPath $xpath, string $url): array {
        $forms = [];
        foreach ($xpath->query('//form') as $index => $form) {
            $fields = [];
            $hasEmail = false;
            $hasPhone = false;
            foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $field) {
                $type = strtolower($field->getAttribute('type') ?: strtolower($field->nodeName));
                if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'password', 'file'], true)) {
                    continue;
                }
                $name = trim($field->getAttribute('name') ?: $field->getAttribute('id'));
                if ($name !== '') {
                    $fields[] = mb_substr(preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name), 0, 80);
                }
                $hasEmail = $hasEmail || $type === 'email' || stripos($name, 'email') !== false;
                $hasPhone = $hasPhone || $type === 'tel' || preg_match('/phone|mobile|telephone/i', $name) === 1;
            }

            $identity = trim($form->getAttribute('id') ?: $form->getAttribute('name'));
            $key = substr(hash('sha256', parse_url($url, PHP_URL_PATH) . '|' . $index . '|' . $identity), 0, 16);
            $classText = strtolower($form->getAttribute('class') . ' ' . $form->textContent);
            $forms[] = [
                'key' => $key,
                'page_url' => $url,
                'suggested_name' => $this->guessFormName($form, $index),
                'form_id' => mb_substr(trim($form->getAttribute('id')), 0, 120),
                'form_name' => mb_substr(trim($form->getAttribute('name')), 0, 120),
                'form_class_name' => mb_substr(trim($form->getAttribute('class')), 0, 255),
                'method' => strtoupper($form->getAttribute('method') ?: 'GET'),
                'action' => mb_substr($form->getAttribute('action'), 0, 200),
                'field_names' => array_values(array_unique($fields)),
                'field_count' => count(array_unique($fields)),
                'has_email_field' => $hasEmail,
                'has_phone_field' => $hasPhone,
                'looks_multistep' => preg_match('/\b(step|next|previous|progress|wizard)\b/i', $classText) === 1,
            ];
        }
        return $forms;
    }

    private function guessFormName(\DOMElement $form, int $index): string {
        foreach (['aria-label', 'title', 'name', 'id'] as $attribute) {
            $value = trim($form->getAttribute($attribute));
            if ($value !== '') {
                return mb_substr(ucwords(str_replace(['-', '_'], ' ', $value)), 0, 120);
            }
        }
        $text = strtolower($form->textContent);
        if (str_contains($text, 'quote')) return 'Quote request';
        if (str_contains($text, 'demo')) return 'Demo request';
        if (str_contains($text, 'newsletter') || str_contains($text, 'subscribe')) return 'Newsletter signup';
        if (str_contains($text, 'contact')) return 'Contact us';
        if (str_contains($text, 'search')) return 'Site search';
        return 'Form ' . ($index + 1);
    }

    private function extractSchema(\DOMXPath $xpath): array {
        $types = [];
        $names = [];
        $serviceNames = [];
        $productNames = [];
        $breadcrumbNames = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $this->walkSchema($decoded, $types, $names, $serviceNames, $productNames, $breadcrumbNames);
            }
        }
        return [
            'types' => array_slice(array_values(array_unique($types)), 0, 30),
            'names' => array_slice(array_values(array_unique($names)), 0, 30),
            'service_names' => $this->cleanNames($serviceNames),
            'product_names' => $this->cleanNames($productNames),
            'breadcrumb_names' => $this->cleanNames($breadcrumbNames),
        ];
    }

    private function walkSchema(array $node, array &$types, array &$names, array &$serviceNames, array &$productNames, array &$breadcrumbNames, bool $inBreadcrumb = false): void {
        $nodeTypes = [];
        if (isset($node['@type'])) {
            foreach ((array)$node['@type'] as $type) {
                if (is_string($type)) {
                    $safeType = mb_substr($type, 0, 80);
                    $types[] = $safeType;
                    $nodeTypes[] = strtolower($safeType);
                }
            }
            if (isset($node['name']) && is_string($node['name'])) {
                $name = mb_substr(trim($node['name']), 0, 120);
                $names[] = $name;
                if (array_intersect($nodeTypes, ['service', 'professionalservice'])) $serviceNames[] = $name;
                if (array_intersect($nodeTypes, ['product', 'productgroup', 'softwareapplication'])) $productNames[] = $name;
                if ($inBreadcrumb && in_array('listitem', $nodeTypes, true)) $breadcrumbNames[] = $name;
            }
        }
        $childInBreadcrumb = $inBreadcrumb || in_array('breadcrumblist', $nodeTypes, true);
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walkSchema($value, $types, $names, $serviceNames, $productNames, $breadcrumbNames, $childInBreadcrumb);
            }
        }
    }

    private function summarise(array $pages): array {
        $forms = [];
        $services = [];
        $products = [];
        $technologies = [];
        $features = ['site_search' => false, 'filters' => false, 'multistep_forms' => false, 'spa_signals' => false];
        $scores = ['lead_generation' => 0, 'ecommerce' => 0, 'saas' => 0];

        foreach ($pages as $page) {
            $technologies = array_merge($technologies, $page['technologies']);
            $features['site_search'] = $features['site_search'] || $page['features']['site_search'];
            $features['filters'] = $features['filters'] || $page['features']['filters'];
            foreach ($page['forms'] as $form) {
                if (isset($forms[$form['key']])) continue;
                $forms[$form['key']] = $form;
                $features['multistep_forms'] = $features['multistep_forms'] || $form['looks_multistep'];
                if ($form['has_email_field'] || $form['has_phone_field']) $scores['lead_generation'] += 2;
            }
            $types = array_map('strtolower', $page['schema_types']);
            if (in_array('product', $types, true) || in_array($page['page_type'], ['product', 'cart', 'checkout'], true)) {
                $scores['ecommerce'] += 3;
                $productNames = $page['schema_product_names'] ?? [];
                if (!$productNames) $productNames = $this->meaningfulBreadcrumbNames($page['schema_breadcrumb_names'] ?? []);
                $products = array_merge($products, $productNames);
                if (!$productNames && $page['h1'] !== '') $products[] = $page['h1'];
            }
            if (in_array('service', $types, true) || $page['page_type'] === 'service') {
                $scores['lead_generation'] += 2;
                $serviceNames = $page['schema_service_names'] ?? [];
                if (!$serviceNames) $serviceNames = $this->meaningfulBreadcrumbNames($page['schema_breadcrumb_names'] ?? []);
                $services = array_merge($services, $serviceNames);
                if (!$serviceNames && $page['h1'] !== '') $services[] = $page['h1'];
            }
            if (in_array($page['page_type'], ['pricing', 'signup', 'login'], true)) $scores['saas'] += 2;
        }

        if (in_array('Shopify', $technologies, true) || in_array('WooCommerce', $technologies, true)) $scores['ecommerce'] += 5;
        if (in_array('HubSpot', $technologies, true)) $scores['lead_generation'] += 2;
        if ($features['site_search'] && $features['filters']) $scores['ecommerce'] += 1;
        $features['spa_signals'] = in_array('SPA framework', $technologies, true);

        arsort($scores);
        $top = array_keys($scores);
        $suggested = $top[0] ?? 'lead_generation';
        if (($scores[$top[0]] ?? 0) > 0 && (($scores[$top[1]] ?? 0) >= ($scores[$top[0]] - 1))) {
            $suggested = 'hybrid';
        }

        return [
            'suggested_business_type' => $suggested,
            'business_type_scores' => $scores,
            'services' => $this->cleanNames($services),
            'products' => $this->cleanNames($products),
            'forms' => array_slice(array_values($forms), 0, 30),
            'features' => $features,
            'technologies' => array_values(array_unique($technologies)),
        ];
    }

    private function detectTechnologies(string $source): array {
        $patterns = [
            'Google Tag Manager' => '/googletagmanager\.com\/gtm\.js|GTM-[A-Z0-9]+/i',
            'Google Analytics 4' => '/googletagmanager\.com\/gtag\/js|G-[A-Z0-9]{6,}/i',
            'PostHog' => '/posthog/i',
            'HubSpot' => '/js\.hs-scripts\.com|hubspot/i',
            'Meta Pixel' => '/connect\.facebook\.net\/.*fbevents|fbq\s*\(/i',
            'TikTok Pixel' => '/analytics\.tiktok\.com|ttq\./i',
            'Shopify' => '/cdn\.shopify\.com|Shopify\./i',
            'WooCommerce' => '/woocommerce/i',
            'Consent platform' => '/onetrust|cookiebot|cookieyes|quantcast\.mgr|didomi|consentmanager/i',
            'SPA framework' => '/__NEXT_DATA__|data-reactroot|ng-version|__NUXT__/i',
        ];
        $found = [];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $source)) $found[] = $name;
        }
        return $found;
    }

    private function guessPageType(string $url, string $text, array $schemaTypes): string {
        $haystack = strtolower((string)parse_url($url, PHP_URL_PATH) . ' ' . $text);
        $types = array_map('strtolower', $schemaTypes);
        if (in_array('product', $types, true)) return 'product';
        if (in_array('service', $types, true)) return 'service';
        foreach (['checkout', 'cart', 'pricing', 'signup', 'register', 'login', 'contact', 'search', 'blog'] as $type) {
            if (preg_match('/\b' . preg_quote($type, '/') . '\b/', $haystack)) return $type === 'register' ? 'signup' : $type;
        }
        if (preg_match('#/services?/#', $haystack)) return 'service';
        if (preg_match('#/products?/#', $haystack)) return 'product';
        return parse_url($url, PHP_URL_PATH) === '/' ? 'home' : 'content';
    }

    private function firstText(\DOMXPath $xpath, string $query): string {
        $node = $xpath->query($query)->item(0);
        return $node ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : '';
    }

    private function validatePublicUrl(string $url): string {
        $parts = parse_url(trim($url));
        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('invalid_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('url_credentials_not_allowed');
        }
        if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) {
            throw new \InvalidArgumentException('port_not_allowed');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('private_host_not_allowed');
        }
        $this->assertPublicDns($host);

        $scheme = strtolower($parts['scheme']);
        $normalised = $scheme . '://' . $host;
        if (isset($parts['port'])) $normalised .= ':' . (int)$parts['port'];
        $normalised .= $parts['path'] ?? '/';
        if (isset($parts['query'])) $normalised .= '?' . $parts['query'];
        return $normalised;
    }

    private function assertPublicDns(string $host): void {
        if (isset($this->validatedHosts[$host])) return;
        $ips = @gethostbynamel($host) ?: [];
        if (defined('DNS_AAAA')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) throw new \InvalidArgumentException('host_not_found');
        foreach (array_unique($ips) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('private_host_not_allowed');
            }
        }
        $this->validatedHosts[$host] = array_values(array_unique($ips));
    }

    private function normaliseCrawlUrl(string $candidate, string $base, string $rootHost): ?string {
        $candidate = trim($candidate);
        if ($candidate === '' || preg_match('#^(mailto:|tel:|javascript:|data:)#i', $candidate)) return null;
        $resolved = $this->resolveUrl($candidate, $base);
        $parts = parse_url($resolved);
        if (!$parts || strtolower($parts['host'] ?? '') !== $rootHost) return null;
        $path = $parts['path'] ?? '/';
        if (preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|pdf|zip|xml|css|js|mp4|mp3|woff2?)$/i', $path)) return null;
        return $this->canonicalPageUrl($resolved);
    }

    private function canonicalPageUrl(string $url): string {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $url;
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $canonical = $scheme . '://' . $host;
        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
        if ($port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $canonical .= ':' . $port;
        }
        $path = preg_replace('#/+#', '/', $parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        return $canonical . ($path === '' ? '/' : $path);
    }

    private function resolveUrl(string $candidate, string $base): string {
        if (preg_match('#^https?://#i', $candidate)) return $candidate;
        $baseParts = parse_url($base);
        $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) $origin .= ':' . $baseParts['port'];
        if (str_starts_with($candidate, '//')) return $baseParts['scheme'] . ':' . $candidate;
        if (str_starts_with($candidate, '/')) return $origin . $candidate;
        $directory = preg_replace('#/[^/]*$#', '/', $baseParts['path'] ?? '/');
        $path = $directory . $candidate;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') array_pop($segments); else $segments[] = $segment;
        }
        return $origin . '/' . implode('/', $segments);
    }

    private function isSameHost(string $url, string $rootHost): bool {
        return strtolower((string)parse_url($url, PHP_URL_HOST)) === $rootHost;
    }

    private function isDisallowed(string $url, array $rules): bool {
        $path = (string)parse_url($url, PHP_URL_PATH);
        foreach ($rules as $rule) {
            if ($rule !== '/' && str_starts_with($path, rtrim($rule, '*'))) return true;
            if ($rule === '/') return true;
        }
        return false;
    }

    private function cleanNames(array $names): array {
        $clean = [];
        foreach ($names as $name) {
            $name = html_entity_decode(trim(strip_tags((string)$name)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name !== '') $clean[] = mb_substr($name, 0, 120);
        }
        return array_slice(array_values(array_unique($clean)), 0, 20);
    }

    private function meaningfulBreadcrumbNames(array $names): array {
        $names = array_values(array_filter($this->cleanNames($names), function (string $name): bool {
            return !in_array(strtolower($name), ['home', 'homepage'], true);
        }));
        return $names ? [end($names)] : [];
    }

    private function safeErrorCode(string $message): string {
        foreach (['invalid_url', 'private_host_not_allowed', 'host_not_found', 'response_too_large', 'too_many_redirects', 'site_unreachable'] as $code) {
            if (str_contains($message, $code)) return $code;
        }
        return str_starts_with($message, 'network_error') ? 'network_error' : 'scan_error';
    }
}
