<?php

declare(strict_types=1);

/**
 * Shared helpers: escaping, URLs, flash messages, CSRF tokens, validation,
 * rate limiting and lightweight templating.
 */

require_once FREESUB_ROOT . '/includes/database.php';

if (!function_exists('config')) {
    /**
     * Read a dot-notated application config value.
     *
     * `$GLOBALS['FREESUB_CONFIG_OVERRIDE']` lets the test suite pin values that
     * must not depend on the developer's local .env (Clerk keys, URLs). It is
     * never set by the application itself.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $config = require FREESUB_ROOT . '/config/app.php';
        }

        // Merge into a *copy*. Merging into $config would mutate the cache, so an
        // override could never be withdrawn and would leak into every later call
        // in the same process.
        $resolved = $config;

        if (isset($GLOBALS['FREESUB_CONFIG_OVERRIDE']) && is_array($GLOBALS['FREESUB_CONFIG_OVERRIDE'])) {
            $resolved = array_replace_recursive($resolved, $GLOBALS['FREESUB_CONFIG_OVERRIDE']);
        }

        if ($key === null || $key === '') {
            return $resolved;
        }

        $value = $resolved;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output. Always use this for dynamic content.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('app_debug')) {
    function app_debug(): bool
    {
        return (bool) config('debug', false);
    }
}

if (!function_exists('app_url')) {
    /**
     * Absolute URL for a path inside the application.
     */
    function app_url(string $path = '/'): string
    {
        $base = (string) config('url', '');

        if ($base === '') {
            $base = base_url();
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('base_url')) {
    /**
     * Detect the public base URL of the application (scheme + host + base path).
     *
     * Works when FreeSub is served from a domain root *and* when it lives in a
     * subdirectory, and it is correct for pages inside /admin and /api too.
     */
    function base_url(): string
    {
        static $cached = null;

        if (is_string($cached)) {
            return $cached;
        }

        $scheme = is_https() ? 'https' : 'http';
        $basePath = app_base_path();

        $cached = $scheme . '://' . request_host() . $basePath;

        return $cached;
    }
}

if (!function_exists('app_base_path')) {
    /**
     * The path prefix the application is mounted on ('' for the domain root).
     */
    function app_base_path(): string
    {
        static $cached = null;

        if (is_string($cached)) {
            return $cached;
        }

        $cached = '';

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');

        if ($scriptName === '' || $scriptFile === '') {
            return $cached;
        }

        $realScript = realpath($scriptFile);
        $realRoot = realpath(FREESUB_ROOT);

        if ($realScript === false || $realRoot === false || !str_starts_with($realScript, $realRoot)) {
            return $cached;
        }

        // "/admin/reservations.php" — the script path relative to the app root.
        $relative = str_replace('\\', '/', substr($realScript, strlen($realRoot)));

        if ($relative === '' || !str_ends_with($scriptName, $relative)) {
            return $cached;
        }

        $prefix = substr($scriptName, 0, strlen($scriptName) - strlen($relative));
        $cached = rtrim($prefix, '/');

        return $cached;
    }
}

if (!function_exists('is_https')) {
    function is_https(): bool
    {
        if (env_bool('SECURITY_FORCE_HTTPS', false)) {
            return true;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return is_trusted_proxy() && $forwardedProto === 'https';
    }
}

if (!function_exists('is_trusted_proxy')) {
    function is_trusted_proxy(): bool
    {
        $proxies = config('security.trusted_proxies', []);

        if (!is_array($proxies) || $proxies === []) {
            return false;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        foreach ($proxies as $proxy) {
            if (str_contains($proxy, '/') && cidr_match($ip, $proxy)) {
                return true;
            }

            if (hash_equals((string) $proxy, $ip)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('cidr_match')) {
    function cidr_match(string $ip, string $range): bool
    {
        if ($ip === '' || !str_contains($range, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBinary, $subnetBinary, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}

if (!function_exists('request_host')) {
    function request_host(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');

        if ($forwarded !== '' && is_trusted_proxy()) {
            $host = trim(explode(',', $forwarded)[0]);
        }

        // Defend against header injection and absurd host values.
        $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?: 'localhost';

        return $host;
    }
}

if (!function_exists('request_ip')) {
    function request_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (is_trusted_proxy()) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

            if ($forwarded !== '') {
                $candidate = trim(explode(',', $forwarded)[0]);

                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    $ip = $candidate;
                }
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to a local path (or an explicit absolute URL) and stop.
     */
    function redirect(string $path, int $status = 302): never
    {
        $location = preg_match('#^https?://#i', $path) ? $path : app_url($path);

        if (!headers_sent()) {
            header('Location: ' . $location, true, $status);
        }

        exit;
    }
}

if (!function_exists('current_path')) {
    function current_path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }
}

if (!function_exists('input')) {
    /**
     * Read a (trimmed) string from POST, then GET.
     */
    function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }
}

if (!function_exists('old')) {
    /**
     * Remember submitted form values for one redirect (no passwords).
     */
    function flash_old_input(array $values): void
    {
        $safe = [];

        foreach ($values as $key => $value) {
            if (is_scalar($value) && !in_array($key, ['password', 'password_confirmation', 'current_password', '_token'], true)) {
                $safe[$key] = (string) $value;
            }
        }

        $_SESSION['_old_input'] = $safe;
    }

    function old(string $key, string $default = ''): string
    {
        return (string) ($_SESSION['_old_input'][$key] ?? $default);
    }

    function clear_old_input(): void
    {
        unset($_SESSION['_old_input']);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token = null): bool
    {
        $token ??= $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!is_string($token) || $token === '') {
            return false;
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? null;

        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('csrf_check')) {
    /**
     * Enforce CSRF protection for state changing requests.
     */
    function csrf_check(): void
    {
        if (csrf_verify()) {
            return;
        }

        http_response_code(419);

        $view = FREESUB_ROOT . '/includes/views/errors/419.php';

        if (is_file($view) && function_exists('view')) {
            try {
                view('errors/419', ['pageTitle' => 'Session expired']);

                exit;
            } catch (Throwable) {
                // Fall through to the self-contained page below.
            }
        }

        header('Content-Type: text/html; charset=utf-8');

        $message = 'Your session expired or the form token was invalid. Please go back, refresh the page and try again.';
        $home = e(app_url('/'));

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Session expired · ' . e((string) config('name', 'FreeSub')) . '</title>'
            . '<link rel="stylesheet" href="' . e(app_url('/assets/vendor/bootstrap.min.css')) . '"></head>'
            . '<body class="bg-light"><main class="container py-5" style="max-width:640px">'
            . '<div class="card shadow-sm border-0"><div class="card-body p-4 p-md-5 text-center">'
            . '<h1 class="h4 mb-3">Session expired</h1><p class="text-secondary mb-4">' . e($message) . '</p>'
            . '<a class="btn btn-primary" href="' . $home . '">Back to FreeSub</a>'
            . '</div></div></main></body></html>';

        exit;
    }
}

if (!function_exists('flash')) {
    /**
     * Queue a flash message: flash('success', 'Saved!').
     */
    function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('flash_messages')) {
    /**
     * @return list<array{type: string, message: string}>
     */
    function flash_messages(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }
}

if (!function_exists('errors')) {
    /**
     * @return array<string, string>
     */
    function errors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        return is_array($errors) ? $errors : [];
    }

    /**
     * @param array<string, string> $errors
     */
    function flash_errors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }
}

if (!function_exists('error_for')) {
    /**
     * @param array<string, string> $errors
     */
    function error_for(array $errors, string $field): ?string
    {
        return $errors[$field] ?? null;
    }
}

if (!function_exists('view')) {
    /**
     * Render a template from includes/views with optional layout.
     *
     * @param array<string, mixed> $data
     */
    function view(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        $path = FREESUB_ROOT . '/includes/views/' . $template . '.php';

        if (!is_file($path)) {
            http_response_code(500);
            error_log('[freesub] missing view: ' . $template);
            echo 'View not found.';

            return;
        }

        extract($data, EXTR_SKIP);

        if ($layout === null) {
            require $path;

            return;
        }

        ob_start();
        require $path;
        $content = (string) ob_get_clean();

        require FREESUB_ROOT . '/includes/views/' . $layout . '.php';
    }
}

if (!function_exists('partial')) {
    /**
     * Include a template partial without a layout.
     *
     * @param array<string, mixed> $data
     */
    function partial(string $template, array $data = []): void
    {
        $path = FREESUB_ROOT . '/includes/views/' . $template . '.php';

        if (!is_file($path)) {
            error_log('[freesub] missing partial: ' . $template);

            return;
        }

        extract($data, EXTR_SKIP);
        require $path;
    }
}

if (!function_exists('json_response')) {
    /**
     * Emit a JSON API response and stop.
     *
     * @param array<string, mixed>|list<mixed> $payload
     */
    function json_response(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_error')) {
    /**
     * @param array<string, string> $fieldErrors
     */
    function json_error(string $message, int $status = 422, array $fieldErrors = []): never
    {
        $payload = ['ok' => false, 'message' => $message];

        if ($fieldErrors !== []) {
            $payload['errors'] = $fieldErrors;
        }

        json_response($payload, $status);
    }
}

if (!function_exists('audit_log')) {
    /**
     * Append an audit log entry. Never throws: logging must not break a request.
     *
     * The actor type is derived from the authenticated account's role (not from
     * the session flag) so CLI actions and admin changes are attributed
     * correctly. Pass 'actor_type' => 'system' in $context for jobs/cron.
     *
     * @param array<string, mixed> $context
     */
    function audit_log(
        string $action,
        ?int $userId = null,
        array $context = [],
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null
    ): void {
        try {
            $actorType = null;

            if (isset($context['actor_type']) && in_array($context['actor_type'], ['user', 'admin', 'system', 'guest'], true)) {
                $actorType = (string) $context['actor_type'];
                unset($context['actor_type']);
            }

            if ($actorType === null) {
                if ($userId === null) {
                    $actorType = 'guest';
                } else {
                    $role = Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $userId]);
                    $actorType = $role === 'admin' ? 'admin' : 'user';
                }
            }

            Database::execute(
                'INSERT INTO audit_logs (user_id, actor_type, action, entity_type, entity_id, description, context, ip_address, user_agent)
                 VALUES (:user_id, :actor_type, :action, :entity_type, :entity_id, :description, :context, :ip_address, :user_agent)',
                [
                    'user_id' => $userId,
                    'actor_type' => $actorType,
                    'action' => mb_substr($action, 0, 80),
                    'entity_type' => $entityType !== null ? mb_substr($entityType, 0, 60) : null,
                    'entity_id' => $entityId,
                    'description' => $description !== null ? mb_substr($description, 0, 500) : null,
                    'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? request_ip() : null,
                    'user_agent' => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] audit log failed: ' . $exception->getMessage());
        }
    }
}

if (!function_exists('rate_limit_hit')) {
    /**
     * Simple file-backed sliding window limiter for unauthenticated endpoints.
     * Returns false when the limit is already exhausted.
     */
    function rate_limit_hit(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $directory = FREESUB_ROOT . '/storage/cache/rate-limits';

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            // Fail open: never lock users out because storage is unavailable.
            return true;
        }

        $key = hash('sha256', $bucket . '|' . request_ip());
        $path = $directory . '/' . $key . '.json';
        $now = time();
        $attempts = [];

        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = $raw !== false ? json_decode($raw, true) : null;

            if (is_array($decoded)) {
                $attempts = array_values(array_filter(
                    array_map('intval', $decoded),
                    static fn (int $timestamp): bool => $timestamp > $now - $windowSeconds
                ));
            }
        }

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;

        @file_put_contents($path, json_encode($attempts), LOCK_EX);

        return true;
    }
}

if (!function_exists('rate_limit_clear')) {
    function rate_limit_clear(string $bucket): void
    {
        $path = FREESUB_ROOT . '/storage/cache/rate-limits/' . hash('sha256', $bucket . '|' . request_ip()) . '.json';

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $value, string $format = 'M j, Y'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? '—' : gmdate($format, $timestamp);
    }
}

if (!function_exists('str_limit')) {
    function str_limit(string $value, int $limit = 80): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
    }
}

if (!function_exists('asset')) {
    /**
     * Cache-busted asset URL.
     */
    function asset(string $path): string
    {
        $file = FREESUB_ROOT . '/assets/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return app_url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
    }
}

if (!function_exists('is_active_path')) {
    function is_active_path(string ...$paths): bool
    {
        $current = current_path();

        foreach ($paths as $path) {
            if ($path === '/' ? $current === '/' || $current === '/index.php' : str_starts_with($current, $path)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : 'U';
    }
}
