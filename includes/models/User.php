<?php

declare(strict_types=1);

/**
 * User repository.
 *
 * All queries are prepared statements. Password hashes never leave this class
 * except for the explicit verification helpers in Auth.
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

    /**
     * Create a user. Returns the created row.
     *
     * @throws RuntimeException when the email is already taken.
     */
    public static function create(string $name, string $email, string $password, string $role = 'user'): array
    {
        $email = mb_strtolower(trim($email));

        try {
            Database::execute(
                'INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
                 VALUES (:name, :email, :hash, :role, :status, :created_at, :updated_at)',
                [
                    'name' => trim($name),
                    'email' => $email,
                    'hash' => Auth::hashPassword($password),
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
