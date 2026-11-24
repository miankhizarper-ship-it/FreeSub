<?php

declare(strict_types=1);

/**
 * Clerk (svix) webhook signature verification.
 *
 * Clerk sends `svix-id`, `svix-timestamp` and `svix-signature` headers. The
 * signature is base64(HMAC-SHA256("{id}.{timestamp}.{body}", base64_decode(secret
 * without the "whsec_" prefix))), and the header may carry several space
 * separated `v1,<signature>` entries during key rotation.
 *
 * This mirrors the official SDK's Webhook helper, implemented natively so the
 * receiver does not need vendor/ to be present.
 *
 * @see https://docs.svix.com/receiving/verifying-payloads/how-manual
 */
final class ClerkWebhook
{
    /** Clerk's own replay tolerance. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * Verify a delivery.
     *
     * Returns null when authentic, or a short machine-readable reason when not
     * (never the secret, the signature or the body).
     */
    public static function verify(
        string $body,
        ?string $id,
        ?string $timestamp,
        ?string $signatureHeader,
        string $secret,
        ?int $now = null
    ): ?string {
        if ($secret === '') {
            return 'not_configured';
        }

        if ($id === null || $id === '' || !preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $id)) {
            return 'missing_headers';
        }

        if ($timestamp === null || !preg_match('/^\d{10}$/', $timestamp)) {
            return 'bad_timestamp';
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return 'stale_timestamp';
        }

        if ($signatureHeader === null || trim($signatureHeader) === '') {
            return 'missing_headers';
        }

        $key = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($key, true);

        if ($decoded === false || $decoded === '') {
            return 'bad_secret';
        }

        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $decoded, true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $entry) {
            $parts = explode(',', $entry, 2);

            if (count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }

            if (hash_equals($expected, $parts[1])) {
                return null;
            }
        }

        return 'signature_mismatch';
    }

    /**
     * Build a signature header for a payload. Used by the test suite and by
     * operators replaying a delivery locally; never exposed over HTTP.
     */
    public static function sign(string $body, string $id, string $timestamp, string $secret): string
    {
        $key = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($key, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('The webhook secret is not valid base64 after the whsec_ prefix.');
        }

        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $decoded, true));
    }

    /**
     * Generate a secret in the format Clerk shows in the dashboard.
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . base64_encode(random_bytes(24));
    }
}
