<?php

declare(strict_types=1);

/**
 * Abuse report repository.
 */
final class AbuseReport
{
    public static function create(string $fqdn, string $email, string $category, string $message, ?string $name = null, ?int $subdomainId = null): int
    {
        Database::execute(
            'INSERT INTO abuse_reports
                (subdomain_id, reported_fqdn, reporter_name, reporter_email, category, message, status, request_ip, created_at, updated_at)
             VALUES
                (:subdomain_id, :fqdn, :name, :email, :category, :message, :status, :ip, :created_at, :updated_at)',
            [
                'subdomain_id' => $subdomainId,
                'fqdn' => mb_strtolower(trim($fqdn)),
                'name' => $name !== null ? mb_substr($name, 0, 100) : null,
                'email' => mb_strtolower(trim($email)),
                'category' => $category,
                'message' => $message,
                'status' => 'new',
                'ip' => request_ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return Database::lastInsertId();
    }

    public static function countOpen(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM abuse_reports WHERE status IN ('new', 'reviewing')");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return Database::select(
            'SELECT * FROM abuse_reports ORDER BY id DESC LIMIT ' . $limit
        );
    }
}
