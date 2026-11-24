<?php

declare(strict_types=1);

/**
 * Subdomain label rules (Task 04).
 *
 * One place decides what a label may be, so the public search, the reservation
 * service, the DNS engine and the admin queue can never disagree.
 *
 * Design notes that matter:
 *   * We never "clean up" hostile input and then accept it. The old
 *     normaliseLabel() rewrote "My Site!" into "my-site" and reserved that.
 *     That silently hands out names the user did not ask for and hides typos.
 *     Instead: normalise what normalisation can do *safely* (trim, lowercase,
 *     strip a pasted apex), and reject anything that still contains characters
 *     outside [a-z0-9-].
 *   * Validation is pure and total: same input, same answer, no I/O.
 *   * Every rule is anchored (^...$) — no partial matches, no substring tricks.
 */
final class SubdomainLabel
{
    /** Outcome codes, used by the availability engine and the UI. */
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const TOO_SHORT = 'too_short';
    public const TOO_LONG = 'too_long';
    public const BAD_CHARSET = 'bad_charset';
    public const BAD_EDGE = 'bad_edge';
    public const DOUBLE_HYPHEN = 'double_hyphen';
    public const PUNYCODE = 'punycode';
    public const NON_ASCII = 'non_ascii';
    public const WILDCARD = 'wildcard';
    public const NUMERIC = 'numeric';
    public const APEX = 'apex';
    public const RESERVED = 'reserved';
    public const MALFORMED = 'malformed';

    /**
     * Normalise *safely*: trim, lowercase, and drop a pasted root domain.
     *
     * It deliberately does **not** replace invalid characters with hyphens or
     * collapse runs — that is left to validate() so the user is told the truth
     * about what they typed.
     *
     * @param string $input   Raw form value
     * @param string $root    Root domain of the selected extension ('' when unknown)
     */
    public static function normalise(string $input, string $root = ''): string
    {
        // Reject control characters and NUL outright rather than stripping them.
        $label = trim($input);

        // A leading "@" is common shorthand for "the apex": keep it visible to
        // validation instead of letting it vanish.
        if (str_starts_with($label, '@')) {
            return '@' . mb_strtolower(trim(substr($label, 1)));
        }

        $label = mb_strtolower($label);

        // Strip a pasted "name.root.tld" (or the bare apex) down to "name".
        $root = mb_strtolower(rtrim(trim($root), '.'));

        if ($root !== '' && str_ends_with($label, '.' . $root)) {
            $label = substr($label, 0, -1 * (strlen($root) + 1));
        }

        // Strip a trailing DNS dot: "mian." is the same name as "mian".
        return rtrim($label, '.');
    }

    /**
     * Full validation.
     *
     * @return array{ok: bool, label: string, code: string, message: string}
     */
    public static function validate(string $input, string $root = ''): array
    {
        $label = self::normalise($input, $root);
        $min = max(1, (int) config('subdomains.min_length', 3));
        $max = max($min, (int) config('subdomains.max_length', 32));

        $fail = static fn (string $code, string $message): array => [
            'ok' => false,
            'label' => $label,
            'code' => $code,
            'message' => $message,
        ];

        if ($label === '') {
            return $fail(self::EMPTY, 'Enter the subdomain you would like to reserve.');
        }

        // Anything that is not valid UTF-8 cannot be reasoned about at all.
        if (!mb_check_encoding($label, 'UTF-8')) {
            return $fail(self::MALFORMED, 'That name contains invalid characters.');
        }

        // Control characters, whitespace or a stray NUL anywhere: malformed.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $label) === 1) {
            return $fail(self::MALFORMED, 'Spaces and control characters are not allowed in a subdomain.');
        }

        if (str_contains($label, '*')) {
            return $fail(self::WILDCARD, 'Wildcard names are not available.');
        }

        // Explicitly reject a bare apex attempt before the charset check, so the
        // message is accurate ("that is the root domain") rather than "bad
        // characters".
        if ($root !== '' && $label === $root) {
            return $fail(self::APEX, 'That is the root domain itself; choose a name in front of it.');
        }

        if ($label === '@') {
            return $fail(self::APEX, 'That is the root domain itself; choose a name in front of it.');
        }

        // Non-ASCII (including homoglyphs and emoji) must be refused, not
        // transliterated: an IDN label is a different domain, not a spelling of
        // an ASCII one.
        if (preg_match('/[^\x20-\x7E]/', $label) === 1) {
            return $fail(self::NON_ASCII, 'Use plain ASCII letters, numbers and hyphens — accented and non-Latin characters are not supported yet.');
        }

        if (preg_match('/^xn--/', $label) === 1) {
            return $fail(self::PUNYCODE, 'Internationalised (punycode) labels are not supported yet.');
        }

        // A dot inside the label means the user pasted a hostname for a domain
        // we did not recognise. Refuse it rather than guessing an extension.
        if (str_contains($label, '.')) {
            return $fail(self::BAD_CHARSET, 'Enter only the part in front of the extension, for example "mian".');
        }

        if (preg_match('/[^a-z0-9-]/', $label) === 1) {
            return $fail(self::BAD_CHARSET, 'Use lowercase letters, numbers and hyphens only.');
        }

        if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
            return $fail(self::BAD_EDGE, 'A subdomain cannot start or end with a hyphen.');
        }

        if (str_contains($label, '--')) {
            return $fail(self::DOUBLE_HYPHEN, 'Please avoid double hyphens; they look like IDN prefixes.');
        }

        if (strlen($label) < $min) {
            return $fail(self::TOO_SHORT, sprintf('Subdomains must be between %d and %d characters.', $min, $max));
        }

        // DNS wire limit is 63 octets per label; the product limit is smaller.
        if (strlen($label) > 63) {
            return $fail(self::TOO_LONG, 'A DNS label cannot be longer than 63 characters.');
        }

        if (strlen($label) > $max) {
            return $fail(self::TOO_LONG, sprintf('Subdomains must be between %d and %d characters.', $min, $max));
        }

        if (preg_match('/^[0-9]+$/', $label) === 1) {
            return $fail(self::NUMERIC, 'All-numeric subdomains are not available.');
        }

        if (self::isReserved($label)) {
            return $fail(self::RESERVED, 'That name is reserved. Please pick another one.');
        }

        return ['ok' => true, 'label' => $label, 'code' => self::OK, 'message' => ''];
    }

    /**
     * Reserved names, configurable.
     *
     * Two layers: an explicit list (`subdomains.reserved_labels`) and anchored
     * patterns (`subdomains.reserved_patterns`) so ns1…ns9 are covered without
     * enumerating them.
     */
    public static function isReserved(string $label): bool
    {
        $label = mb_strtolower(trim($label));

        if ($label === '') {
            return true;
        }

        $reserved = array_map('mb_strtolower', (array) config('subdomains.reserved_labels', []));

        if (in_array($label, $reserved, true)) {
            return true;
        }

        foreach ((array) config('subdomains.reserved_patterns', []) as $pattern) {
            $pattern = (string) $pattern;

            if ($pattern !== '' && @preg_match('/' . $pattern . '/', $label) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a *cancelled/rejected* name can be offered again. Reserved names
     * never can, and neither can one that is delegated or apex-like.
     */
    public static function isReusable(string $label): bool
    {
        return self::validate($label)['ok'];
    }

    /**
     * Short reasons used by the availability engine. Kept together so the API
     * and the dashboard speak the same language.
     */
    public static function message(string $code): string
    {
        return match ($code) {
            self::RESERVED => 'That name is reserved by the platform.',
            self::APEX => 'The root domain cannot be reserved as a subdomain.',
            self::WILDCARD => 'Wildcard names are not available.',
            self::NON_ASCII => 'Non-ASCII names are not supported.',
            self::MALFORMED => 'That name contains invalid characters.',
            default => 'That name cannot be reserved.',
        };
    }
}
