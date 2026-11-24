<?php

declare(strict_types=1);

/**
 * Result of a Clerk session-token verification.
 *
 * Immutable value object: either a verified token (with its claims) or a
 * rejection with a machine-readable reason. Nothing in here is ever rendered
 * to a browser or written to a log: claims contain personal data and the raw
 * token is deliberately not retained.
 */
final class ClerkToken
{
    /** @param array<string, mixed> $claims */
    private function __construct(
        public readonly bool $valid,
        public readonly array $claims,
        public readonly ?string $reason,
        public readonly ?string $detail
    ) {
    }

    /** @param array<string, mixed> $claims */
    public static function verified(array $claims): self
    {
        return new self(true, $claims, null, null);
    }

    public static function rejected(string $reason, ?string $detail = null): self
    {
        return new self(false, [], $reason, $detail);
    }

    public function subject(): ?string
    {
        $sub = $this->claims['sub'] ?? null;

        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    public function sessionId(): ?string
    {
        $sid = $this->claims['sid'] ?? null;

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    public function expiresAt(): ?int
    {
        $exp = $this->claims['exp'] ?? null;

        return is_int($exp) ? $exp : (is_numeric($exp) ? (int) $exp : null);
    }

    /**
     * A human sentence suitable for the UI (never includes claims or tokens).
     */
    public function message(): string
    {
        return match ($this->reason) {
            ClerkTokenVerifier::REASON_MISSING => 'Please sign in to continue.',
            ClerkTokenVerifier::REASON_EXPIRED => 'Your session has expired. Please sign in again.',
            ClerkTokenVerifier::REASON_NOT_YET_VALID => 'That session is not active yet. Please try again.',
            ClerkTokenVerifier::REASON_BAD_SIGNATURE => 'Your session could not be verified. Please sign in again.',
            ClerkTokenVerifier::REASON_BAD_ISSUER => 'Your session was issued for a different application.',
            ClerkTokenVerifier::REASON_BAD_AUTHORIZED_PARTY => 'Your session was opened from an untrusted origin. Please sign in again.',
            ClerkTokenVerifier::REASON_BAD_AUDIENCE => 'Your session is not valid for this application.',
            ClerkTokenVerifier::REASON_MISSING_SUBJECT => 'Your session is missing a user identity.',
            ClerkTokenVerifier::REASON_KEY_UNAVAILABLE => 'Sign-in is temporarily unavailable. Please try again in a moment.',
            default => 'Your session is not valid. Please sign in again.',
        };
    }
}
