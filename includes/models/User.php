<?php

declare(strict_types=1);

/**
 * User repository.
 *
 * Identity model after the Clerk migration:
 *
 *   users.id            internal primary key, never changes (FKs depend on it)
 *   users.clerk_user_id the verified Clerk `sub`, unique, the identity key
 *   users.email         kept in sync from Clerk when Clerk says it is verified
 *   users.role          owned by this application ONLY. Clerk metadata, JWT
 *                       claims and anything the browser sends are ignored.
 *
 * Nothing in this class creates an administrator.
 */
final class User
{
    /**
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email))]
        );
    }

    /**
     * Identity lookup. This is the only lookup an authenticated request uses.
     *
     * @return array<string, mixed>|null
     */
    public static function findByClerkId(string $clerkUserId): ?array
    {
        if (!self::isClerkUserId($clerkUserId)) {
            return null;
        }

        return Database::selectOne(
            'SELECT * FROM users WHERE clerk_user_id = :clerk_id LIMIT 1',
            ['clerk_id' => $clerkUserId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public static function emailExists(string $email): bool
    {
        return Database::scalar(
            'SELECT COUNT(*) FROM users WHERE email = :email',
            ['email' => mb_strtolower(trim($email))]
        ) > 0;
    }

    public static function isClerkUserId(string $value): bool
    {
        return preg_match('/^user_[A-Za-z0-9_]{1,180}$/', $value) === 1;
    }

    /* ---------------------------------------------------------------------
     * Clerk synchronisation
     * ------------------------------------------------------------------ */

    /**
     * Resolve the local account for a verified Clerk identity, creating or
     * updating it as needed.
     *
     * Order of resolution — deliberately conservative:
     *
     *   1. users.clerk_user_id = <sub>            → reuse (the normal path)
     *   2. verified Clerk email matches an account
     *      that has no clerk_user_id yet           → link it, keeping the id,
     *                                                role and all history
     *   3. otherwise                               → create role = 'user'
     *
     * A Clerk email that is NOT verified is never used to attach an identity
     * to somebody else's existing account.
     *
     * @param array<string, mixed> $clerkUser Raw Clerk user object
     * @param bool $force CLI only: allow attaching an administrator row. Never
     *                    pass true from a request handler.
     * @return array{user: array<string, mixed>, created: bool, linked: bool}
     *
     * @throws ClerkSyncException when the identity cannot be resolved safely
     */
    public static function syncFromClerk(array $clerkUser, bool $force = false): array
    {
        $clerkUserId = (string) ($clerkUser['id'] ?? '');

        if (!self::isClerkUserId($clerkUserId)) {
            throw new ClerkSyncException('Clerk returned an unusable user id.');
        }

        if (($clerkUser['banned'] ?? false) === true) {
            throw new ClerkSyncException('This account has been blocked.', 403);
        }

        $email = self::verifiedEmail($clerkUser);
        $name = self::displayName($clerkUser, $email);
        $username = self::nullableString($clerkUser['username'] ?? null, 64);
        $avatar = self::nullableString($clerkUser['image_url'] ?? ($clerkUser['profile_image_url'] ?? null), 500);

        // 1. Already linked.
        $existing = self::findByClerkId($clerkUserId);

        if ($existing !== null) {
            return [
                'user' => self::refreshFromClerk($existing, $name, $email, $username, $avatar),
                'created' => false,
                'linked' => false,
            ];
        }

        // 2. Link an existing local account by *verified* email.
        if ($email !== null) {
            $candidate = self::findByEmail($email);

            if ($candidate !== null) {
                if (!empty($candidate['clerk_user_id']) && $candidate['clerk_user_id'] !== $clerkUserId) {
                    // Two Clerk identities claim the same address. Refuse rather
                    // than merge: an administrator has to resolve this.
                    throw new ClerkSyncException(
                        'This email address is already linked to a different sign-in account. Please contact support.',
                        409
                    );
                }

                // A self-service sign-in must never inherit an administrator
                // account from an email claim. Verifying control of an address
                // is not proof of being the person who ran the platform: an
                // admin's address can be recycled, re-registered after a lapse,
                // or added to a second Clerk user by mistake. Admin rows are
                // linked deliberately, from the CLI, by someone with shell
                // access to this installation.
                if ((string) ($candidate['role'] ?? 'user') === 'admin' && empty($force)) {
                    throw new ClerkSyncException(
                        'This account is managed offline. An administrator must link it from the server '
                        . '(php database/link-clerk.php).',
                        409
                    );
                }

                $linked = self::linkClerkIdentity($candidate, $clerkUserId, $name, $email, $username, $avatar);

                if ($linked !== null) {
                    return ['user' => $linked, 'created' => false, 'linked' => true];
                }
            }
        }

        // 3. Provision a brand-new, least-privileged account.
        return [
            'user' => self::createFromClerk($clerkUserId, $name, $email, $username, $avatar),
            'created' => true,
            'linked' => false,
        ];
    }

    /**
     * The email Clerk itself marks as verified and primary, or null.
     *
     * @param array<string, mixed> $clerkUser
     */
    public static function verifiedEmail(array $clerkUser): ?string
    {
        $addresses = $clerkUser['email_addresses'] ?? null;

        if (!is_array($addresses) || $addresses === []) {
            return null;
        }

        $primaryId = $clerkUser['primary_email_address_id'] ?? null;
        $fallback = null;

        foreach ($addresses as $address) {
            if (!is_array($address)) {
                continue;
            }

            if (!self::addressIsVerified($address)) {
                continue;
            }

            $email = strtolower(trim((string) ($address['email_address'] ?? '')));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            if ($primaryId !== null && ($address['id'] ?? null) === $primaryId) {
                return $email;
            }

            $fallback ??= $email;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $address
     */
    private static function addressIsVerified(array $address): bool
    {
        $verification = $address['verification'] ?? null;

        if (!is_array($verification)) {
            return false;
        }

        // Clerk reports OTP/OAuth e-mail verification as status "verified";
        // links verified by an administrator are strategy "admin".
        return (string) ($verification['status'] ?? '') === 'verified'
            && isset($verification['verified_at']);
    }

    /**
     * Apply a webhook's `user.updated` payload to an already linked account.
     *
     * Unlike syncFromClerk() this never creates or links anything: a webhook is
     * untrusted data about an account we already know, so the worst it can do
     * is update the display fields.
     *
     * @param array<string, mixed> $clerkUser
     * @return array<string, mixed>|null
     */
    public static function refreshFromClerkPayload(array $clerkUser): ?array
    {
        $clerkUserId = (string) ($clerkUser['id'] ?? '');

        if (!self::isClerkUserId($clerkUserId)) {
            return null;
        }

        $existing = self::findByClerkId($clerkUserId);

        if ($existing === null) {
            return null;
        }

        $email = self::verifiedEmail($clerkUser);
        $name = self::displayName($clerkUser, $email ?? (string) $existing['email']);
        $username = self::nullableString($clerkUser['username'] ?? null, 64);
        $avatar = self::nullableString($clerkUser['image_url'] ?? ($clerkUser['profile_image_url'] ?? null), 500);

        return self::refreshFromClerk($existing, $name, $email, $username, $avatar);
    }

    /**
     * Detach a Clerk identity from its local account (webhook `user.deleted`).
     *
     * The account, its reservations, subdomains and audit trail are preserved;
     * only the link is cleared, so the same person can register again or an
     * operator can re-link the row.
     */
    public static function unlinkClerkIdentity(string $clerkUserId): bool
    {
        if (!self::isClerkUserId($clerkUserId)) {
            return false;
        }

        $existing = self::findByClerkId($clerkUserId);

        if ($existing === null) {
            return false;
        }

        Database::execute(
            "UPDATE users
                SET clerk_user_id = NULL, auth_provider = 'clerk_unlinked', updated_at = :now
              WHERE id = :id AND clerk_user_id = :clerk",
            ['now' => now(), 'id' => (int) $existing['id'], 'clerk' => $clerkUserId]
        );

        audit_log('user.clerk_unlinked', (int) $existing['id'], [
            'clerk_user_id' => $clerkUserId,
            'reason' => 'clerk_user_deleted',
        ], 'system');

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function refreshFromClerk(
        array $user,
        string $name,
        ?string $email,
        ?string $username,
        ?string $avatar
    ): array {
        $updates = [
            'name' => $name,
            'username' => $username,
            'avatar_url' => $avatar,
            'last_login_at' => now(),
            'last_login_ip' => request_ip(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => now(),
        ];

        // Email only moves when Clerk vouches for the new address, and only if
        // no other account already owns it (the unique index would reject it).
        if ($email !== null && strtolower((string) $user['email']) !== $email) {
            $clash = self::findByEmail($email);

            if ($clash === null || (int) $clash['id'] === (int) $user['id']) {
                $updates['email'] = $email;
                $updates['email_verified_at'] ??= now();
            }
        }

        if (empty($user['email_verified_at']) && $email !== null) {
            $updates['email_verified_at'] = now();
        }

        Database::execute(
            'UPDATE users SET ' . implode(', ', array_map(
                static fn (string $column): string => $column . ' = :' . $column,
                array_keys($updates)
            )) . ' WHERE id = :id',
            $updates + ['id' => (int) $user['id']]
        );

        return self::find((int) $user['id']) ?? $user;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private static function linkClerkIdentity(
        array $user,
        string $clerkUserId,
        string $name,
        ?string $email,
        ?string $username,
        ?string $avatar
    ): ?array {
        try {
            Database::execute(
                'UPDATE users
                    SET clerk_user_id = :clerk_user_id,
                        name = :name,
                        username = :username,
                        avatar_url = :avatar_url,
                        auth_provider = :auth_provider,
                        email_verified_at = COALESCE(email_verified_at, :verified_at),
                        last_login_at = :last_login_at,
                        last_login_ip = :last_login_ip,
                        updated_at = :updated_at
                  WHERE id = :id
                    AND (clerk_user_id IS NULL OR clerk_user_id = :clerk_user_id_check)',
                [
                    'clerk_user_id' => $clerkUserId,
                    'clerk_user_id_check' => $clerkUserId,
                    'name' => $name,
                    'username' => $username,
                    'avatar_url' => $avatar,
                    'auth_provider' => 'clerk',
                    'verified_at' => now(),
                    'last_login_at' => now(),
                    'last_login_ip' => request_ip(),
                    'updated_at' => now(),
                    'id' => (int) $user['id'],
                ]
            );
        } catch (PDOException $exception) {
            if (Database::errorCode($exception) === 'duplicate') {
                // A concurrent request linked this identity first; use that row.
                return self::findByClerkId($clerkUserId);
            }

            throw $exception;
        }

        return self::find((int) $user['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function createFromClerk(
        string $clerkUserId,
        string $name,
        ?string $email,
        ?string $username,
        ?string $avatar
    ): array {
        if ($email === null) {
            throw new ClerkSyncException(
                'Your account has no verified email address yet. Please verify it in order to continue.',
                422
            );
        }

        try {
            Database::execute(
                'INSERT INTO users
                    (name, email, clerk_user_id, username, avatar_url, auth_provider,
                     role, status, email_verified_at, last_login_at, last_login_ip,
                     created_at, updated_at)
                 VALUES
                    (:name, :email, :clerk_user_id, :username, :avatar_url, :auth_provider,
                     :role, :status, :verified_at, :last_login_at, :last_login_ip,
                     :created_at, :updated_at)',
                [
                    'name' => $name,
                    'email' => $email,
                    'clerk_user_id' => $clerkUserId,
                    'username' => $username,
                    'avatar_url' => $avatar,
                    'auth_provider' => 'clerk',
                    // Hard-coded: never taken from input, claims or metadata.
                    'role' => 'user',
                    'status' => 'active',
                    'verified_at' => now(),
                    'last_login_at' => now(),
                    'last_login_ip' => request_ip(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (PDOException $exception) {
            if (Database::errorCode($exception) === 'duplicate') {
                // Race: another request created the same identity or the email
                // is now taken. Re-resolve instead of duplicating a record.
                $raced = self::findByClerkId($clerkUserId);

                if ($raced !== null) {
                    return $raced;
                }

                $byEmail = self::findByEmail($email);

                if ($byEmail !== null && empty($byEmail['clerk_user_id'])) {
                    $linked = self::linkClerkIdentity($byEmail, $clerkUserId, $name, $email, $username, $avatar);

                    if ($linked !== null) {
                        return $linked;
                    }
                }

                throw new ClerkSyncException(
                    'This email address is already in use by another account. Please contact support.',
                    409
                );
            }

            throw $exception;
        }

        $created = self::findByClerkId($clerkUserId);

        if ($created === null) {
            throw new ClerkSyncException('The account could not be created.');
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $clerkUser
     */
    private static function displayName(array $clerkUser, ?string $email): string
    {
        $first = trim((string) ($clerkUser['first_name'] ?? ''));
        $last = trim((string) ($clerkUser['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);

        if ($full === '') {
            $full = trim((string) ($clerkUser['username'] ?? ''));
        }

        if ($full === '' && $email !== null) {
            $full = strstr($email, '@', true) ?: $email;
        }

        if ($full === '') {
            $full = 'FreeSub member';
        }

        return mb_substr($full, 0, 100);
    }

    private static function nullableString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /* ---------------------------------------------------------------------
     * Local (non-Clerk) helpers
     * ------------------------------------------------------------------ */

    /**
     * Create a local account with a password.
     *
     * Kept only for the offline break-glass command and for the test suite.
     * There is no web route that calls this any more.
     *
     * @throws RuntimeException when the email is already taken.
     */
    public static function create(string $name, string $email, string $password, string $role = 'user'): array
    {
        $email = mb_strtolower(trim($email));

        try {
            Database::execute(
                'INSERT INTO users (name, email, password_hash, auth_provider, role, status, created_at, updated_at)
                 VALUES (:name, :email, :hash, :provider, :role, :status, :created_at, :updated_at)',
                [
                    'name' => trim($name),
                    'email' => $email,
                    'hash' => Auth::hashPassword($password),
                    'provider' => 'break_glass',
                    'role' => $role === 'admin' ? 'admin' : 'user',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (PDOException $exception) {
            if (Database::errorCode($exception) === 'duplicate') {
                throw new RuntimeException('An account with that email address already exists.');
            }

            throw $exception;
        }

        $user = self::find(Database::lastInsertId());

        if ($user === null) {
            throw new RuntimeException('The account could not be created.');
        }

        return $user;
    }

    /**
     * Mark a local account as Clerk-backed without changing its role.
     *
     * Used by `php database/link-clerk.php` when an operator wants to bind an
     * existing administrator to a Clerk user explicitly instead of waiting for
     * the verified-email match on first sign-in.
     *
     * @return array<string, mixed>
     */
    public static function attachClerkIdentity(int $userId, string $clerkUserId): array
    {
        if (!self::isClerkUserId($clerkUserId)) {
            throw new RuntimeException('That is not a valid Clerk user id (expected user_...).');
        }

        $user = self::find($userId);

        if ($user === null) {
            throw new RuntimeException('No such user.');
        }

        $owner = self::findByClerkId($clerkUserId);

        if ($owner !== null && (int) $owner['id'] !== $userId) {
            throw new RuntimeException('That Clerk user is already linked to another account.');
        }

        Database::execute(
            'UPDATE users SET clerk_user_id = :clerk_id, auth_provider = :provider, updated_at = :updated_at
              WHERE id = :id',
            ['clerk_id' => $clerkUserId, 'provider' => 'clerk', 'updated_at' => now(), 'id' => $userId]
        );

        return self::find($userId) ?? $user;
    }

    public static function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM users');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return Database::select(
            'SELECT id, name, email, role, status, created_at, last_login_at
             FROM users ORDER BY id DESC LIMIT ' . $limit
        );
    }
}

/**
 * Raised when a Clerk identity cannot be mapped onto a local account safely.
 * The message is safe to show to the affected user.
 */
class ClerkSyncException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
