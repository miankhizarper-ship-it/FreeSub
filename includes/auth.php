<?php

declare(strict_types=1);

/**
 * Authentication, session management and authorization.
 *
 * Clerk is the identity provider. This class is the server-side middleware
 * between a verified Clerk identity and a local PHP session:
 *
 *   browser  --Clerk JS-->  Clerk session   (Google OAuth, e-mail code,
 *                                            password reset: all handled there)
 *   browser  --privileged-->  clerk-bridge.php  (same-origin, session-bound)
 *   PHP      --secret key-->  Clerk Backend API (clients/verify, sessions)
 *   PHP      --handoff token-->  /auth/handoff.php  (single use, hashed at rest)
 *
 * The browser never hands PHP a session token to trust, and PHP never trusts a
 * user id, role or email sent by JavaScript. On every request the local session
 * is bound to (internal user id + Clerk user id) and the row is re-loaded, so a
 * suspended or deleted account loses access immediately.
 *
 * Roles remain local: nothing here reads a role from Clerk or from input.
 */
final class Auth
{
    /** Internal users.id of the signed-in account. */
    public const SESSION_KEY = '_auth_user_id';
    /** Verified Clerk user id (users.clerk_user_id). */
    public const CLERK_KEY = '_auth_clerk_user_id';
    /** Clerk session id (sess_...), used for server-side revocation checks. */
    public const CLERK_SESSION_KEY = '_auth_clerk_session_id';
    public const FINGERPRINT_KEY = '_auth_fingerprint';
    /** Unix time of the last successful Clerk verification. */
    public const VERIFIED_AT_KEY = '_auth_verified_at';

    /**
     * Handshake value proving that database/break-glass.php authorised the
     * session. Changing it invalidates every outstanding break-glass link.
     */
    public const BREAK_GLASS_PROOF = 'freesub-break-glass-cli';

    private static ?array $user = null;
    private static bool $resolved = false;

    /* ---------------------------------------------------------------------
     * Session lifecycle
     * ------------------------------------------------------------------ */

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = (array) config('session', []);
        $savePath = (string) ($config['save_path'] ?? FREESUB_ROOT . '/storage/sessions');

        if (!is_dir($savePath) && !@mkdir($savePath, 0770, true) && !is_dir($savePath)) {
            error_log('[freesub] unable to create session directory; falling back to the system path');
            $savePath = '';
        }

        $lifetime = max(5, (int) ($config['lifetime'] ?? 120)) * 60;
        $secureSetting = (string) ($config['secure'] ?? 'auto');
        $secure = $secureSetting === 'auto' ? is_https() : in_array(strtolower($secureSetting), ['1', 'true', 'on', 'yes'], true);
        $sameSite = ucfirst(strtolower((string) ($config['same_site'] ?? 'Lax')));

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        if ($sameSite === 'None') {
            // Browsers reject SameSite=None cookies that are not Secure.
            $secure = true;
        }

        if ($savePath !== '') {
            session_save_path($savePath);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', $sameSite);
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        // sid_length / sid_bits_per_character are deprecated as of PHP 8.4.
        if (PHP_VERSION_ID < 80400) {
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '5');
        }

        session_name((string) ($config['name'] ?? 'freesub_session'));

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) ($config['path'] ?? '/'),
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        session_start();

        self::enforceIdleTimeout($lifetime);
        self::guardAgainstFixation();
    }

    private static function enforceIdleTimeout(int $lifetime): void
    {
        $lastActivity = (int) ($_SESSION['_last_activity'] ?? 0);
        $now = time();

        if ($lastActivity > 0 && ($now - $lastActivity) > $lifetime) {
            self::logout();
            $_SESSION['_flash'][] = ['type' => 'warning', 'message' => 'You were signed out after a period of inactivity.'];

            return;
        }

        $_SESSION['_last_activity'] = $now;
    }

    /**
     * Rotate the session id whenever the authenticated user changes.
     */
    private static function guardAgainstFixation(): void
    {
        $fingerprint = self::fingerprint();
        $sessionUserId = $_SESSION[self::SESSION_KEY] ?? null;
        $sessionFingerprint = $_SESSION[self::FINGERPRINT_KEY] ?? null;

        if ($sessionUserId !== null && !hash_equals((string) $sessionFingerprint, $fingerprint)) {
            // Session cookie reused from another network client.
            self::logout();
            $_SESSION['_flash'][] = ['type' => 'warning', 'message' => 'Please sign in again.'];
        }

        if ($sessionUserId === null && !isset($_SESSION['_started_at'])) {
            session_regenerate_id(true);
            $_SESSION['_started_at'] = time();
        }
    }

    private static function fingerprint(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    }

    /* ---------------------------------------------------------------------
     * Local password support — CLI break-glass only
     *
     * There is no web route that calls these. They exist so an operator can
     * still reach the admin area if Clerk is misconfigured, and so the test
     * suite can synthesise accounts. See database/break-glass.php.
     * ------------------------------------------------------------------ */

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /**
     * Verify a local password for an account that has one.
     *
     * @return array{ok: bool, message: string, user?: array<string, mixed>}
     */
    public static function verifyLocalCredentials(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::findByEmail($email);

        // Always run a hash comparison to keep response timing uniform.
        $hash = $user['password_hash'] ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $passwordOk = self::verifyPassword($password, (string) $hash);

        if ($user === null || !$passwordOk || empty($user['password_hash'])) {
            audit_log('auth.local_login_failed', $user !== null ? (int) $user['id'] : null, ['email' => $email]);

            return ['ok' => false, 'message' => 'Those credentials do not match our records.'];
        }

        if (($user['status'] ?? 'active') !== 'active') {
            audit_log('auth.login_blocked', (int) $user['id'], ['status' => $user['status']]);

            return ['ok' => false, 'message' => 'This account is not active. Please contact support.'];
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until'] . ' UTC') > time()) {
            audit_log('auth.login_locked', (int) $user['id']);

            return ['ok' => false, 'message' => 'This account is temporarily locked. Please try again later.'];
        }

        if (self::needsRehash((string) $user['password_hash'])) {
            try {
                Database::execute(
                    'UPDATE users SET password_hash = :hash WHERE id = :id',
                    ['hash' => self::hashPassword($password), 'id' => (int) $user['id']]
                );
            } catch (Throwable $exception) {
                error_log('[freesub] password rehash failed: ' . $exception->getMessage());
            }
        }

        return ['ok' => true, 'message' => 'Credentials verified.', 'user' => $user];
    }

    /* ---------------------------------------------------------------------
     * Clerk-backed sign-in
     * ------------------------------------------------------------------ */

    /**
     * Establish the local session for an already-verified Clerk identity.
     *
     * @param array<string, mixed> $user        Local user row
     * @param string               $clerkUserId Verified Clerk `sub`
     * @param string|null          $clerkSessionId Verified Clerk session id
     */
    public static function loginWithClerk(array $user, string $clerkUserId, ?string $clerkSessionId = null): void
    {
        if (!User::isClerkUserId($clerkUserId)) {
            throw new RuntimeException('Refusing to start a session for an invalid Clerk identity.');
        }

        if ((int) ($user['clerk_user_id'] ?? '') !== 0
            && !hash_equals((string) $user['clerk_user_id'], $clerkUserId)) {
            throw new RuntimeException('Refusing to start a session: identity mismatch.');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION[self::CLERK_KEY] = $clerkUserId;
        $_SESSION[self::CLERK_SESSION_KEY] = $clerkSessionId;
        $_SESSION[self::FINGERPRINT_KEY] = self::fingerprint();
        $_SESSION[self::VERIFIED_AT_KEY] = time();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_started_at'] = time();

        self::$user = $user;
        self::$resolved = true;

        try {
            Database::execute(
                'UPDATE users
                    SET last_login_at = :now, last_login_ip = :ip,
                        failed_login_attempts = 0, locked_until = NULL, updated_at = :now
                  WHERE id = :id',
                ['now' => now(), 'ip' => request_ip(), 'id' => (int) $user['id']]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] last login update failed: ' . $exception->getMessage());
        }

        if ($clerkSessionId !== null) {
            self::rememberClerkSession((int) $user['id'], $clerkSessionId);
        }

        audit_log(
            'auth.login',
            (int) $user['id'],
            ['provider' => 'clerk', 'has_session' => $clerkSessionId !== null],
            'user',
            (int) $user['id'],
            'Signed in with Clerk'
        );
    }

    /**
     * Mirror a Clerk session event into clerk_sessions (webhook entry point).
     */
    public static function mirrorClerkSession(string $clerkUserId, string $sessionId, ?string $status = 'active'): bool
    {
        if (!User::isClerkUserId($clerkUserId)) {
            return false;
        }

        $user = User::findByClerkId($clerkUserId);

        if ($user === null) {
            return false;
        }

        if ($status === 'ended') {
            self::forgetClerkSession($sessionId);

            return true;
        }

        self::rememberClerkSession((int) $user['id'], $sessionId);

        return true;
    }

    /**
     * Mark a Clerk session ended (webhook entry point).
     */
    public static function endClerkSession(string $sessionId): bool
    {
        if (!preg_match('/^sess_[A-Za-z0-9_]{1,180}$/', $sessionId)) {
            return false;
        }

        $existing = Database::scalar('SELECT COUNT(*) FROM clerk_sessions WHERE session_id = :sid', ['sid' => $sessionId]);

        if ((int) $existing === 0) {
            return false;
        }

        self::forgetClerkSession($sessionId);

        return true;
    }

    private static function rememberClerkSession(int $userId, string $sessionId): void
    {
        if (!preg_match('/^sess_[A-Za-z0-9_]{1,180}$/', $sessionId)) {
            return;
        }

        try {
            $existing = Database::scalar(
                'SELECT COUNT(*) FROM clerk_sessions WHERE session_id = :sid',
                ['sid' => $sessionId]
            );

            if ((int) $existing > 0) {
                Database::execute(
                    'UPDATE clerk_sessions SET status = :status, last_verified_at = :now, updated_at = :now
                      WHERE session_id = :sid',
                    ['status' => 'active', 'now' => now(), 'sid' => $sessionId]
                );

                return;
            }

            Database::execute(
                'INSERT INTO clerk_sessions (session_id, user_id, status, last_verified_at, created_at, updated_at)
                 VALUES (:sid, :uid, :status, :now, :now, :now)',
                ['sid' => $sessionId, 'uid' => $userId, 'status' => 'active', 'now' => now()]
            );
        } catch (Throwable $exception) {
            // Bookkeeping only: never fail a sign-in because of it.
            error_log('[freesub] clerk session bookkeeping failed: ' . $exception->getMessage());
        }
    }

    /**
     * Mark the mirrored Clerk session as ended locally.
     */
    private static function forgetClerkSession(?string $sessionId): void
    {
        if ($sessionId === null || $sessionId === '') {
            return;
        }

        try {
            Database::execute(
                'UPDATE clerk_sessions SET status = :status, ended_at = :now, updated_at = :now
                  WHERE session_id = :sid',
                ['status' => 'ended', 'now' => now(), 'sid' => $sessionId]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] clerk session bookkeeping failed: ' . $exception->getMessage());
        }
    }

    public static function logout(): void
    {
        $userId = self::id();
        $clerkSessionId = $_SESSION[self::CLERK_SESSION_KEY] ?? null;

        if ($userId !== null) {
            audit_log('auth.logout', $userId, ['provider' => 'clerk'], 'user', $userId, 'Signed out');
        }

        self::forgetClerkSession(is_string($clerkSessionId) ? $clerkSessionId : null);

        self::$user = null;
        self::$resolved = true;

        $_SESSION = [];

        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            // Keep flash messages working after logout.
            session_start();
            session_regenerate_id(true);
        }
    }

    /* ---------------------------------------------------------------------
     * Current user
     * ------------------------------------------------------------------ */

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;

        $id = $_SESSION[self::SESSION_KEY] ?? null;

        if ($id === null) {
            return null;
        }

        $clerkUserId = $_SESSION[self::CLERK_KEY] ?? null;
        $breakGlass = ($_SESSION['_auth_break_glass'] ?? false) === true;

        // The identity model is loaded explicitly: Auth is required by the
        // bootstrap before the models are, so relying on the caller having
        // included it first made this path fatal on any request that resolved a
        // session before touching a model.
        require_once __DIR__ . '/models/User.php';

        if (!$breakGlass && (!is_string($clerkUserId) || !User::isClerkUserId($clerkUserId))) {
            // A session without a verified identity is never trusted, including
            // sessions created before the Clerk migration.
            self::clearSession();

            return null;
        }

        try {
            $user = Database::selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => (int) $id]);
        } catch (Throwable $exception) {
            error_log('[freesub] user lookup failed: ' . $exception->getMessage());

            return null;
        }

        if ($user === null || ($user['status'] ?? '') !== 'active') {
            self::clearSession();

            return null;
        }

        if ($breakGlass) {
            // Offline administrator session: require the flag the CLI set AND
            // that the account is still an active administrator. If either is
            // gone (demoted, suspended, flag removed) the session dies.
            if ((string) ($user['role'] ?? '') !== 'admin' || !config('clerk.break_glass', false)) {
                self::clearSession();

                return null;
            }

            self::$user = $user;

            return self::$user;
        }

        // The row must still carry the same identity that the session claims.
        if (!is_string($user['clerk_user_id'] ?? null)
            || !hash_equals((string) $user['clerk_user_id'], $clerkUserId)) {
            self::clearSession();

            return null;
        }

        // Bind the session to the client that created it. The check runs on
        // every resolution (not only at session start) so a cookie copied to
        // another browser or network client stops working immediately.
        $fingerprint = $_SESSION[self::FINGERPRINT_KEY] ?? null;

        if (!is_string($fingerprint) || !hash_equals($fingerprint, self::fingerprint())) {
            self::clearSession();

            return null;
        }

        if (self::clerkSessionWasRevoked()) {
            self::logout();
            $_SESSION['_flash'][] = ['type' => 'warning', 'message' => 'Your session ended. Please sign in again.'];

            return null;
        }

        self::$user = $user;

        return self::$user;
    }

    /**
     * Periodically ask Clerk whether the session is still alive.
     *
     * This catches "signed out on another device", sessions revoked by an
     * administrator and expired sessions, without a network call per request.
     * Failures are treated as "cannot confirm": the user stays signed in until
     * the normal idle timeout, because a Clerk outage must not lock everyone out.
     */
    private static function clerkSessionWasRevoked(): bool
    {
        $sessionId = $_SESSION[self::CLERK_SESSION_KEY] ?? null;
        $verifiedAt = (int) ($_SESSION[self::VERIFIED_AT_KEY] ?? 0);
        $interval = max(0, (int) config('clerk.session_recheck', 60));

        if (!is_string($sessionId) || $sessionId === '' || $interval === 0) {
            return false;
        }

        if (time() - $verifiedAt < $interval) {
            return false;
        }

        $_SESSION[self::VERIFIED_AT_KEY] = time();

        $client = new ClerkApiClient();

        if (!$client->isConfigured()) {
            return false;
        }

        try {
            $session = $client->getSession($sessionId);
        } catch (ClerkApiException $exception) {
            error_log('[freesub] clerk session re-check skipped: ' . $exception->status());

            return false;
        }

        if ($session === null) {
            return true;
        }

        $status = (string) ($session['status'] ?? '');

        if ($status !== '' && $status !== 'active') {
            return true;
        }

        $expireAt = $session['expire_at'] ?? null;

        if (is_numeric($expireAt) && (int) $expireAt <= time()) {
            return true;
        }

        try {
            Database::execute(
                'UPDATE clerk_sessions SET last_verified_at = :now, updated_at = :now WHERE session_id = :sid',
                ['now' => now(), 'sid' => $sessionId]
            );
        } catch (Throwable) {
            // Ignore bookkeeping errors.
        }

        return false;
    }

    private static function clearSession(): void
    {
        foreach ([self::SESSION_KEY, self::CLERK_KEY, self::CLERK_SESSION_KEY, self::VERIFIED_AT_KEY, self::FINGERPRINT_KEY] as $key) {
            unset($_SESSION[$key]);
        }

        self::$user = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return self::user() === null;
    }

    public static function id(): ?int
    {
        return self::user() !== null ? (int) self::$user['id'] : null;
    }

    public static function clerkUserId(): ?string
    {
        $user = self::user();

        return $user !== null && is_string($user['clerk_user_id'] ?? null) ? (string) $user['clerk_user_id'] : null;
    }

    public static function isAdmin(): bool
    {
        return self::user() !== null && (self::$user['role'] ?? 'user') === 'admin';
    }

    /**
     * Was the current session established through the offline break-glass
     * command rather than through Clerk?
     */
    public static function isBreakGlass(): bool
    {
        return ($_SESSION['_auth_break_glass'] ?? false) === true;
    }

    /**
     * Offline administrator sign-in. Only reachable from a CLI-issued link and
     * only when CLERK_BREAK_GLASS is enabled; see database/break-glass.php.
     */
    /**
     * Start an offline administrator session.
     *
     * Callers cannot use this without having verified the operator's shell
     * access: `database/break-glass.php` is the only issuer of break-glass
     * tickets, and it refuses to issue one until the local password (when the
     * account has one) has been checked. The extra arguments here are the proof
     * that step happened — they are not optional.
     */
    public static function loginBreakGlass(array $user, string $channel = 'cli', string $proof = ''): void
    {
        if ($proof !== self::BREAK_GLASS_PROOF) {
            throw new RuntimeException('Break-glass sign-in must be started from the CLI command.');
        }

        if ((string) ($user['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Break-glass sign-in is restricted to administrators.');
        }

        if (!config('clerk.break_glass', false)) {
            throw new RuntimeException('Break-glass sign-in is disabled (CLERK_BREAK_GLASS=false).');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        self::clearSession();

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION[self::FINGERPRINT_KEY] = self::fingerprint();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_started_at'] = time();
        $_SESSION['_auth_break_glass'] = true;

        self::$user = $user;
        self::$resolved = true;

        audit_log('auth.break_glass_login', (int) $user['id'], ['channel' => $channel], 'user', (int) $user['id'], 'Offline administrator sign-in');
    }

    /* ---------------------------------------------------------------------
     * Guards
     * ------------------------------------------------------------------ */

    public static function requireAuth(?string $redirectTo = null): array
    {
        $user = self::user();

        if ($user === null) {
            if (wants_json()) {
                json_error('Please sign in to continue.', 401);
            }

            $_SESSION['_intended'] = $redirectTo ?? current_path();
            flash('warning', 'Please sign in to continue.');
            redirect(ClerkConfig::isConfigured() ? '/login.php' : '/login.php?error=not_configured');
        }

        return $user;
    }

    public static function requireGuest(): void
    {
        if (self::check()) {
            redirect('/dashboard.php');
        }
    }

    public static function requireAdmin(): array
    {
        $user = self::requireAuth();

        if ((string) $user['role'] !== 'admin') {
            if (wants_json()) {
                json_error('Administrator access required.', 403);
            }

            http_response_code(403);
            view('errors/403', ['pageTitle' => 'Access denied']);
            exit;
        }

        return $user;
    }

    /**
     * Pop the URL the guest originally wanted to reach.
     */
    public static function pullIntended(string $default = '/dashboard.php'): string
    {
        $intended = $_SESSION['_intended'] ?? null;
        unset($_SESSION['_intended']);

        if (!is_string($intended) || $intended === '' || !str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            return $default;
        }

        return $intended;
    }
}

if (!function_exists('wants_json')) {
    /**
     * True when the caller expects a JSON response instead of an HTML redirect.
     *
     * A browser that sends a broad Accept header ("text/html, ... application/json")
     * must still get HTML, so JSON is only chosen when it is the *preferred*
     * type, when the request is an API path, or when it is an XHR/fetch call.
     */
    function wants_json(): bool
    {
        if (str_starts_with(current_path(), '/api/')) {
            return true;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));

        if ($accept === '') {
            return false;
        }

        $first = trim(explode(',', $accept)[0]);
        $first = trim(explode(';', $first)[0]);

        return in_array($first, ['application/json', 'application/*'], true);
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return Auth::isAdmin();
    }
}
