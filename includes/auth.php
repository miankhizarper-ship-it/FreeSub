<?php

declare(strict_types=1);

/**
 * Authentication, session management and authorization.
 *
 * Session data is never trusted to identify a user by id alone: every request
 * reloads the user row and rejects suspended/deleted accounts.
 */
final class Auth
{
    public const SESSION_KEY = '_auth_user_id';
    public const FINGERPRINT_KEY = '_auth_fingerprint';

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
     * Credentials
     * ------------------------------------------------------------------ */

    /**
     * Hash a plaintext password with the current default algorithm.
     */
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
     * Attempt to sign a user in.
     *
     * @return array{ok: bool, message: string, user?: array<string, mixed>}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));

        $maxAttempts = (int) config('security.login_max_attempts', 5);
        $window = (int) config('security.login_window', 900);

        if (!rate_limit_hit('login:' . $email, $maxAttempts, $window)) {
            audit_log('auth.login_rate_limited', null, ['email' => $email]);

            return [
                'ok' => false,
                'message' => 'Too many sign-in attempts. Please wait a few minutes and try again.',
            ];
        }

        $user = Database::selectOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        // Always run a hash comparison to keep response timing uniform.
        $hash = $user['password_hash'] ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $passwordOk = self::verifyPassword($password, (string) $hash);

        if ($user === null || !$passwordOk) {
            if ($user !== null) {
                self::registerFailedAttempt($user);
            }

            audit_log('auth.login_failed', $user !== null ? (int) $user['id'] : null, ['email' => $email]);

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

        self::login($user);

        return ['ok' => true, 'message' => 'Welcome back!', 'user' => $user];
    }

    private static function registerFailedAttempt(array $user): void
    {
        $attempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;
        $lockedUntil = null;

        if ($attempts >= 10) {
            $lockedUntil = gmdate('Y-m-d H:i:s', time() + 900);
            $attempts = 0;
        }

        try {
            Database::execute(
                'UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked WHERE id = :id',
                ['attempts' => $attempts, 'locked' => $lockedUntil, 'id' => (int) $user['id']]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] failed to record login attempt: ' . $exception->getMessage());
        }
    }

    /**
     * Persist the authenticated user in the session.
     */
    public static function login(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION[self::FINGERPRINT_KEY] = self::fingerprint();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_started_at'] = time();

        self::$user = $user;
        self::$resolved = true;

        rate_limit_clear('login:' . mb_strtolower((string) $user['email']));

        try {
            Database::execute(
                'UPDATE users SET last_login_at = :now, last_login_ip = :ip, failed_login_attempts = 0, locked_until = NULL WHERE id = :id',
                ['now' => now(), 'ip' => request_ip(), 'id' => (int) $user['id']]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] last login update failed: ' . $exception->getMessage());
        }

        audit_log('auth.login', (int) $user['id'], ['email' => $user['email']], 'user', (int) $user['id'], 'Signed in');
    }

    public static function logout(): void
    {
        $userId = self::id();

        if ($userId !== null) {
            audit_log('auth.logout', $userId, [], 'user', $userId, 'Signed out');
        }

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

        try {
            $user = Database::selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => (int) $id]);
        } catch (Throwable $exception) {
            error_log('[freesub] user lookup failed: ' . $exception->getMessage());

            return null;
        }

        if ($user === null || ($user['status'] ?? '') !== 'active') {
            $_SESSION = [];

            return null;
        }

        self::$user = $user;

        return self::$user;
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

    public static function isAdmin(): bool
    {
        return self::user() !== null && (self::$user['role'] ?? 'user') === 'admin';
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
            redirect('/login.php');
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

        // Compare the first (most preferred) media range only.
        $first = trim(explode(',', $accept)[0]);
        $first = trim(explode(';', $first)[0]);

        // "*/*" is what curl and most HTTP clients send by default; JSON is
        // only assumed for the API prefix, not for ordinary pages.
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
