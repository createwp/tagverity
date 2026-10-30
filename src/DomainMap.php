<?php
namespace GTMAuditor;

/**
 * A client's domain map: discovers domains from GA4 stream URLs and the latest site scan,
 * links GA4 streams to their domains, and records the client's "Is this yours?" answers.
 *
 * Confirmation values: unconfirmed | yours | not_yours | investigate.
 * `is_yours` is 1 for yours, 0 for not_yours, NULL for investigate and unanswered domains.
 */
final class DomainMap {
    /**
     * Normalises a full URL or a bare host to a lowercase host (no scheme, port, path or
     * trailing dot). Returns null for empty input, IP literals, localhost, single-label hosts
     * and anything longer than 253 characters.
     */
    public static function normaliseHost(string $urlOrHost): ?string {
        $input = trim($urlOrHost);
        if ($input === '') {
            return null;
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $input = 'https://' . $input;
        }

        $host = parse_url($input, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if ($host === 'localhost' || strpos($host, '.') === false) {
            return null;
        }
        if (!preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * Adds candidate domains that the client does not have yet: GA4 stream hosts (also linked to
     * their property) and the host of the latest site scan. Existing rows are never changed.
     *
     * @return int How many domains were added.
     */
    public static function refresh(\PDO $pdo, int $clientId): int {
        $candidates = [];

        $streams = $pdo->prepare(
            "SELECT p.id AS property_row_id, s.stream_url
             FROM ga4_streams s
             JOIN ga4_properties p ON p.id = s.ga4_property_id
             WHERE p.client_id = ? AND s.stream_url IS NOT NULL AND s.stream_url <> ''
             ORDER BY p.id, s.id"
        );
        $streams->execute([$clientId]);
        foreach ($streams->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $candidates[] = [
                'url' => (string)$row['stream_url'],
                'source' => 'ga4_stream',
                'property_row_id' => (int)$row['property_row_id'],
            ];
        }

        $scan = $pdo->prepare("SELECT root_url FROM site_scans WHERE client_id = ? ORDER BY id DESC LIMIT 1");
        $scan->execute([$clientId]);
        $rootUrl = $scan->fetchColumn();
        if (is_string($rootUrl) && $rootUrl !== '') {
            $candidates[] = ['url' => $rootUrl, 'source' => 'site_scan', 'property_row_id' => null];
        }

        $added = 0;
        foreach ($candidates as $candidate) {
            $host = self::normaliseHost($candidate['url']);
            if ($host === null) {
                continue;
            }

            $domain = self::ensureDomain($pdo, $clientId, $host, $candidate['source']);
            if ($domain['added']) {
                $added++;
            }

            if ($candidate['property_row_id'] !== null) {
                $link = $pdo->prepare(
                    "INSERT INTO domain_ga4_properties (domain_id, property_id) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE domain_id = domain_id"
                );
                $link->execute([$domain['id'], $candidate['property_row_id']]);
            }
        }

        return $added;
    }

    /**
     * Everything the domain map page needs for a client.
     *
     * @return array{containers: array, properties: array, domains: array, edges: array}
     *     Edges are ['from' => 'ga4:<row id>' | 'gtm:<row id>', 'to' => 'domain:<row id>'].
     */
    public static function load(\PDO $pdo, int $clientId): array {
        $containers = $pdo->prepare(
            "SELECT id, name, gtm_public_id AS public_id FROM gtm_containers WHERE client_id = ? ORDER BY id"
        );
        $containers->execute([$clientId]);

        $properties = $pdo->prepare(
            "SELECT id, display_name, property_id FROM ga4_properties WHERE client_id = ? ORDER BY id"
        );
        $properties->execute([$clientId]);

        $domains = $pdo->prepare(
            "SELECT id, host, source, confirmation, confirmed_at FROM domains WHERE client_id = ? ORDER BY id"
        );
        $domains->execute([$clientId]);

        $edges = [];

        $ga4Edges = $pdo->prepare(
            "SELECT dp.property_id, dp.domain_id
             FROM domain_ga4_properties dp
             JOIN domains d ON d.id = dp.domain_id
             WHERE d.client_id = ?
             ORDER BY dp.property_id, dp.domain_id"
        );
        $ga4Edges->execute([$clientId]);
        foreach ($ga4Edges->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $edges[] = ['from' => 'ga4:' . $row['property_id'], 'to' => 'domain:' . $row['domain_id']];
        }

        $gtmEdges = $pdo->prepare(
            "SELECT dc.container_id, dc.domain_id
             FROM domain_containers dc
             JOIN domains d ON d.id = dc.domain_id
             WHERE d.client_id = ?
             ORDER BY dc.container_id, dc.domain_id"
        );
        $gtmEdges->execute([$clientId]);
        foreach ($gtmEdges->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $edges[] = ['from' => 'gtm:' . $row['container_id'], 'to' => 'domain:' . $row['domain_id']];
        }

        return [
            'containers' => array_map(fn(array $row) => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'public_id' => (string)$row['public_id'],
            ], $containers->fetchAll(\PDO::FETCH_ASSOC)),
            'properties' => array_map(fn(array $row) => [
                'id' => (int)$row['id'],
                'display_name' => (string)$row['display_name'],
                'property_id' => (string)$row['property_id'],
            ], $properties->fetchAll(\PDO::FETCH_ASSOC)),
            'domains' => array_map(fn(array $row) => [
                'id' => (int)$row['id'],
                'host' => (string)$row['host'],
                'source' => (string)$row['source'],
                'confirmation' => (string)$row['confirmation'],
                'confirmed_at' => $row['confirmed_at'],
            ], $domains->fetchAll(\PDO::FETCH_ASSOC)),
            'edges' => $edges,
        ];
    }

    /**
     * Records the client's answer to "Is this yours?" for one of its domains.
     *
     * @param string $answer yours | not_yours | investigate
     * @throws \InvalidArgumentException 'invalid_answer' for any other answer,
     *     'foreign_target' when the domain does not belong to the client.
     */
    public static function confirm(\PDO $pdo, int $clientId, int $domainId, string $answer, int $userId): void {
        if (!in_array($answer, ['yours', 'not_yours', 'investigate'], true)) {
            throw new \InvalidArgumentException('invalid_answer');
        }

        $owner = $pdo->prepare("SELECT client_id FROM domains WHERE id = ?");
        $owner->execute([$domainId]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId === false || (int)$ownerId !== $clientId) {
            throw new \InvalidArgumentException('foreign_target');
        }

        if ($answer === 'yours') {
            $stmt = $pdo->prepare(
                "UPDATE domains
                 SET confirmation = 'yours', confirmed_at = NOW(), is_yours = 1, verified = 1,
                     verified_by_user_id = ?, verified_at = NOW()
                 WHERE id = ? AND client_id = ?"
            );
            $stmt->execute([$userId, $domainId, $clientId]);
        } elseif ($answer === 'not_yours') {
            $stmt = $pdo->prepare(
                "UPDATE domains
                 SET confirmation = 'not_yours', confirmed_at = NOW(), is_yours = 0, verified = 0
                 WHERE id = ? AND client_id = ?"
            );
            $stmt->execute([$domainId, $clientId]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE domains
                 SET confirmation = 'investigate', confirmed_at = NOW(), is_yours = NULL, verified = 0
                 WHERE id = ? AND client_id = ?"
            );
            $stmt->execute([$domainId, $clientId]);
        }
    }

    /**
     * Adds a domain the client typed in. Returns the existing row's id when the host is already known.
     *
     * @throws \InvalidArgumentException 'invalid_host' when the input does not normalise to a host.
     */
    public static function addManual(\PDO $pdo, int $clientId, string $urlOrHost): int {
        $host = self::normaliseHost($urlOrHost);
        if ($host === null) {
            throw new \InvalidArgumentException('invalid_host');
        }

        return self::ensureDomain($pdo, $clientId, $host, 'manual')['id'];
    }

    /**
     * Inserts the domain for this host, or finds the existing row. The existing row is never
     * modified, so its source and confirmation survive.
     *
     * @return array{id: int, added: bool}
     */
    private static function ensureDomain(\PDO $pdo, int $clientId, string $host, string $source): array {
        $stmt = $pdo->prepare(
            "INSERT INTO domains (client_id, host, url, name, source)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $stmt->execute([$clientId, $host, 'https://' . $host, $host, $source]);

        return ['id' => (int)$pdo->lastInsertId(), 'added' => $stmt->rowCount() === 1];
    }
}
