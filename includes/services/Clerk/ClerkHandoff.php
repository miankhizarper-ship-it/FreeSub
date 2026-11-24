<?php

declare(strict_types=1);

/**
 * One-time handoff between the browser SDK and PHP.
 *
 * Why not just read the Clerk `__session` cookie on every request?
 *
 *   A cookie readable by page JavaScript is not a safe channel for a server to
 *   authenticate on: any XSS in the page (or a same-origin sibling app) can
 *   mint or replay one. Clerk's guidance for server-side apps is the opposite
 *   direction: the browser proves who it is to the server once, over a
 *   channel bound to the server's own session, and the server performs the
 *   authentication itself with its secret key.
 *
 * The protocol implemented here:
 *
 *   1. GET /login.php
 *        PHP opens a protected "channel" in the browser session:
 *        $_SESSION['_clerk_handoff'] = [channel, state, expires_at]
 *
 *   2. POST /clerk-bridge.php  (from the browser SDK, same origin)
 *        A privileged bridge request. Rejected unless it carries our own
 *        channel+state (so the form is rendered by us, not by an attacker)
 *        and the Origin header matches an authorized party.
 *        Inside, the page reads exactly one method from the Clerk session:
 *        clerk.session.getToken({ template: 'freesub_handoff' }) — a
 *        self-signed JWT scoped to our instance.
 *
 *   3. PHP receives that token and signs it with the instance JWKS
 *        (signature + iss + exp + sub). No session cookie is trusted.
 *
 *   4. PHP asks the Clerk Backend API for the authoritative user record and
 *        resolves it to a local account (User::syncFromClerk).
 *
 *   5. PHP mints a random 256-bit handoff token, stores ONLY its SHA-256
 *        digest with a 120 second expiry, and returns it to the page.
 *
 *   6. POST /auth/handoff.php (form post, CSRF protected, token in the body)
 *        PHP consumes the token atomically (single use), re-checks the Clerk
 *        identity, and starts the local session. The browser then discards
 *        the Clerk session it no longer needs.
 *
 * A token is therefore single-use, short lived, bound to the session that
 * asked for it, and useless without the Clerk identity that produced it.
 */
final class ClerkHandoff
{
    public const CHANNEL_KEY = '_clerk_handoff';
    public const MAX_BRIDGE_ATTEMPTS = 5;

    /**
     * Open (or reuse) the handoff channel for this browser session.
     *
     * @return array{channel: string, state: string, expires_at: int}
     */
    public static function openChannel(): array
    {
        $existing = $_SESSION[self::CHANNEL_KEY] ?? null;

        if (is_array($existing) && isset($existing['channel'], $existing['state'], $existing['expires_at'])
            && (int) $existing['expires_at'] > time() + 30) {
            return $existing;
        }

        $ttl = max(60, (int) config('clerk.handoff_ttl', 120));

        $channel = bin2hex(random_bytes(24));
        $state = bin2hex(random_bytes(16));

        $_SESSION[self::CHANNEL_KEY] = [
            'channel' => $channel,
            'state' => $state,
            'expires_at' => time() + $ttl,
            'attempts' => 0,
        ];

        return $_SESSION[self::CHANNEL_KEY];
    }

    /**
     * Validate an incoming bridge request against the open channel.
     *
     * @return array{ok: bool, reason: string}
     */
    public static function validateChannel(?string $channel, ?string $state): array
    {
        $open = $_SESSION[self::CHANNEL_KEY] ?? null;

        if (!is_array($open) || !isset($open['channel'], $open['state'], $open['expires_at'])) {
            return ['ok' => false, 'reason' => 'no_channel'];
        }

        if ((int) $open['expires_at'] < time()) {
            unset($_SESSION[self::CHANNEL_KEY]);

            return ['ok' => false, 'reason' => 'channel_expired'];
        }

        $attempts = (int) ($open['attempts'] ?? 0) + 1;
        $_SESSION[self::CHANNEL_KEY]['attempts'] = $attempts;

        if ($attempts > self::MAX_BRIDGE_ATTEMPTS) {
            unset($_SESSION[self::CHANNEL_KEY]);

            return ['ok' => false, 'reason' => 'too_many_attempts'];
        }

        if (!is_string($channel) || !is_string($state) || $channel === '' || $state === '') {
            return ['ok' => false, 'reason' => 'missing_credentials'];
        }

        if (!hash_equals((string) $open['channel'], $channel) || !hash_equals((string) $open['state'], $state)) {
            return ['ok' => false, 'reason' => 'channel_mismatch'];
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Complete the server-side half of the handshake.
     *
     * @param string      $handoffJwt Token minted by the browser SDK
     * @param string|null $origin     Origin header of the bridge request
     * @return array{ok: bool, reason: string, message: string, token?: string, redirect?: string, user?: array<string, mixed>}
     */
    public static function completeFromBrowserToken(string $handoffJwt, ?string $origin): array
    {
        if (!ClerkConfig::canCompleteSignIn()) {
            return self::failure('not_configured', 'Sign-in is not configured yet.');
        }

        // The bridge is same-origin only. A missing Origin is allowed (some
        // privacy modes strip it for same-origin POSTs), but never a foreign one.
        if ($origin !== null && $origin !== '' && !ClerkConfig::isAuthorizedParty($origin)) {
            audit_log('auth.clerk_origin_rejected', null, ['origin' => mb_substr($origin, 0, 120)]);

            return self::failure('origin_rejected', 'This sign-in was started from an untrusted origin.');
        }

        // 1. Cryptographic verification of the token the browser presented.
        $verified = ClerkTokenVerifier::verify(
            $handoffJwt,
            ClerkConfig::issuer(),
            ClerkConfig::jwksUrl()
        );

        if (!$verified->valid) {
            audit_log('auth.clerk_token_rejected', null, ['reason' => (string) $verified->reason]);

            return self::failure((string) $verified->reason, $verified->message());
        }

        $clerkUserId = $verified->subject();

        if ($clerkUserId === null) {
            return self::failure(ClerkTokenVerifier::REASON_MISSING_SUBJECT, 'Your session is missing a user identity.');
        }

        // 2. Ask Clerk for the authoritative record (never trust the token alone
        //    for account state such as bans, locked accounts or email changes).
        $client = new ClerkApiClient();

        try {
            $clerkUser = $client->getUser($clerkUserId);
        } catch (ClerkApiException $exception) {
            error_log('[freesub] clerk user lookup failed: status=' . $exception->status());

            return self::failure('clerk_unavailable', 'Sign-in is temporarily unavailable. Please try again in a moment.');
        }

        if ($clerkUser === null) {
            return self::failure('clerk_user_missing', 'That Clerk account no longer exists.');
        }

        // 3. Resolve to a local account (create/link, never duplicate, never promote).
        try {
            $sync = User::syncFromClerk($clerkUser);
        } catch (ClerkSyncException $exception) {
            audit_log('auth.clerk_sync_rejected', null, ['status' => $exception->httpStatus()]);

            return self::failure('clerk_sync_rejected', $exception->getMessage());
        }

        $user = $sync['user'];

        if (($user['status'] ?? '') !== 'active') {
            audit_log('auth.login_blocked', (int) $user['id'], ['status' => $user['status']]);

            return self::failure('account_inactive', 'This account is not active. Please contact support.');
        }

        if ($sync['created']) {
            audit_log('auth.registered', (int) $user['id'], ['provider' => 'clerk'], 'user', (int) $user['id'], 'Account created through Clerk');
        } elseif ($sync['linked']) {
            audit_log('auth.account_linked', (int) $user['id'], ['provider' => 'clerk'], 'user', (int) $user['id'], 'Existing account linked to Clerk identity');
        }

        // 4. Mint the single-use handoff token.
        $issued = self::issue($user, $clerkUserId, $verified->sessionId());

        if ($issued === null) {
            return self::failure('token_issue_failed', 'Sign-in could not be completed. Please try again.');
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'message' => $sync['created'] ? 'Account created.' : 'Signed in.',
            'token' => $issued,
            'redirect' => $sync['created'] ? '/auth/handoff.php?welcome=1' : '/auth/handoff.php',
            'user' => $user,
        ];
    }

    /**
     * Store a hashed, single-use token and return the plaintext once.
     */
    private static function issue(array $user, string $clerkUserId, ?string $clerkSessionId): ?string
    {
        $ttl = max(20, min(300, (int) config('clerk.ticket_ttl', 60)));
        $token = bin2hex(random_bytes(32));

        try {
            // Housekeeping: drop anything that expired long ago.
            Database::execute('DELETE FROM clerk_handoffs WHERE expires_at < :cutoff', [
                'cutoff' => gmdate('Y-m-d H:i:s', time() - 3600),
            ]);

            Database::execute(
                'INSERT INTO clerk_handoffs
                    (token_hash, user_id, clerk_user_id, state, attempts, expires_at,
                     ip_address, user_agent, created_at)
                 VALUES
                    (:token_hash, :user_id, :clerk_user_id, :state, 0, :expires_at,
                     :ip, :ua, :created_at)',
                [
                    'token_hash' => hash('sha256', $token),
                    'user_id' => (int) $user['id'],
                    'clerk_user_id' => $clerkUserId,
                    'state' => 'pending',
                    'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
                    'ip' => request_ip(),
                    'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    'created_at' => now(),
                ]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] handoff token could not be issued: ' . $exception->getMessage());

            return null;
        }

        return $token;
    }

    /**
     * Consume a handoff token and return the local user it belongs to.
     *
     * @return array{ok: bool, reason: string, user?: array<string, mixed>}
     */
    public static function redeem(string $token): array
    {
        $token = trim($token);

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $hash = hash('sha256', $token);

        try {
            $row = Database::selectOne(
                'SELECT * FROM clerk_handoffs WHERE token_hash = :hash LIMIT 1',
                ['hash' => $hash]
            );
        } catch (Throwable $exception) {
            error_log('[freesub] handoff lookup failed: ' . $exception->getMessage());

            return ['ok' => false, 'reason' => 'unavailable'];
        }

        if ($row === null) {
            return ['ok' => false, 'reason' => 'unknown'];
        }

        if ((string) $row['state'] !== 'pending') {
            // Replay attempt: the token was already used once.
            audit_log('auth.handoff_replay', (int) $row['user_id'], [], 'user', (int) $row['user_id'], 'Handoff token replay rejected');

            return ['ok' => false, 'reason' => 'already_used'];
        }

        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        // Atomic single-use claim. The WHERE clause makes a concurrent request
        // fail instead of both succeeding.
        $claimed = Database::execute(
            "UPDATE clerk_handoffs
                SET state = 'consumed', consumed_at = :now
              WHERE id = :id AND state = 'pending'",
            ['now' => now(), 'id' => (int) $row['id']]
        );

        if ($claimed !== 1) {
            return ['ok' => false, 'reason' => 'already_used'];
        }

        $user = User::find((int) $row['user_id']);

        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return ['ok' => false, 'reason' => 'account_inactive'];
        }

        $isBreakGlass = (string) $row['ip_address'] === 'cli'
            && (string) $row['user_agent'] === 'break-glass';

        if ($isBreakGlass) {
            // Offline administrator ticket: database/break-glass.php already
            // verified the operator's shell access and local password before
            // issuing it. The only thing re-checked here is that the account is
            // still an active administrator — a demotion takes effect at once,
            // even for a ticket that is still within its TTL.
            if ((string) ($user['role'] ?? '') !== 'admin') {
                return ['ok' => false, 'reason' => 'account_inactive'];
            }

            return ['ok' => true, 'reason' => 'ok', 'user' => $user, 'break_glass' => true];
        }

        if (!is_string($user['clerk_user_id'] ?? null)
            || !hash_equals((string) $row['clerk_user_id'], (string) $user['clerk_user_id'])) {
            return ['ok' => false, 'reason' => 'identity_mismatch'];
        }

        return ['ok' => true, 'reason' => 'ok', 'user' => $user, 'break_glass' => false];
    }

    /**
     * Close the channel once it has been used.
     */
    public static function closeChannel(): void
    {
        unset($_SESSION[self::CHANNEL_KEY]);
    }

    /**
     * @return array{ok: bool, reason: string, message: string}
     */
    private static function failure(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }
}
