<?php

declare(strict_types=1);

/**
 * Offline administrator access when Clerk cannot be reached.
 *
 * This is the documented escape hatch that replaces the old password login:
 * it is CLI-only, admin-only, disabled unless CLERK_BREAK_GLASS=true, and every
 * use is written to the audit log with the IP that redeemed the link.
 *
 *   php database/break-glass.php --email=admin@example.com
 *       Verify the administrator's local password (if one exists) and print a
 *       one-time sign-in link valid for BREAK_GLASS_TTL seconds (default 300).
 *
 *   php database/break-glass.php --disable
 *       Revoke every pending link and confirm the current setting.
 *
 * There is no web form anywhere that accepts a password. The link itself is
 * deliberately usable from any browser — that is the point of a break-glass
 * path — so its safety rests on four things instead of on the requester's
 * browser: it can only be minted with shell access, it is short lived, it is
 * single use, and it is stored only as a hash.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$arguments = array_slice($argv, 1);

function break_glass_option(array $arguments, string $name): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return null;
}

echo "FreeSub — offline administrator sign-in (break-glass)\n";
echo str_repeat('-', 62) . "\n";

$enabled = (bool) config('clerk.break_glass', false);

if (in_array('--disable', $arguments, true)) {
    echo "CLERK_BREAK_GLASS is currently " . ($enabled ? 'enabled' : 'disabled') . ".\n";
    echo "To disable it, set CLERK_BREAK_GLASS=false in .env — this command cannot\n";
    echo "change the environment for you. Links already issued expire on their own.\n";

    if ($enabled) {
        $used = Database::execute(
            "UPDATE clerk_handoffs SET state = 'consumed', consumed_at = :now
              WHERE state = 'pending' AND expires_at > :now",
            ['now' => now()]
        );

        echo "Revoked {$used} pending link(s).\n";
    }

    exit(0);
}

if (!$enabled) {
    fwrite(STDERR, "Break-glass access is disabled. Set CLERK_BREAK_GLASS=true in .env to allow it.\n");
    exit(1);
}

$email = break_glass_option($arguments, 'email');

if ($email === null) {
    fwrite(STDERR, "Usage: php database/break-glass.php --email=admin@example.com\n");
    exit(1);
}

$user = User::findByEmail($email);

if ($user === null) {
    fwrite(STDERR, "No account with that email.\n");
    exit(1);
}

if ((string) $user['role'] !== 'admin') {
    fwrite(STDERR, "Break-glass access is restricted to administrators.\n");
    exit(1);
}

if (($user['status'] ?? '') !== 'active') {
    fwrite(STDERR, "That account is not active.\n");
    exit(1);
}

// A password may exist from before the Clerk migration. If it does, require it:
// shell access alone is not enough to impersonate an administrator.
if (!empty($user['password_hash'])) {
    $password = getenv('BREAK_GLASS_PASSWORD');

    if ($password === false || $password === '') {
        fwrite(STDOUT, "Local password for {$email}: ");
        $password = trim((string) fgets(STDIN));
    }

    if (!Auth::verifyPassword((string) $password, (string) $user['password_hash'])) {
        audit_log('auth.break_glass_failed', (int) $user['id'], ['reason' => 'bad_password']);

        fwrite(STDERR, "That password is not correct.\n");
        exit(1);
    }
} else {
    echo "Note: this account has no local password, so shell access is the only factor.\n";
}

$ttl = max(60, (int) env_int('BREAK_GLASS_TTL', 300));
$token = bin2hex(random_bytes(32));

// Reuse the handoff table: single use, hashed at rest, same redemption path.
Database::execute(
    'INSERT INTO clerk_handoffs (token_hash, user_id, clerk_user_id, state, attempts, expires_at, ip_address, user_agent, created_at)
     VALUES (:hash, :uid, :clerk, :state, 0, :expires, :ip, :ua, :now)',
    [
        'hash' => hash('sha256', $token),
        'uid' => (int) $user['id'],
        'clerk' => $user['clerk_user_id'] ?? null,
        'state' => 'pending',
        'expires' => gmdate('Y-m-d H:i:s', time() + $ttl),
        'ip' => 'cli',
        'ua' => 'break-glass',
        'now' => now(),
    ]
);

audit_log(
    'auth.break_glass_link_issued',
    (int) $user['id'],
    ['ttl' => $ttl, 'channel' => 'cli'],
    'user',
    (int) $user['id'],
    'Offline sign-in link issued'
);

echo str_repeat('-', 62) . "\n";
echo "One-time link (valid {$ttl}s, single use):\n\n";
echo '  ' . app_url('/auth/handoff.php') . '?token=' . $token . "\n\n";
echo "Alternatively POST it to /auth/handoff.php as handoff_token (with CSRF).\n";
echo "This was logged to audit_logs as auth.break_glass_link_issued.\n";
