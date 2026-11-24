<?php

declare(strict_types=1);

/**
 * Privileged, same-origin bridge between the Clerk browser SDK and PHP.
 *
 * This is the only endpoint that accepts a token minted by the browser. It is
 * deliberately strict:
 *
 *   * POST only, same-origin only (Origin checked against the allowlist).
 *   * The request must carry the channel + state that login.php created inside
 *     the server session, so a third-party page cannot drive it.
 *   * Rate limited per session and per IP.
 *   * It never returns user data, claims or tokens back to the page beyond the
 *     single-use handoff token, and it never echoes an exception message.
 *
 * The page keeps the browser SDK in charge of everything the user actually
 * interacts with (Google OAuth, e-mail codes, password reset). This endpoint
 * only converts "Clerk says this browser is signed in" into "this server
 * session may finish signing in".
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    header('Allow: POST');
    json_response([
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Use POST.',
    ], 405);
}

// Same-origin enforcement. Browsers send Origin on cross-site POSTs and on all
// fetch() requests; a missing Origin is tolerated only because some privacy
// tools strip it for same-origin navigation.
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null;

if ($origin !== null && $origin !== '' && !ClerkConfig::isAuthorizedParty($origin)) {
    audit_log('auth.clerk_origin_rejected', null, ['origin' => mb_substr($origin, 0, 120)]);

    json_response([
        'ok' => false,
        'error' => 'origin_rejected',
        'message' => 'This sign-in was started from an untrusted origin.',
    ], 403);
}

if (!ClerkConfig::canCompleteSignIn()) {
    json_response([
        'ok' => false,
        'error' => 'not_configured',
        'message' => 'Sign-in is not configured on this server yet.',
    ], 503);
}

// Read the request first. clerk-auth.js posts JSON, and PHP only populates
// $_POST for form encodings, so a JSON body has to be decoded explicitly.
// Form-encoded bodies are still accepted for non-JavaScript clients.
$body = file_get_contents('php://input');

if (!is_string($body) || $body === '') {
    json_response([
        'ok' => false,
        'error' => 'malformed_request',
        'message' => 'The sign-in request was empty.',
    ], 400);
}

if (strlen($body) > 16384) {
    json_response([
        'ok' => false,
        'error' => 'request_too_large',
        'message' => 'The sign-in request was too large.',
    ], 413);
}

$payload = json_decode($body, true);

if (!is_array($payload)) {
    // Tolerate a form-encoded body, but never a malformed JSON one.
    if ($_POST !== []) {
        $payload = $_POST;
    } else {
        json_response([
            'ok' => false,
            'error' => 'malformed_request',
            'message' => 'The sign-in request could not be read.',
        ], 400);
    }
}

// The channel proves the request originates from a login page we rendered.
$channel = isset($payload['channel']) && is_string($payload['channel']) ? $payload['channel'] : null;
$state = isset($payload['state']) && is_string($payload['state']) ? $payload['state'] : null;

$channelCheck = ClerkHandoff::validateChannel($channel, $state);

if (!$channelCheck['ok']) {
    json_response([
        'ok' => false,
        'error' => $channelCheck['reason'],
        'message' => 'Please reload the page and try signing in again.',
    ], 403);
}

if (rate_limit_hit('clerk-bridge:' . request_ip(), 20, 300) === false) {
    json_response([
        'ok' => false,
        'error' => 'rate_limited',
        'message' => 'Too many sign-in attempts. Please wait a few minutes and try again.',
    ], 429);
}

$handoffJwt = $payload['handoffToken'] ?? ($payload['token'] ?? null);

if (!is_string($handoffJwt) || trim($handoffJwt) === '') {
    json_response([
        'ok' => false,
        'error' => 'token_missing',
        'message' => 'No sign-in token was presented.',
    ], 400);
}

$result = ClerkHandoff::completeFromBrowserToken($handoffJwt, $origin);

if ($result['ok'] !== true) {
    // 5xx means "our side or Clerk's side is temporarily unable", so a browser
    // or monitoring system can retry. `jwk_unavailable` belongs here: the token
    // may be perfectly valid and the signing keys simply could not be fetched.
    $status = match ($result['reason']) {
        'not_configured' => 503,
        'clerk_unavailable' => 503,
        ClerkTokenVerifier::REASON_KEY_UNAVAILABLE => 503,
        'account_inactive' => 403,
        'origin_rejected' => 403,
        'channel_mismatch', 'no_channel', 'channel_expired', 'too_many_attempts' => 403,
        default => 401,
    };

    json_response([
        'ok' => false,
        'error' => $result['reason'],
        'message' => $result['message'],
    ], $status);
}

ClerkHandoff::closeChannel();
rate_limit_clear('clerk-bridge:' . request_ip());

json_response([
    'ok' => true,
    'token' => $result['token'],
    'redirect' => $result['redirect'],
]);
