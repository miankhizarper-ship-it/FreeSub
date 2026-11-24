<?php

declare(strict_types=1);

/**
 * Presentation of canonical backend states (Task 05).
 *
 * The backend owns the vocabulary — `pending_dns`, `active`, `terminated`,
 * `pending`, `approved`, `rejected`, `cancelled`, `expired` and the
 * `provisioning_jobs` states — and this class only decides how each one is
 * *described* to a person. Nothing here invents a state, and nothing here
 * decides whether a state is legal: that stays in the services.
 *
 * Why a class rather than a `match` in each template: the dashboard, the detail
 * page, the reservation history and the admin queue must agree, and the tests
 * assert the map directly instead of scraping HTML for wording.
 */
final class DashboardStatus
{
    /**
     * Canonical subdomain status -> human label, tone, and one-line meaning.
     *
     * `tone` maps to the existing badge classes: is-active, is-pending,
     * is-failed, is-neutral. The label is always rendered as text, so the badge
     * never relies on colour alone.
     *
     * @var array<string, array{label: string, tone: string, hint: string}>
     */
    private const SUBDOMAIN = [
        'pending_dns' => [
            'label' => 'Pending DNS',
            'tone' => 'is-pending',
            'hint' => 'Approved and queued. The record is created and verified by an operator before it goes live.',
        ],
        'active' => [
            'label' => 'Active',
            'tone' => 'is-active',
            'hint' => 'The DNS record exists and has been read back from the zone.',
        ],
        'suspended' => [
            'label' => 'Suspended',
            'tone' => 'is-failed',
            'hint' => 'Temporarily paused. Contact support if this is unexpected.',
        ],
        'failed' => [
            'label' => 'Provisioning failed',
            'tone' => 'is-failed',
            'hint' => 'The record could not be created or verified. Support has the details and can retry.',
        ],
        'terminated' => [
            'label' => 'Released',
            'tone' => 'is-neutral',
            'hint' => 'Released and no longer yours. The name may become available again.',
        ],
    ];

    /**
     * Canonical reservation status -> label, tone, hint.
     *
     * @var array<string, array{label: string, tone: string, hint: string}>
     */
    private const RESERVATION = [
        'pending' => [
            'label' => 'Awaiting review',
            'tone' => 'is-pending',
            'hint' => 'A person reviews this request before any DNS is touched.',
        ],
        'approved' => [
            'label' => 'Approved',
            'tone' => 'is-active',
            'hint' => 'Approved. Provisioning is queued; the name is not live until the record is verified.',
        ],
        'rejected' => [
            'label' => 'Rejected',
            'tone' => 'is-failed',
            'hint' => 'Not approved. The reason is shown with the request.',
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'tone' => 'is-neutral',
            'hint' => 'You cancelled this request; the label was released.',
        ],
        'expired' => [
            'label' => 'Expired',
            'tone' => 'is-neutral',
            'hint' => 'The hold lapsed before review; the label was released.',
        ],
    ];

    /** Provisioning job state -> label/tone (admin-facing wording, same rules). */
    private const JOB = [
        'pending' => ['label' => 'Queued', 'tone' => 'is-pending', 'hint' => 'Waiting to be run.'],
        'running' => ['label' => 'Running', 'tone' => 'is-pending', 'hint' => 'A worker has claimed this job.'],
        'done' => ['label' => 'Verified', 'tone' => 'is-active', 'hint' => 'Record created and read back.'],
        'failed' => ['label' => 'Failed', 'tone' => 'is-failed', 'hint' => 'Stopped after the retry budget or a permanent error.'],
        'cancelled' => ['label' => 'Cancelled', 'tone' => 'is-neutral', 'hint' => 'Cancelled before completion.'],
    ];

    /** Account state -> label/tone. */
    private const ACCOUNT = [
        'active' => ['label' => 'Active', 'tone' => 'is-active'],
        'suspended' => ['label' => 'Suspended', 'tone' => 'is-failed'],
        'pending' => ['label' => 'Pending', 'tone' => 'is-pending'],
    ];

    /**
     * @return array{label: string, tone: string, hint: string}
     */
    public static function subdomain(string $status): array
    {
        return self::SUBDOMAIN[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'tone' => 'is-neutral',
            'hint' => '',
        ];
    }

    /**
     * @return array{label: string, tone: string, hint: string}
     */
    public static function reservation(string $status): array
    {
        return self::RESERVATION[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'tone' => 'is-neutral',
            'hint' => '',
        ];
    }

    /**
     * @return array{label: string, tone: string, hint: string}
     */
    public static function job(string $status): array
    {
        return self::JOB[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'tone' => 'is-neutral',
            'hint' => '',
        ];
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function account(string $status): array
    {
        return self::ACCOUNT[$status] ?? ['label' => ucfirst($status), 'tone' => 'is-neutral'];
    }

    /** Every status the subdomain repository can return. */
    public static function subdomainStates(): array
    {
        return array_keys(self::SUBDOMAIN);
    }

    /** Every status the reservation repository can return. */
    public static function reservationStates(): array
    {
        return array_keys(self::RESERVATION);
    }

    /**
     * Can the owner release this subdomain from the dashboard?
     *
     * Only states that hold no live DNS record. `active` needs the record
     * deleted through the audited DNS path, and `terminated` is already gone, so
     * both are refused here — the rule lives in one place so the view cannot
     * offer a button the controller would reject.
     */
    public static function canReleaseFromDashboard(string $status): bool
    {
        return in_array($status, ['pending_dns', 'failed', 'suspended'], true);
    }

    /**
     * Why the release button is unavailable, for the disabled state's tooltip
     * and for the controller's refusal message. Never a stack trace.
     */
    public static function releaseBlockedReason(string $status): ?string
    {
        return match ($status) {
            'active' => 'Active subdomains are released by support, so the DNS record is removed safely.',
            'terminated' => 'This subdomain has already been released.',
            default => self::canReleaseFromDashboard($status) ? null : 'This subdomain cannot be released in its current state.',
        };
    }

    /**
     * Build the provisioning timeline for one subdomain.
     *
     * Only events that exist in the database are returned: a reservation that is
     * missing, an approval that never happened, or a job that was never created
     * simply produce no step. Timestamps are never synthesised.
     *
     * @param array<string, mixed> $subdomain  Row from Subdomain::findForUser()
     * @param array<string, mixed>|null $reservation Linked reservation, when present
     * @param list<array<string, mixed>> $records DNS records for the subdomain
     * @param array<string, mixed>|null $job     Latest provisioning job, when present
     * @return list<array{key: string, label: string, at: string|null, state: string, detail: string}>
     */
    public static function timeline(
        array $subdomain,
        ?array $reservation,
        array $records,
        ?array $job
    ): array {
        // Each step keeps the raw stored timestamp alongside its display form.
        // Ordering must use the raw value: the display format starts with a
        // month *name*, so sorting on it would order steps alphabetically by
        // month rather than in time.
        $steps = [];

        $add = static function (string $key, string $label, mixed $raw, string $state, string $detail) use (&$steps): void {
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'at' => self::date($raw),
                '_raw' => is_string($raw) && trim($raw) !== '' && self::date($raw) !== null ? $raw : null,
                'state' => $state,
                'detail' => $detail,
            ];
        };

        if ($reservation !== null) {
            $add('reservation', 'Reservation requested', $reservation['created_at'] ?? null, 'done', 'Requested by you');

            if (in_array((string) $reservation['status'], ['approved'], true) && ($reservation['reviewed_at'] ?? null) !== null) {
                $add('approval', 'Approved', $reservation['reviewed_at'], 'done', 'Reviewed by the FreeSub team');
            }
        }

        if ($job !== null) {
            $add('queued', 'DNS provisioning queued', $job['created_at'] ?? null, 'done', 'Waiting in the provisioning queue');

            if ((int) ($job['attempts'] ?? 0) > 0) {
                $add(
                    'attempted',
                    'Provisioning attempted',
                    $job['updated_at'] ?? null,
                    (string) $job['status'] === 'failed' ? 'failed' : 'done',
                    (int) $job['attempts'] . ' attempt' . ((int) $job['attempts'] === 1 ? '' : 's')
                );
            }
        }

        // Anything the zone confirmed for us carries its own sync timestamp.
        $verified = null;

        foreach ($records as $record) {
            // The repository exposes a boolean, never the provider's id.
            if ((int) ($record['has_provider_record'] ?? 0) === 1 && ($record['last_synced_at'] ?? null) !== null) {
                $candidate = (string) $record['last_synced_at'];

                if ($verified === null || $candidate > $verified) {
                    $verified = $candidate;
                }
            }
        }

        if ($verified !== null) {
            $add('verified', 'DNS verified', $verified, 'done', 'Read back from the zone and compared');
        }

        if ((string) $subdomain['status'] === 'active') {
            $add('active', 'Active', $subdomain['provisioned_at'] ?? null, 'done', 'Serving DNS');
        } elseif ((string) $subdomain['status'] === 'failed') {
            $add('failed', 'Provisioning failed', $subdomain['updated_at'] ?? null, 'failed', 'Support can retry this safely');
        }

        // The steps are collected in logical order, which is not chronological:
        // a row's updated_at is when it was last written, so the failure step
        // can precede the last attempt that caused it. A timeline that runs
        // backwards reads as a bug. usort() is not stable and several steps can
        // share a minute, so ties break on the original position.
        $indexed = [];

        foreach ($steps as $position => $step) {
            $indexed[] = [$position, $step];
        }

        usort($indexed, static function (array $a, array $b): int {
            $left = $a[1]['_raw'];
            $right = $b[1]['_raw'];

            // Undated steps cannot be placed between dated ones; they keep
            // their relative order at the end.
            if ($left === null || $right === null) {
                if ($left === $right) {
                    return $a[0] <=> $b[0];
                }

                return $left === null ? 1 : -1;
            }

            $order = strcmp($left, $right);

            return $order !== 0 ? $order : ($a[0] <=> $b[0]);
        });

        return array_map(static function (array $pair): array {
            unset($pair[1]['_raw']);

            return $pair[1];
        }, $indexed);
    }

    /**
     * A user-safe explanation for a provisioning failure.
     *
     * The stored `last_error` is written by the DNS engine for operators (it can
     * name a literal provider response), so it is never rendered to the owner.
     * This is the only text they see.
     */
    public static function failureMessage(): string
    {
        return 'Provisioning did not complete. The record could not be created or verified against the DNS provider. '
            . 'This is not something you can fix from here — contact support and we will retry it safely.';
    }

    /** Format a stored timestamp for display, tolerant of nulls and junk. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $formatted = format_date($value, 'M j, Y H:i');

        return $formatted === '—' ? null : $formatted;
    }
}
