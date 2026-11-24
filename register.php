<?php

declare(strict_types=1);

/**
 * Create an account — delegated to Clerk.
 *
 * Clerk collects the address, sends the verification code and applies the
 * password policy configured for the instance. The first successful sign-up
 * provisions the local account with role = 'user' (see User::syncFromClerk).
 */

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireGuest();

$clerkReady = ClerkConfig::isConfigured();
$secretReady = ClerkConfig::secretKeyConfigured();
$channel = ['channel' => '', 'state' => ''];

if ($clerkReady) {
    $channel = ClerkHandoff::openChannel();
}

$pageTitle = 'Create your free account';
$metaDescription = 'Create a free FreeSub account and reserve your subdomain in under a minute. No credit card required.';

view('auth/register', [
    'errors' => errors(),
    'pageTitle' => $pageTitle,
    'metaDescription' => $metaDescription,
    'clerkReady' => $clerkReady,
    'secretReady' => $secretReady,
    'channel' => (string) ($channel['channel'] ?? ''),
    'state' => (string) ($channel['state'] ?? ''),
    'clerkPublishableKey' => (string) ClerkConfig::publishableKey(),
    'notice' => null,
    'loadClerk' => true,
], 'layouts/public');
