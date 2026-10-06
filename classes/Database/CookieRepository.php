<?php

declare(strict_types=1);

namespace Database;

/**
 * Persistence for remember-me cookies (the `cookies` table).
 *
 * Rows hold the SHA-256 of the token, never the token: callers hash before
 * they get here (see Auth\IsLoggedIn). Every lookup honours `expires_at`, so
 * a token captured from a browser or a backup dies with the row's lifetime
 * instead of living as long as the browser says. Issuing a cookie also purges
 * expired rows, which keeps the table bounded without a cron entry.
 *
 * Timestamps are computed in PHP and the SQL kept portable so the Unit suite
 * can drive this on an in-memory SQLite.
 */
class CookieRepository
{
    /** MySQL: unknown column. */
    private const SQLSTATE_NO_COLUMN = '42S22';

    /** VARCHAR width of `cookies`.`user_agent`. */
    private const USER_AGENT_WIDTH = 255;

    /** How stale `last_access` may get before touch() writes it again. */
    private const TOUCH_EVERY_SECONDS = 3600;

    private \DateTimeImmutable $now;

    public function __construct(
        private \PDO $di_pdo,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->now = $now ?? new \DateTimeImmutable();
    }

    public function issue(
        int $user_id,
        string $cookie_hash,
        string $ip_bin,
        string $user_agent_md5,
        int $lifetime_seconds,
        string $user_agent = '',
    ): void {
        $params = [
            $user_id,
            $cookie_hash,
            $this->format($this->now),
            $this->format($this->now->modify("+{$lifetime_seconds} seconds")),
            $user_agent_md5,
            $ip_bin,
        ];
        try {
            $stmt = $this->di_pdo->prepare(
                "INSERT INTO `cookies`
                   (`user_id`, `cookie`, `last_access`, `expires_at`, `user_agent_md5`, `ip_address`, `user_agent`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([...$params, substr($user_agent, 0, self::USER_AGENT_WIDTH)]);
        } catch (\PDOException $e) {
            // A site that has not applied 02_devices yet must still let people
            // log in. Its devices page just cannot name the browser.
            if (!self::isMissingUserAgentColumn($e)) {
                throw $e;
            }
            $stmt = $this->di_pdo->prepare(
                "INSERT INTO `cookies`
                   (`user_id`, `cookie`, `last_access`, `expires_at`, `user_agent_md5`, `ip_address`)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute($params);
        }
        $this->purgeExpired();
    }

    /**
     * The user a presented cookie belongs to, or 0 when it is unknown, was
     * issued to a different browser, or has expired. The IP is not checked:
     * phones and laptops change networks, and that logged people out.
     */
    public function findUserId(string $cookie_hash, string $user_agent_md5): int
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT `user_id` FROM `cookies`
             WHERE `cookie` = ? AND `user_agent_md5` = ? AND `expires_at` > ?
             LIMIT 1"
        );
        $stmt->execute([$cookie_hash, $user_agent_md5, $this->format($this->now)]);
        $user_id = $stmt->fetchColumn();
        return $user_id === false ? 0 : (int) $user_id;
    }

    public function revoke(string $cookie_hash): void
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `cookie` = ?");
        $stmt->execute([$cookie_hash]);
    }

    /**
     * Sign a user out everywhere. Pass the hash of the cookie in hand to keep
     * the current browser logged in ("everywhere else"), which is what a
     * password change wants. Returns the number of sessions revoked.
     */
    public function revokeAllForUser(int $user_id, ?string $keep_cookie_hash = null): int
    {
        if ($keep_cookie_hash === null) {
            $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `user_id` = ?");
            $stmt->execute([$user_id]);
        } else {
            $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `user_id` = ? AND `cookie` <> ?");
            $stmt->execute([$user_id, $keep_cookie_hash]);
        }
        return $stmt->rowCount();
    }

    /**
     * Revoke one of a user's devices by row id. Scoped to the user, so a
     * posted cookie_id belonging to someone else revokes nothing. True when a
     * row went away.
     */
    public function revokeDevice(int $user_id, int $cookie_id): bool
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `cookie_id` = ? AND `user_id` = ?");
        $stmt->execute([$cookie_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Record that a cookie was just used, so the devices page can say when.
     * Runs on every logged-in request, so it writes at most once an hour per
     * cookie; the WHERE clause makes the other requests a no-op read.
     */
    public function touch(string $cookie_hash): void
    {
        $stmt = $this->di_pdo->prepare(
            "UPDATE `cookies` SET `last_access` = ?
             WHERE `cookie` = ? AND (`last_access` IS NULL OR `last_access` < ?)"
        );
        $stmt->execute([
            $this->format($this->now),
            $cookie_hash,
            $this->format($this->now->modify('-' . self::TOUCH_EVERY_SECONDS . ' seconds')),
        ]);
    }

    /**
     * A user's live devices, most recently used first. The one whose hash is
     * $current_cookie_hash (the browser asking) is flagged is_current.
     *
     * @return list<\Auth\Device>
     */
    public function listForUser(int $user_id, ?string $current_cookie_hash): array
    {
        $params = [$current_cookie_hash ?? '', $user_id, $this->format($this->now)];
        $sql = "SELECT `cookie_id`, %s AS `user_agent`, `ip_address`, `created_at`, `last_access`, `expires_at`,
                       (`cookie` = ?) AS `is_current`
                FROM `cookies`
                WHERE `user_id` = ? AND `expires_at` > ?
                ORDER BY `last_access` DESC, `cookie_id` DESC";
        try {
            $stmt = $this->di_pdo->prepare(sprintf($sql, '`user_agent`'));
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if (!self::isMissingUserAgentColumn($e)) {
                throw $e;
            }
            $stmt = $this->di_pdo->prepare(sprintf($sql, "''"));
            $stmt->execute($params);
        }

        $devices = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $devices[] = new \Auth\Device(
                cookie_id: self::int($row['cookie_id'] ?? 0),
                user_agent: self::str($row['user_agent'] ?? ''),
                ip_address: \Auth\IPBin::binaryToIp(self::str($row['ip_address'] ?? '')),
                created_at: self::str($row['created_at'] ?? ''),
                last_access: self::str($row['last_access'] ?? ''),
                expires_at: self::str($row['expires_at'] ?? ''),
                is_current: self::int($row['is_current'] ?? 0) === 1,
            );
        }
        return $devices;
    }

    public function purgeExpired(): void
    {
        $stmt = $this->di_pdo->prepare("DELETE FROM `cookies` WHERE `expires_at` <= ?");
        $stmt->execute([$this->format($this->now)]);
    }

    private function format(\DateTimeImmutable $when): string
    {
        return $when->format('Y-m-d H:i:s');
    }

    /** A PDO column value as a string; NULL and anything odd is ''. */
    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** A PDO column value as an int (drivers differ on int vs numeric string). */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function isMissingUserAgentColumn(\PDOException $e): bool
    {
        // MySQL reports SQLSTATE 42S22 (unknown column). SQLite says "has no
        // column named" on INSERT and "no such column" on SELECT, with HY000.
        return $e->getCode() === self::SQLSTATE_NO_COLUMN
            || str_contains($e->getMessage(), 'no column named user_agent')
            || str_contains($e->getMessage(), 'no such column: user_agent');
    }
}
