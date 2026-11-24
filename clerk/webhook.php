<?php

declare(strict_types=1);

/**
 * Clerk webhook receiver.
 *
 * Clerk signs every webhook with an svix signature. This endpoint verifies the
 * signature with CLERK_WEBHOOK_SECRET, then applies the event to our own data:
 * email changes, suspensions, and deletions (which unlink the Clerk identity
 * without deleting the account or its history).
 *
 * Rules:
 *   * never trust the payload body — the signature is checked first;
 *   * the secret is never logged, echoed or written to the response;
 *   * replies are terse and constant-shaped, so an attacker cannot use the
 *     endpoint as an oracle;
 *   * the endpoint is idempotent: Clerk retries deliveries.
 *
 * The signature check is implemented natively (HMAC-SHA256 over
 * "{msg_id}.{timestamp}.{body}") so no extra dependency is required; the
 * official SDK ships the same algorithm as Webhook::verify.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * @param array<string, mixed> $payload
 */
function clerk_webhook_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    clerk_webhook_reply(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$secret = (string) config('clerk.webhook_secret', '');

if ($secret === '') {
    // Misconfiguration: fail closed, and tell the operator only via the log.
    error_log('[freesub] Clerk webhook received but CLERK_WEBHOOK_SECRET is not configured.');

    clerk_webhook_reply(['ok' => false, 'error' => 'not_configured'], 503);
}

$rawBody = (string) file_get_contents('php://input');

if ($rawBody === '' || strlen($rawBody) > 262144) {
    clerk_webhook_reply(['ok' => false, 'error' => 'bad_request'], 400);
}

$headers = clerk_webhook_headers();

$verification = ClerkWebhook::verify(
    $rawBody,
    $headers['svix-id'] ?? null,
    $headers['svix-timestamp'] ?? null,
    $headers['svix-signature'] ?? null,
    $secret
);

if ($verification !== null) {
    error_log('[freesub] Clerk webhook rejected: ' . $verification);

    clerk_webhook_reply(['ok' => false, 'error' => 'invalid_signature'], 401);
}

$event = json_decode($rawBody, true);

if (!is_array($event) || !is_string($event['type'] ?? null)) {
    clerk_webhook_reply(['ok' => false, 'error' => 'bad_payload'], 400);
}

$type = (string) $event['type'];
$data = is_array($event['data'] ?? null) ? $event['data'] : [];
$clerkUserId = is_string($data['id'] ?? null) ? (string) $data['id'] : null;

$handled = false;

try {
    switch ($type) {
        case 'user.created':
        case 'user.updated':
            // Clerk is the source of truth for identity, but not for role: the
            // sync only ever writes the Clerk-derived columns.
            if ($clerkUserId !== null) {
                $handled = User::refreshFromClerkPayload($data) !== null;
            }

            break;

        case 'user.deleted':
            // Keep the account, its reservations and its audit trail; just
            // detach the identity so the person cannot sign back in and the
            // email is free again.
            if ($clerkUserId !== null) {
                $handled = User::unlinkClerkIdentity($clerkUserId);
            }

            break;

        case 'session.ended':
        case 'session.removed':
        case 'session.revoked':
            if (is_string($data['id'] ?? null)) {
                $handled = Auth::endClerkSession((string) $data['id']);
            }

            break;

        case 'session.created':
            if (is_string($data['id'] ?? null) && is_string($data['user_id'] ?? null)) {
                $handled = Auth::mirrorClerkSession((string) $data['user_id'], (string) $data['id']);
            }

            break;

        default:
            // Unsubscribed event types are acknowledged so Clerk stops retrying.
            break;
    }
} catch (Throwable $exception) {
    error_log('[freesub] Clerk webhook handling failed for ' . $type . ': ' . $exception->getMessage());

    clerk_webhook_reply(['ok' => false, 'error' => 'processing_failed'], 500);
}

audit_log('clerk.webhook.' . $type, null, [
    'clerk_user_id' => $clerkUserId,
    'handled' => $handled,
], 'system');

clerk_webhook_reply(['ok' => true, 'handled' => $handled]);

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Normalise the headers we care about. Apache, nginx+FPM and the PHP built-in
 * server all expose them differently, so read every spelling.
 *
 * @return array<string, string>
 */
function clerk_webhook_headers(): array
{
    $wanted = ['svix-id', 'svix-timestamp', 'svix-signature'];
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (!is_string($value)) {
            continue;
        }

        $normalised = strtolower(str_replace('_', '-', (string) preg_replace('/^HTTP_/', '', (string) $key)));

        if (in_array($normalised, $wanted, true)) {
            $headers[$normalised] = $value;
        }
    }

    foreach (['HTTP_SVIX_ID' => 'svix-id', 'HTTP_SVIX_TIMESTAMP' => 'svix-timestamp', 'HTTP_SVIX_SIGNATURE' => 'svix-signature'] as $serverKey => $name) {
        if (!isset($headers[$name]) && isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
            $headers[$name] = $_SERVER[$serverKey];
        }
    }

    return $headers;
}
