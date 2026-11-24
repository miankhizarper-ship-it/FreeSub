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
     * Strict variant used by the Task 04 engine.
     *
     * normaliseLabel() above is a *display* helper: it rewrites hostile input into
     * something plausible. That is fine for prefilling a form, and wrong for
     * reserving a name — so the reservation path uses SubdomainLabel, which
     * refuses instead of rewriting. Both are kept because existing pages prefill
     * from the lenient one.
     */
    public static function strictLabel(string $input, string $rootDomain = ''): array
    {
        return SubdomainLabel::validate($input, $rootDomain);
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
        // Task 04: one engine answers "is this free?", so the landing page, the
        // API and the reservation path can never disagree about a name.
        $engine = new AvailabilityEngine();
        $result = $engine->check($rawLabel, $domain);

        return [
            'ok' => $result['ok'] !== false,
            'label' => $result['label'],
            'fqdn' => $result['fqdn'],
            'domain' => $result['root_domain'],
            'available' => $result['available'],
            'status' => $result['status'],
            'message' => $result['message'],
            'suggestions' => $result['suggestions'],
            // Additive: the authoritative/cached flags let the UI be honest about
            // whether DNS was actually consulted.
            'authoritative' => $result['authoritative'],
            'cached' => $result['cached'],
            'source' => $result['source'],
        ];
    }

    /**
     * Former implementation, kept for reference only; no caller uses it.
     *
     * @return array<string, mixed>
     */
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
        // Task 04: the reservation service owns validation, quota, the live-label
        // unique constraint and auditing. This wrapper keeps the old signature
        // working for existing callers.
        $domain = Domain::find($domainId);

        if ($domain === null) {
            return ['ok' => false, 'message' => 'That extension is not available.'];
        }

        return ReservationService::create($user, $rawLabel, $domain, $purpose);
    }

    /**
     * Former implementation, kept for reference only; no caller uses it.
     *
     * @return array<string, mixed>
     */
}
