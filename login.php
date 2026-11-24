<?php

declare(strict_types=1);

/**
 * Sign in — delegated to Clerk.
 *
 * This page no longer accepts credentials. It renders Clerk's own component
 * (email + password, Google OAuth, MFA, password reset) and then finishes the
 * sign-in through the server-side handoff. See includes/services/Clerk/.
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireGuest();

$errors = errors();
$clerkReady = ClerkConfig::isConfigured();
$secretReady = ClerkConfig::secretKeyConfigured();
$channel = ['channel' => '', 'state' => ''];

if ($clerkReady) {
    // Only open a channel when the browser SDK can actually use it.
    $channel = ClerkHandoff::openChannel();
}

$notice = null;

if (input('error') === 'not_configured') {
    $notice = 'Sign-in is temporarily unavailable. Please try again later or contact support.';
}

$pageTitle = 'Sign in';
$metaDescription = 'Sign in to your FreeSub account to manage your subdomains and reservations.';

view('auth/login', [
    'errors' => $errors,
    'pageTitle' => $pageTitle,
    'metaDescription' => $metaDescription,
    'clerkReady' => $clerkReady,
    'secretReady' => $secretReady,
    'channel' => (string) ($channel['channel'] ?? ''),
    'state' => (string) ($channel['state'] ?? ''),
    'clerkPublishableKey' => (string) ClerkConfig::publishableKey(),
    'notice' => $notice,
    'loadClerk' => true,
], 'layouts/public');
