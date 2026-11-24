<?php

declare(strict_types=1);

/**
 * Record rules for the DNS engine.
 *
 * Everything here is pure: no database, no network, no globals beyond config.
 * That keeps the rules testable in isolation and means the same checks run in
 * the admin UI, the service layer and the provider.
 *
 * The engine may only ever write A, AAAA and CNAME records, only inside the
 * configured root domain, never proxied and never for a reserved label.
 */
final class DnsRecordValidator
{
    /** Cloudflare-supported TTLs; 1 means "automatic". */
    public const TTL_VALUES = [1, 60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 14400, 18000, 21600, 43200, 86400];

    /**
     * Ranges that must never appear as a record target: RFC1918, loopback,
     * link-local (which is also the cloud metadata address), CGNAT, multicast,
     * reserved/benchmark space, and the IPv6 equivalents.
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],       // "this network"
        ['10.0.0.0', 8],      // RFC1918
        ['100.64.0.0', 10],   // CGNAT
        ['127.0.0.0', 8],     // loopback
        ['169.254.0.0', 16],  // link-local + 169.254.169.254 metadata
        ['172.16.0.0', 12],   // RFC1918
        ['192.0.0.0', 24],    // IETF protocol assignments
        ['192.0.2.0', 24],    // TEST-NET-1
        ['192.88.99.0', 24],  // 6to4 relay anycast
        ['192.168.0.0', 16],  // RFC1918
        ['198.18.0.0', 15],   // benchmarking
        ['198.51.100.0', 24], // TEST-NET-2
        ['203.0.113.0', 24],  // TEST-NET-3
        ['224.0.0.0', 4],     // multicast
        ['240.0.0.0', 4],     // reserved + broadcast
    ];

    /** Minimum legal TTL one year; anything beyond is nonsense. */
    private const MAX_TTL = 86400;

    /**
     * Validate a create/update request.
     *
     * @param array{type?: mixed, name?: mixed, content?: mixed, ttl?: mixed} $input
     * @param string $rootDomain Configured apex, e.g. kharcha.info
     * @return array{ok: bool, errors: array<string, string>, type: string, name: string, content: string, ttl: int}
     */
    public static function validate(array $input, string $rootDomain): array
    {
        $errors = [];

        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        $content = trim((string) ($input['content'] ?? ''));
        $ttl = $input['ttl'] ?? 1;

        $allowedTypes = array_map('strtoupper', (array) config('dns.allowed_record_types', ['A', 'AAAA', 'CNAME']));

        if ($type === '') {
            $errors['type'] = 'Choose a record type.';
        } elseif (!in_array($type, $allowedTypes, true)) {
            // Explicitly names the block: NS/MX/TXT/CAA are never user-editable.
            $errors['type'] = 'Only A, AAAA and CNAME records can be created here.';
        }

        $nameCheck = self::validateName($name, $rootDomain);

        if ($nameCheck !== null) {
            $errors['name'] = $nameCheck;
        }

        if ($content === '') {
            $errors['content'] = 'Enter the record value.';
        } elseif ($type === 'A') {
            $ipError = self::validateIpv4($content);

            if ($ipError !== null) {
                $errors['content'] = $ipError;
            }
        } elseif ($type === 'AAAA') {
            $ipError = self::validateIpv6($content);

            if ($ipError !== null) {
                $errors['content'] = $ipError;
            }
        } elseif ($type === 'CNAME') {
            $cnameError = self::validateCnameTarget($content, $rootDomain);

            if ($cnameError !== null) {
                $errors['content'] = $cnameError;
            }
        }

        if (!is_numeric($ttl)) {
            $errors['ttl'] = 'Choose a valid TTL.';
            $ttl = 1;
        } else {
            $ttl = (int) $ttl;

            if ($ttl <= 0 || $ttl > self::MAX_TTL) {
                $errors['ttl'] = 'TTL must be between 60 and 86400 seconds.';
            } elseif (!in_array($ttl, self::TTL_VALUES, true)) {
                $errors['ttl'] = 'Choose one of the supported TTL values.';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'type' => $type,
            'name' => $name,
            'content' => $type === 'CNAME' ? strtolower(rtrim($content, '.')) : $content,
            'ttl' => (int) $ttl,
        ];
    }

    /**
     * Absolute, in-zone, non-reserved record names only.
     *
     * @return string|null Error message, or null when acceptable
     */
    public static function validateName(string $name, string $rootDomain): ?string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $root = strtolower(rtrim(trim($rootDomain), '.'));

        if ($name === '') {
            return 'Enter the hostname for this record.';
        }

        if ($root === '') {
            return 'No root domain is configured for DNS.';
        }

        if (strlen($name) > 190) {
            return 'That hostname is too long.';
        }

        if (preg_match('/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name) !== 1) {
            return 'Enter a valid fully qualified hostname, for example app.' . $root . '.';
        }

        // The apex itself is not a subdomain record.
        if ($name === $root) {
            return 'The apex domain itself cannot be registered as a subdomain.';
        }

        if (!str_ends_with($name, '.' . $root)) {
            // Rejects both foreign zones (evil.com) and suffix tricks
            // (kharcha.info.evil.com).
            return 'That hostname is outside ' . $root . '.'; 
        }

        $label = substr($name, 0, -1 * (strlen($root) + 1));
        $labels = explode('.', $label);

        if (count($labels) > 3) {
            return 'Use at most three labels in front of ' . $root . '.';
        }

        foreach ($labels as $part) {
            if ($part === '') {
                return 'That hostname contains an empty label.';
            }

            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $part) !== 1) {
                return 'Each label may only contain lowercase letters, numbers and hyphens.';
            }
        }

        // The left-most label is the one a user reserves, so it is subject to
        // the same reserved list and numeric rule as the public search.
        $reserved = array_map('strtolower', (array) config('subdomains.reserved_labels', []));

        if (in_array($labels[0], $reserved, true)) {
            return 'That name is reserved and cannot be managed here.';
        }

        if (preg_match('/^\d+$/', $labels[0]) === 1) {
            return 'All-numeric hostnames are not available.';
        }

        // Wildcards are never created by this engine, by anyone.
        if (str_contains($name, '*')) {
            return 'Wildcard records are not supported.';
        }

        return null;
    }

    /** Public IPv4 only. */
    public static function validateIpv4(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return 'Enter a valid IPv4 address, for example 93.184.216.34.';
        }

        return self::isBlockedIp($value)
            ? 'Private, loopback, link-local, multicast and reserved addresses cannot be used.'
            : null;
    }

    /** Public IPv6 only. */
    public static function validateIpv6(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return 'Enter a valid IPv6 address, for example 2001:db8::1.';
        }

        return self::isBlockedIp($value)
            ? 'Private, loopback, link-local, multicast and reserved addresses cannot be used.'
            : null;
    }

    /** A CNAME target is a hostname — never an IP, never a URL, never the apex. */
    public static function validateCnameTarget(string $value, string $rootDomain): ?string
    {
        $target = strtolower(rtrim(trim($value), '.'));

        if ($target === '') {
            return 'Enter the hostname this CNAME points at.';
        }

        if (str_contains($target, '://') || str_contains($target, '/') || str_contains($target, ':')) {
            return 'A CNAME must point at a hostname, not a URL, port or IP address.';
        }

        if (filter_var($target, FILTER_VALIDATE_IP) !== false) {
            return 'A CNAME cannot point at an IP address. Use an A or AAAA record instead.';
        }

        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $target) !== 1) {
            return 'Enter a valid target hostname, for example app.example.com.';
        }

        if (str_contains($target, 'localhost') || str_ends_with($target, '.local') || str_ends_with($target, '.internal')) {
            return 'That target is a local address and cannot be used.';
        }

        if ($target === strtolower(rtrim(trim($rootDomain), '.'))) {
            return 'A CNAME cannot point at the apex of the same zone; that would create a loop.';
        }

        return null;
    }

    /** True when the address is not publicly routable. */
    public static function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            foreach (self::BLOCKED_V4 as [$network, $bits]) {
                if (self::inCidr($packed, (string) inet_pton($network), $bits)) {
                    return true;
                }
            }

            return false;
        }

        if (strlen($packed) === 16) {
            $blockedV6 = [
                ['::', 128],          // unspecified
                ['::1', 128],         // loopback
                ['::ffff:0:0', 96],   // IPv4-mapped: re-check the v4 part below
                ['64:ff9b::', 96],    // NAT64
                ['100::', 64],        // discard-only
                ['2001:db8::', 32],   // documentation
                ['2001:10::', 28],    // ORCHID
                ['2002::', 16],       // 6to4 (embeds arbitrary v4)
                ['fc00::', 7],        // unique local
                ['fe80::', 10],       // link-local
                ['ff00::', 8],        // multicast
            ];

            foreach ($blockedV6 as [$network, $bits]) {
                if (self::inCidr($packed, (string) inet_pton($network), $bits)) {
                    // An IPv4-mapped address is only blocked when the embedded
                    // IPv4 is itself blocked; otherwise it is a plain public v4.
                    if ($network === '::ffff:0:0') {
                        return self::isBlockedIp((string) inet_ntop(substr($packed, 12)));
                    }

                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private static function inCidr(string $packedIp, string $packedNetwork, int $bits): bool
    {
        if (strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }

    /**
     * Relative label a user can type, e.g. "app" or "app.kharcha.info" → fqdn.
     */
    public static function toFqdn(string $name, string $rootDomain): string
    {
        $name = strtolower(trim($name));
        $root = strtolower(rtrim(trim($rootDomain), '.'));

        if ($name === '') {
            return '';
        }

        if (str_ends_with($name, '.' . $root) || $name === $root) {
            return $name;
        }

        // A trailing dot is meaningless once the value is normalised.
        return rtrim($name, '.') . '.' . $root;
    }
}
