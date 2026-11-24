<?php

declare(strict_types=1);

/**
 * Subdomain availability checks and reservation logic.
 *
 * Guarantees of this foundation:
 *   - nothing here creates DNS records;
 *   - nothing here reports a hostname as live;
 *   - a label becomes "taken" only through the subdomains table, or by a
 *     pending/approved reservation.
 */
final class SubdomainService
{
    /**
     * Normalise user input into a bare label: "My Site" -> "my-site".
     */
    public static function normaliseLabel(string $input): string
    {
        $label = mb_strtolower(trim($input));

        // Allow users to paste a full hostname: mysite.kharcha.info
        if (str_contains($label, '.')) {
            $label = explode('.', $label)[0];
        }

        $label = preg_replace('/[^a-z0-9-]+/', '-', $label) ?? '';
        $label = preg_replace('/-{2,}/', '-', $label) ?? '';
        $label = trim($label, '-');

        return mb_substr($label, 0, (int) config('subdomains.max_length', 32));
    }

    /**
     * Full availability check used by the landing page search and the API.
     *
     * @param array<string, mixed>|null $domain
     * @return array{
     *   ok: bool, label: string, fqdn: string|null, domain: string|null,
     *   available: bool, status: string, message: string, suggestions: list<string>
     * }
     */
    public static function check(string $rawLabel, ?array $domain = null): array
    {
        $label = self::normaliseLabel($rawLabel);
        $result = [
            'ok' => true,
            'label' => $label,
            'fqdn' => null,
            'domain' => $domain['root_domain'] ?? null,
            'available' => false,
            'status' => 'invalid',
            'message' => '',
            'suggestions' => [],
        ];

        $error = Validator::subdomainLabel($label);

        if ($error !== null) {
            $result['message'] = $error;
            $result['suggestions'] = self::suggestions($label);

            return $result;
        }

        if ($domain === null) {
            $domain = Domain::available()[0] ?? null;
        }

        if ($domain === null) {
            $result['ok'] = false;
            $result['status'] = 'unavailable';
            $result['message'] = 'No root domains are currently open for registration.';

            return $result;
        }

        $fqdn = $label . '.' . $domain['root_domain'];
        $result['fqdn'] = $fqdn;
        $result['domain'] = (string) $domain['root_domain'];

        if (($domain['registration_status'] ?? 'ready') !== 'ready' || !($domain['is_active'] ?? true)) {
            $result['status'] = 'unavailable';
            $result['message'] = $domain['root_domain'] . ' is currently not accepting new subdomains.';

            return $result;
        }

        if (Subdomain::labelTaken((int) $domain['id'], $label)) {
            $result['status'] = 'taken';
            $result['message'] = $fqdn . ' is already taken.';
            $result['suggestions'] = self::suggestions($label);

            return $result;
        }

        if (Reservation::pendingFor((int) $domain['id'], $label) !== null) {
            $result['status'] = 'reserved';
            $result['message'] = $fqdn . ' is reserved while a request is being reviewed.';
            $result['suggestions'] = self::suggestions($label);

            return $result;
        }

        // Provider lookup is advisory only; the null driver never confirms.
        $providerCheck = DnsProvider::current()->isHostnameAvailable($fqdn);

        if (($providerCheck['available'] ?? true) === false) {
            $result['status'] = 'taken';
            $result['message'] = $providerCheck['reason'] ?? ($fqdn . ' is not available.');

            return $result;
        }

        $result['available'] = true;
        $result['status'] = 'available';
        $result['message'] = $fqdn . ' is available.';

        return $result;
    }

    /**
     * Cheap alternatives shown when a label is taken.
     *
     * @return list<string>
     */
    public static function suggestions(string $label, int $count = 4): array
    {
        $base = $label !== '' ? $label : 'mysite';
        $suffixes = ['app', 'hq', 'web', 'site', 'online', 'dev'];
        $suggestions = [];

        foreach ($suffixes as $suffix) {
            $suggestions[] = $base . '-' . $suffix;
        }

        foreach (['get', 'my', 'the'] as $prefix) {
            $suggestions[] = $prefix . $base;
        }

        foreach (['', '1', '2', '7', '24'] as $digit) {
            $suggestions[] = $base . $digit;
        }

        $unique = [];

        foreach ($suggestions as $candidate) {
            $candidate = self::normaliseLabel($candidate);

            if ($candidate === '' || $candidate === $base || in_array($candidate, $unique, true)) {
                continue;
            }

            if (Validator::subdomainLabel($candidate) !== null) {
                continue;
            }

            $unique[] = $candidate;

            if (count($unique) >= $count) {
                break;
            }
        }

        return $unique;
    }

    /**
     * Create a reservation request (never a DNS record).
     *
     * @return array{ok: bool, message: string, reservation_id?: int, fqdn?: string}
     */
    public static function reserve(array $user, string $rawLabel, int $domainId, ?string $purpose = null): array
    {
        $domain = Domain::find($domainId);

        if ($domain === null || !($domain['is_active'] ?? false)) {
            return ['ok' => false, 'message' => 'That extension is not available.'];
        }

        if (($domain['registration_status'] ?? 'ready') !== 'ready') {
            return ['ok' => false, 'message' => 'That extension is not accepting new subdomains right now.'];
        }

        $check = self::check($rawLabel, $domain);

        if (!$check['available']) {
            return ['ok' => false, 'message' => $check['message']];
        }

        $label = $check['label'];
        $fqdn = (string) $check['fqdn'];
        $userId = (int) $user['id'];

        $quota = (int) ($domain['subdomain_quota'] ?? config('subdomains.default_quota', 5));
        $owned = Subdomain::countForUser($userId);

        if ($owned >= $quota) {
            return [
                'ok' => false,
                'message' => sprintf('You have reached your limit of %d subdomains. Delete one first or contact support.', $quota),
            ];
        }

        return Database::transaction(static function () use ($userId, $domainId, $label, $fqdn, $purpose, $check): array {
            // Re-check inside the transaction to reduce the race window.
            if (Subdomain::labelTaken($domainId, $label) || Reservation::pendingFor($domainId, $label) !== null) {
                return ['ok' => false, 'message' => $fqdn . ' was just taken. Please try another name.'];
            }

            $reservationId = Reservation::create($userId, $domainId, $label, $purpose);

            audit_log(
                'subdomain.reserved',
                $userId,
                ['fqdn' => $fqdn, 'label' => $label, 'domain_id' => $domainId],
                'reservation',
                $reservationId,
                'Reserved ' . $fqdn
            );

            return [
                'ok' => true,
                'message' => 'Reservation received for ' . $fqdn . '. DNS provisioning is not automated yet, so our team will follow up by email.',
                'reservation_id' => $reservationId,
                'fqdn' => $fqdn,
                'label' => $check['label'],
            ];
        });
    }
}
