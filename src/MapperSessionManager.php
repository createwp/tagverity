<?php
namespace GTMAuditor;

use DateTime;
use DateTimeZone;
use DomainException;
use PDO;

require_once __DIR__ . '/ScanHostAllowlist.php';

/**
 * Issues and validates short-lived, single-use tokens for the live visual
 * tagging toolbar's mapper sessions.
 *
 * Tokens are random, high-entropy values that are returned to the caller
 * exactly once (at issuance) and never stored in raw form — only their
 * SHA-256 hash is persisted in `mapper_sessions.token_hash`. Validation
 * enforces that the token is being used from an origin belonging to the
 * site scan — `root_url`'s own origin, or the origin of any page the scan
 * actually reached (see ScanHostAllowlist; the scanner follows redirects,
 * so a scanned page's host can differ from `root_url`'s) — and, on
 * success only, deletes the session row so the token cannot be redeemed
 * a second time. A failed validation (unknown token, expired, or origin
 * mismatch) never consumes the token, so a legitimate caller that hits a
 * transient issue can still redeem it afterwards.
 */
final class MapperSessionManager {
    /**
     * Issues a new mapper session token.
     *
     * @return array{token: string, expires_at: string} The raw token (only
     *     ever available here — not recoverable from storage) and the
     *     formatted expiry timestamp.
     */
    public function createToken(PDO $pdo, int $clientId, int $siteScanId, int $userId, int $ttlSeconds = 1800): array {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))
            ->modify("+{$ttlSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            "INSERT INTO mapper_sessions (client_id, site_scan_id, created_by_user_id, token_hash, expires_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$clientId, $siteScanId, $userId, $tokenHash, $expiresAt]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Validates a mapper session token against its stored hash, expiry,
     * and the requesting origin — then, on success, deletes the session
     * row so the token is single-use.
     *
     * @param string $origin The full origin the request came from, exactly
     *     as a browser's `Origin:` header would carry it (scheme + host +
     *     optional port), e.g. `https://example.com` or
     *     `http://example.com:8080`.
     * @return array{client_id: int, site_scan_id: int, root_url: string, expires_at: string}
     * @throws DomainException with message `invalid_or_expired_token` when
     *     the token hash is unknown or already expired, or `origin_mismatch`
     *     when the token is valid but used from an origin that doesn't
     *     belong to the site scan (see ScanHostAllowlist::origins()).
     */
    public function validateToken(PDO $pdo, string $token, string $origin): array {
        $tokenHash = hash('sha256', $token);

        $pdo->beginTransaction();
        try {
            // SELECT ... FOR UPDATE row-locks the matching session (via the
            // UNIQUE KEY on token_hash) for the rest of this transaction. A
            // concurrent validateToken() call for the same token blocks here
            // until this call commits (row gone — consumed) or rolls back
            // (row unchanged), closing the race where two requests could
            // otherwise both pass this check before either deletes the row.
            $stmt = $pdo->prepare(
                "SELECT ms.id, ms.client_id, ms.site_scan_id, ms.expires_at, ss.root_url, ss.scan_data
                 FROM mapper_sessions ms
                 JOIN site_scans ss ON ss.id = ms.site_scan_id
                 WHERE ms.token_hash = ?
                 FOR UPDATE"
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || new DateTime($row['expires_at'], new DateTimeZone('UTC')) <= new DateTime('now', new DateTimeZone('UTC'))) {
                $pdo->rollBack();
                throw new DomainException('invalid_or_expired_token');
            }

            $allowedOrigins = (new ScanHostAllowlist())->origins($row['root_url'], $row['scan_data']);
            $matchesAllowedOrigin = false;
            foreach ($allowedOrigins as $allowedOrigin) {
                if (strcasecmp($allowedOrigin, $origin) === 0) {
                    $matchesAllowedOrigin = true;
                    break;
                }
            }
            if (!$matchesAllowedOrigin) {
                $pdo->rollBack(); // do NOT delete — a failed origin check must not consume the token
                throw new DomainException('origin_mismatch');
            }

            $pdo->prepare("DELETE FROM mapper_sessions WHERE id = ?")->execute([(int)$row['id']]);
            $pdo->commit();

            return [
                'client_id' => (int)$row['client_id'],
                'site_scan_id' => (int)$row['site_scan_id'],
                'root_url' => $row['root_url'],
                'expires_at' => $row['expires_at'],
            ];
        } catch (\Throwable $e) {
            // Catches our own DomainException as well as anything the
            // transaction itself can throw (lock-wait timeout, deadlock,
            // dropped connection, etc. — db.php runs PDO in exception mode).
            // Without this, an unexpected PDOException would leave the
            // transaction open and the row lock held for the rest of the
            // connection's lifetime.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
