<?php

declare(strict_types=1);

/**
 * DNS record repository (desired state only).
 *
 * No method here talks to a DNS provider. A provider driver is responsible for
 * reconciling these rows with the real zone (see includes/services/DnsProvider).
 */
final class DnsRecord
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forSubdomain(int $subdomainId): array
    {
        return Database::select(
            'SELECT * FROM dns_records WHERE subdomain_id = :id ORDER BY id ASC',
            ['id' => $subdomainId]
        );
    }

    public static function create(int $subdomainId, string $type, string $name, string $content, int $ttl = 1, bool $proxied = false, ?int $priority = null): int
    {
        Database::execute(
            'INSERT INTO dns_records (subdomain_id, type, name, content, ttl, priority, proxied, status, created_at, updated_at)
             VALUES (:subdomain_id, :type, :name, :content, :ttl, :priority, :proxied, :status, :created_at, :updated_at)',
            [
                'subdomain_id' => $subdomainId,
                'type' => strtoupper($type),
                'name' => $name,
                'content' => $content,
                'ttl' => $ttl,
                'priority' => $priority,
                'proxied' => $proxied ? 1 : 0,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return Database::lastInsertId();
    }

    public static function countPending(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM dns_records WHERE status = 'pending'");
    }
}
