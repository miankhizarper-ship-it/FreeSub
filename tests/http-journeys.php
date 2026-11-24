<?php

declare(strict_types=1);

/**
 * HTTP journey tests. Included by tests/run.php when FREESUB_TEST_BASE_URL is set.
 *
 * Uses cURL with a cookie jar so sessions behave exactly like a browser.
 */

/**
 * @return array{status: int, body: string, headers: string, location: string|null, cookies: string}
 */
function http_request(string $url, ?array $form = null, ?string $jar = '', string $method = 'GET', ?string $raw = null): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'FreeSubTestRunner/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
    ]);

    if ($jar !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);

        if ($raw !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $raw);
        } elseif ($form !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
        }
    }

    $response = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $location = null;

    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $match)) {
        $location = trim($match[1]);
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'location' => $location, 'cookies' => $jar];
}

function http_token(string $html): ?string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $match) ? $match[1] : null;
}

function run_http_journeys(TestRunner $t, string $base): void
{
    $base = rtrim($base, '/');
    $jar = tempnam(sys_get_temp_dir(), 'freesub-jar-') . '.txt';
    $email = 'journey' . random_int(1000, 9999) . '@example.test';

    // ---- Public pages -------------------------------------------------
    $home = http_request($base . '/', null, $jar);
    $t->check('GET / returns 200', $home['status'] === 200, $home['status']);
    $t->check('landing contains search widget', str_contains($home['body'], 'data-subdomain-search'));
    $t->check('landing shows kharcha.info', str_contains($home['body'], 'kharcha.info'));
    $t->check('landing has no PHP notices', !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $home['body']));
    $t->check('security headers present', str_contains($home['headers'], 'X-Content-Type-Options: nosniff') && str_contains($home['headers'], 'X-Frame-Options'));
    $t->check('no X-Powered-By header', stripos($home['headers'], 'X-Powered-By') === false);

    foreach (['/extensions.php', '/login.php', '/register.php', '/abuse.php'] as $path) {
        $page = http_request($base . $path, null, $jar);
        $t->check("GET {$path} returns 200", $page['status'] === 200, $page['status']);
        $t->check("GET {$path} has no PHP errors", !preg_match('/(Fatal error|Warning:|Notice:)/', $page['body']));
    }

    $missing = http_request($base . '/no-such-page.php', null, $jar);
    $t->check('unknown page returns 404', $missing['status'] === 404, $missing['status']);
    $t->check('unknown page renders the styled 404', str_contains($missing['body'], 'could not find that page'));

    // Application internals must never be reachable over HTTP, whatever the
    // web server (Apache denies them; router.php mirrors that for `php -S`).
    foreach (['/config/env.php', '/includes/auth.php', '/database/schema.sql',
              '/tests/run.php', '/storage/logs', '/.env', '/.git/config'] as $internal) {
        $probe = http_request($base . $internal, null, null);
        $t->check("internal path blocked: {$internal}", in_array($probe['status'], [403, 404], true), $probe['status']);
        $t->check("internal path leaks nothing: {$internal}",
            !str_contains($probe['body'], 'password')
            && !str_contains($probe['body'], 'CREATE TABLE')
            && !str_contains($probe['body'], 'DB_PASSWORD'));
    }

    // ---- Availability API ---------------------------------------------
    // The label is unique per run so the suite can be repeated against the
    // same database without cleaning up reservations first.
    $label = 'journey-' . substr(bin2hex(random_bytes(4)), 0, 8);

    $api = http_request($base . '/api/check.php?label=' . $label, null, $jar);
    $payload = json_decode($api['body'], true);
    $t->check('API returns JSON', is_array($payload), substr($api['body'], 0, 120));
    $t->check('API reports available', ($payload['available'] ?? false) === true, $payload);
    $t->check('API fqdn uses root domain', ($payload['fqdn'] ?? '') === $label . '.kharcha.info', $payload['fqdn'] ?? null);

    $invalid = http_request($base . '/api/check.php?label=admin', null, $jar);
    $t->check('API rejects reserved label', (json_decode($invalid['body'], true)['available'] ?? true) === false);

    $postApi = http_request($base . '/api/check.php?label=x', ['label' => 'x'], $jar, 'POST');
    $t->check('API rejects POST', $postApi['status'] === 405, $postApi['status']);

    // ---- Registration --------------------------------------------------
    $register = http_request($base . '/register.php', null, $jar);
    $token = http_token($register['body']);
    $t->check('register form exposes csrf token', $token !== null);

    $forged = http_request($base . '/register.php', [
        '_token' => str_repeat('0', 64), 'name' => 'Forged User', 'email' => 'forged@example.test',
        'password' => 'ForgedPass123', 'password_confirmation' => 'ForgedPass123',
    ], $jar, 'POST');
    $t->check('forged CSRF token rejected with 419', $forged['status'] === 419, $forged['status']);

    $weak = http_request($base . '/register.php', [
        '_token' => (string) $token, 'name' => 'J', 'email' => 'not-an-email', 'password' => 'short', 'password_confirmation' => 'short',
    ], $jar, 'POST');
    $t->check('invalid registration re-renders form', $weak['status'] === 302, $weak['status']);
    $errorsPage = http_request($base . '/register.php', null, $jar);
    $t->check('validation errors are shown', str_contains($errorsPage['body'], 'valid email address'));

    $token = http_token(http_request($base . '/register.php', null, $jar)['body']);
    $signup = http_request($base . '/register.php', [
        '_token' => (string) $token, 'name' => 'Journey Tester', 'email' => $email,
        'password' => 'JourneyPass123', 'password_confirmation' => 'JourneyPass123',
    ], $jar, 'POST');
    $t->check('registration redirects', $signup['status'] === 302, $signup['status']);
    $t->check('redirect targets dashboard', str_contains((string) $signup['location'], 'dashboard'), $signup['location']);

    $dashboard = http_request($base . '/dashboard.php', null, $jar);
    $t->check('dashboard accessible after signup', $dashboard['status'] === 200 && str_contains($dashboard['body'], 'workspace'), $dashboard['status']);

    // ---- Reservation ---------------------------------------------------
    $token = http_token($dashboard['body']);
    $reserve = http_request($base . '/dashboard.php', [
        '_token' => (string) $token, 'action' => 'reserve', 'label' => $label, 'domain_id' => '1', 'purpose' => 'Journey test',
    ], $jar, 'POST');
    $t->check('reservation redirects', $reserve['status'] === 302, $reserve['status']);
    $afterReserve = http_request($base . '/dashboard.php', null, $jar);
    $t->check('reservation appears on dashboard', str_contains($afterReserve['body'], $label . '.kharcha.info'));
    $t->check('reservation is marked pending', str_contains($afterReserve['body'], 'Pending'));
    $t->check('subdomain is NOT reported live', !preg_match('/' . preg_quote($label, '/') . '\.kharcha\.info<\/span>\s*<[^>]*>Active/i', $afterReserve['body']));

    $taken = http_request($base . '/api/check.php?label=' . $label, null, $jar);
    $t->check('reserved label reported as reserved', (json_decode($taken['body'], true)['status'] ?? '') === 'reserved');

    // ---- Authorization -------------------------------------------------
    $admin = http_request($base . '/admin/index.php', null, $jar);
    $t->check('non-admin blocked from admin area', in_array($admin['status'], [302, 403], true), $admin['status']);

    // ---- Logout / login ------------------------------------------------
    $token = http_token($afterReserve['body']);
    $logout = http_request($base . '/logout.php', ['_token' => (string) $token], $jar, 'POST');
    $t->check('logout redirects to login', $logout['status'] === 302 && str_contains((string) $logout['location'], 'login'), $logout['location']);

    $afterLogout = http_request($base . '/dashboard.php', null, $jar);
    $t->check('dashboard requires authentication', in_array($afterLogout['status'], [302, 401], true), $afterLogout['status']);

    $getLogout = http_request($base . '/logout.php', null, $jar);
    $t->check('GET logout does not sign out', $getLogout['status'] === 302);

    $loginPage = http_request($base . '/login.php', null, $jar);
    $token = http_token($loginPage['body']);
    $badLogin = http_request($base . '/login.php', ['_token' => (string) $token, 'email' => $email, 'password' => 'WrongPass123'], $jar, 'POST');
    $t->check('wrong password redirects back', $badLogin['status'] === 302);
    $t->check('wrong password message shown', str_contains(http_request($base . '/login.php', null, $jar)['body'], 'do not match'));

    $token = http_token(http_request($base . '/login.php', null, $jar)['body']);
    $goodLogin = http_request($base . '/login.php', ['_token' => (string) $token, 'email' => strtoupper($email), 'password' => 'JourneyPass123'], $jar, 'POST');
    $t->check('login succeeds with any email casing', $goodLogin['status'] === 302 && str_contains((string) $goodLogin['location'], 'dashboard'), $goodLogin['location']);

    // ---- Abuse report --------------------------------------------------
    $abusePage = http_request($base . '/abuse.php', null, $jar);
    $token = http_token($abusePage['body']);
    $abuse = http_request($base . '/abuse.php', [
        '_token' => (string) $token, 'fqdn' => 'bad.kharcha.info', 'reporter_email' => 'reporter@example.test',
        'category' => 'phishing', 'message' => 'This hostname is impersonating a bank login page, please review.',
    ], $jar, 'POST');
    $t->check('abuse report accepted', $abuse['status'] === 302);
    $t->check('abuse confirmation shown', str_contains(http_request($base . '/abuse.php', null, $jar)['body'], 'logged'));

    @unlink($jar);
}
