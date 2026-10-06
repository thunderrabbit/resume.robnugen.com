<?php

/**
 * This file tries to simplify knowing if user is logged in.
 *
 *
 */

declare(strict_types=1);

namespace Auth;

class IsLoggedIn
{
    private int $who_is_logged_in = 0;

    private string $loggedInUsername = 'YUNOset?'; // default value, should be overwritten if user is logged in
    public function __construct(
        private \PDO $di_pdo,
        private \Config\Config $di_config,
        private RandomToken $di_token,
        private LoginThrottle $di_throttle,
        private \Database\CookieRepository $di_cookies,
    ) {
    }

    /**
     * Who is this request from? Runs on EVERY request (prepend.php). Its only
     * writes are expiring a cookie the database no longer knows and, at most
     * hourly, stamping last_access for /profile/devices/.
     * It never looks at credentials: that is attemptPasswordLogin()'s job,
     * and only the login page calls that.
     */
    public function resumeFromCookie(\Mlaphp\Request $mla_request): void
    {
        $cookie = $mla_request->cookie[$this->di_config->cookie_name] ?? '';
        if (!is_string($cookie) || $cookie === '') {
            return;
        }

        $found_user_id = $this->getUserIdForCookieInDatabase(
            cookie: $cookie,
            user_agent: self::serverString('HTTP_USER_AGENT')
        );
        if ($found_user_id <= 0) {
            $this->killCookie();
            return;
        }

        $this->di_cookies->touch(hash('sha256', $cookie));
        $this->who_is_logged_in = $found_user_id;
        $this->setUsernameOfLoggedInID($found_user_id);
    }

    /**
     * Password login. Called by /login/ on POST and nowhere else, so a stray
     * username/pass pair in some other form is just data, and a wrong
     * password can no longer log a user out of an unrelated page.
     *
     * BadCredentials has no side effects; the caller shows one generic
     * message for it so a guesser cannot learn which usernames exist.
     */
    public function attemptPasswordLogin(string $username, string $password): LoginResult
    {
        $ip_address = self::serverString('REMOTE_ADDR');
        if ($this->di_throttle->isThrottled($username, $ip_address)) {
            // Do not even look at the password: a throttled guess must cost
            // the attacker nothing in information and us nothing in bcrypt.
            return LoginResult::Throttled;
        }

        $user_id = $this->checkPHPHashedPassword($username, $password);
        if ($user_id <= 0) {
            $this->di_throttle->recordFailure($username, $ip_address);
            return LoginResult::BadCredentials;
        }

        $this->di_throttle->clearFailures($username);
        $this->establishSession($user_id);
        return LoginResult::Success;
    }

    /**
     * The one way to become logged in. EVERY authentication path (password
     * today; emailed sign-in links, OAuth, whatever comes next) must end by
     * calling this and nothing else, so no path can skip the fixation guard
     * or end up with different persistence from the others.
     */
    private function establishSession(int $user_id): void
    {
        // Fresh privilege level → fresh session id (fixation guard): a
        // pre-set session id must not survive the authentication boundary.
        session_regenerate_id(true);
        // regenerate_id keeps the session data, so a CSRF token minted (or
        // fixated) before login would survive it. Drop it; the next page
        // render mints a fresh one.
        unset($_SESSION[\Security\CSRFProtectaroo::FIELD]);
        $this->setAutoLoginCookie($user_id);
        $this->who_is_logged_in = $user_id;
        $this->setUsernameOfLoggedInID($user_id);
    }

    private function setUsernameOfLoggedInID(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }
        // set the session variable for username
        $stmt = $this->di_pdo->prepare("SELECT `username` FROM `users` WHERE `user_id` = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $username = $stmt->fetchColumn();

        if (is_string($username)) {
            $this->loggedInUsername = $username;
        }
    }

    public function getLoggedInUsername(): string
    {
        return $this->loggedInUsername;
    }

    public function getUserRole(): string
    {
        if ($this->who_is_logged_in <= 0) {
            return '';
        }

        $stmt = $this->di_pdo->prepare("SELECT `role` FROM `users` WHERE `user_id` = ? LIMIT 1");
        $stmt->execute([$this->who_is_logged_in]);
        $role = $stmt->fetchColumn();

        return is_string($role) ? $role : '';
    }

    public function isAdmin(): bool
    {
        return $this->getUserRole() === 'admin';
    }
    private function setAutoLoginCookie(int $user_id): void
    {
        $cookie = $this->di_token->generate(32);
        $expires_ts = time() + $this->di_config->cookie_lifetime; // 30 days

        // Store only the SHA-256 of the token: a leaked DB dump/backup must
        // not contain ready-to-use session tokens. The browser holds the
        // plaintext; lookups hash the presented value (see
        // getUserIdForCookieInDatabase). The row carries its own expires_at,
        // so the lifetime is enforced here and not only by the browser.
        $this->di_cookies->issue(
            user_id: $user_id,
            cookie_hash: hash('sha256', $cookie),
            ip_bin: \Auth\IPBin::ipToBinary(self::serverString('REMOTE_ADDR')),
            user_agent_md5: md5(self::serverString('HTTP_USER_AGENT')),
            lifetime_seconds: $this->di_config->cookie_lifetime,
            user_agent: self::serverString('HTTP_USER_AGENT'),
        );

        $cookie_options = \Auth\CookieOptions::build(
            $this->di_config->domain_name,
            $expires_ts
        );
        setcookie($this->di_config->cookie_name, $cookie, $cookie_options);
    }



    /**
     * @return array<mixed> The user's row, or [] when there is no such user.
     */
    private function getIDandPHPHashedPasswordForUsername(string $username): array
    {
        $stmt = $this->di_pdo->prepare(
            "SELECT `user_id`, `password_hash` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /**
     * Looks up hashed password for username, and checks it against the password provided
     * @return int The user_id, or 0 when the username or password is wrong.
     */
    private function checkPHPHashedPassword(string $username, string $password): int
    {
        $row = $this->getIDandPHPHashedPasswordForUsername($username);
        $hashed_password = $row['password_hash'] ?? null;
        if (!is_string($hashed_password) || !password_verify($password, $hashed_password)) {
            return 0;
        }

        $user_id = $row['user_id'] ?? null;

        return is_numeric($user_id) ? (int) $user_id : 0;
    }

    private function getUserIdForCookieInDatabase(
        string $cookie,
        string $user_agent
    ): int {
        return $this->di_cookies->findUserId(
            cookie_hash: hash('sha256', $cookie),
            user_agent_md5: md5($user_agent),
        );
    }
    public function isLoggedIn(): bool
    {
        return $this->who_is_logged_in > 0;
    }

    public function loggedInID(): int
    {
        return $this->who_is_logged_in;
    }


    /**
     * Sign this user out of every OTHER browser: the remember-me cookie in
     * hand keeps working, everything else is revoked server-side. Call it
     * whenever the password changes; a user who suspects a leaked session
     * has no other remedy. Returns how many sessions were revoked.
     */
    public function revokeOtherSessions(): int
    {
        if ($this->who_is_logged_in <= 0) {
            return 0;
        }
        return $this->di_cookies->revokeAllForUser($this->who_is_logged_in, $this->presentedCookieHash());
    }

    /**
     * Every browser this user is signed in on, this one flagged is_current.
     *
     * @return list<Device>
     */
    public function devices(): array
    {
        if ($this->who_is_logged_in <= 0) {
            return [];
        }
        return $this->di_cookies->listForUser($this->who_is_logged_in, $this->presentedCookieHash());
    }

    /**
     * Sign one of this user's devices out. A cookie_id that is not theirs
     * revokes nothing. Returns whether a device went away.
     */
    public function revokeDevice(int $cookie_id): bool
    {
        if ($this->who_is_logged_in <= 0) {
            return false;
        }
        return $this->di_cookies->revokeDevice($this->who_is_logged_in, $cookie_id);
    }

    /**
     * SHA-256 of the remember-me cookie this browser sent, or null without one.
     */
    private function presentedCookieHash(): ?string
    {
        $presented = $_COOKIE[$this->di_config->cookie_name] ?? '';
        return is_string($presented) && $presented !== '' ? hash('sha256', $presented) : null;
    }

    public function logout(): void
    {
        // Nobody is logged in, so there is nothing to revoke and no session of
        // ours to tear down. resumeFromCookie() has already cleared any cookie
        // that failed to resolve, so this is a no-op rather than a cleanup pass.
        if ($this->who_is_logged_in <= 0) {
            return;
        }

        // Revoke every session this user has, not only the browser that clicked
        // logout. Someone logging out because they think they were compromised
        // gets what they asked for, and a token captured from any of their
        // devices stops working now instead of at its expiry.
        $this->di_cookies->revokeAllForUser($this->who_is_logged_in);

        $this->who_is_logged_in = 0;
        $this->killCookie();
        session_destroy();
        session_start();
        session_regenerate_id();
    }

    private function killCookie(): void
    {
        $cookie_options = \Auth\CookieOptions::build(
            $this->di_config->domain_name,
            time() - 3600
        );
        setcookie($this->di_config->cookie_name, '', $cookie_options);
        $this->who_is_logged_in = 0;
    }

    /**
     * A $_SERVER value as a string; anything absent or odd is ''.
     */
    private static function serverString(string $key): string
    {
        $value = $_SERVER[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
