<?php

declare(strict_types=1);

/**
 * Handoff redemption: turn a single-use token into a local session.
 *
 * Reached by a normal form POST from login.php / register.php after
 * clerk-bridge.php minted the token. Requirements:
 *
 *   * POST only, and CSRF protected (the token alone is not enough).
 *   * The token is single use and expires in under two minutes.
 *   * The account and its Clerk identity are re-checked at redemption time.
 *
 * On success the browser discards the Clerk session it used for the handoff,
 * so nothing in the page can act as the user afterwards.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$token = (string) input('handoff_token', '');
$fromLink = false;

/*
 * Two ways in:
 *
 *   1. POST (the normal flow): CSRF protected form, token in the body.
 *   2. GET with ?token= (offline break-glass link issued by the CLI).
 *
 * Both are single-use and short lived. The GET form is only honoured when the
 * token really exists, so a stray link cannot be used to probe the endpoint.
 */
if (!is_post()) {
    $candidate = (string) input('token', '');

    if ($candidate === '' || !preg_match('/^[a-f0-9]{64}$/', $candidate)) {
        redirect(Auth::check() ? '/dashboard.php' : '/login.php');
    }

    $token = $candidate;
    $fromLink = true;
} else {
    csrf_check();
}

$welcome = input('welcome') === '1';

if ($token === '') {
    flash('error', 'That sign-in link was incomplete. Please try again.');
    redirect('/login.php');
}

$result = ClerkHandoff::redeem($token);

if ($result['ok'] !== true || !isset($result['user'])) {
    $messages = [
        'expired' => 'That sign-in took too long to complete. Please try again.',
        'already_used' => 'That sign-in was already completed. Please sign in again.',
        'unknown' => 'That sign-in link is not valid. Please try again.',
        'malformed' => 'That sign-in link is not valid. Please try again.',
        'account_inactive' => 'This account is not active. Please contact support.',
        'identity_mismatch' => 'Your sign-in could not be verified. Please try again.',
        'unavailable' => 'Sign-in is temporarily unavailable. Please try again in a moment.',
    ];

    $reason = (string) $result['reason'];

    audit_log('auth.handoff_rejected', null, ['reason' => $reason]);

    flash('error', $messages[$reason] ?? 'Sign-in could not be completed. Please try again.');
    redirect('/login.php');
}

$user = $result['user'];
$clerkUserId = (string) ($user['clerk_user_id'] ?? '');
// Only a ticket the CLI actually issued takes the offline path; a Clerk ticket
// for an administrator without a local identity does not.
$isBreakGlass = ($result['break_glass'] ?? false) === true;

$isNewAccount = empty($user['last_login_at']) || $welcome;

if ($isBreakGlass) {
    // Offline administrator link from `php database/break-glass.php`.
    try {
        Auth::loginBreakGlass($user, $fromLink ? 'link' : 'form', Auth::BREAK_GLASS_PROOF);
    } catch (RuntimeException $exception) {
        // Log the cause; show the visitor a fixed message. The reason (flag
        // disabled, account demoted) is operator information, not theirs.
        error_log('[freesub] break-glass sign-in refused: ' . $exception->getMessage());

        flash('error', 'Offline sign-in is not available right now. Please use Clerk or contact the administrator.');
        redirect('/login.php');
    }

    flash('warning', 'You are signed in through the offline break-glass path. Clerk sign-in is bypassed for this session.');
    redirect('/admin/index.php');
}

if (!User::isClerkUserId($clerkUserId)) {
    flash('error', 'Your sign-in could not be verified. Please try again.');
    redirect('/login.php');
}

Auth::loginWithClerk($user, $clerkUserId, null);

clear_old_input();

flash('success', $isNewAccount
    ? 'Welcome to ' . (string) config('name', 'FreeSub') . '! Your account is ready.'
    : 'Welcome back!');

redirect($isNewAccount ? '/dashboard.php?welcome=1' : Auth::pullIntended('/dashboard.php'));
