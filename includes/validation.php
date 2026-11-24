<?php

declare(strict_types=1);

/**
 * Input validation primitives.
 *
 * Every rule returns a human readable error string, or null when the value is
 * acceptable. Controllers collect the messages and re-render the form.
 */
final class Validator
{
    /**
     * @return array<string, string> field => message
     */
    public static function registration(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Please tell us your name.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Your name must be between 2 and 100 characters.';
        } elseif (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\s\'\.\-]*$/u', $name)) {
            $errors['name'] = 'Your name may only contain letters, spaces, apostrophes, dots and hyphens.';
        }

        $emailError = self::email($email);

        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }

        $passwordError = self::password($password);

        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        } elseif (!hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'The passwords do not match.';
        }

        return $errors;
    }

    public static function email(string $email): ?string
    {
        if ($email === '') {
            return 'An email address is required.';
        }

        if (mb_strlen($email) > 190) {
            return 'That email address is too long.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Please enter a valid email address.';
        }

        // Local-part sanity: reject control characters, quotes and whitespace.
        if (preg_match('/[\s"\\\\,;]/', explode('@', $email)[0]) === 1) {
            return 'Please enter a valid email address.';
        }

        return null;
    }

    public static function password(string $password): ?string
    {
        $minimum = 10;

        if ($password === '') {
            return 'Please choose a password.';
        }

        if (strlen($password) < $minimum) {
            return 'Your password must be at least ' . $minimum . ' characters long.';
        }

        if (strlen($password) > 200) {
            return 'That password is too long (maximum 200 bytes).';
        }

        if (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
            return 'Use at least one letter and one number in your password.';
        }

        $common = ['password', '12345678', 'qwerty', 'letmein', 'welcome', 'iloveyou', 'admin123', 'freesub'];

        foreach ($common as $weak) {
            if (str_contains(strtolower($password), $weak)) {
                return 'That password is too easy to guess. Please pick something less common.';
            }
        }

        return null;
    }

    public static function login(array $input): array
    {
        $errors = [];

        $emailError = self::email(trim((string) ($input['email'] ?? '')));

        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }

        if ((string) ($input['password'] ?? '') === '') {
            $errors['password'] = 'Please enter your password.';
        }

        return $errors;
    }

    /**
     * Validate a subdomain label (the part before the root domain).
     */
    public static function subdomainLabel(string $label): ?string
    {
        $min = (int) config('subdomains.min_length', 3);
        $max = (int) config('subdomains.max_length', 32);

        if ($label === '') {
            return 'Enter the subdomain you would like to reserve.';
        }

        if (strlen($label) < $min || strlen($label) > $max) {
            return sprintf('Subdomains must be between %d and %d characters.', $min, $max);
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
            return 'Use lowercase letters, numbers and hyphens only — and do not start or end with a hyphen.';
        }

        if (str_contains($label, '--')) {
            return 'Please avoid double hyphens; they look like IDN prefixes.';
        }

        if (preg_match('/^xn--/', $label) === 1) {
            return 'Internationalised (punycode) labels are not supported yet.';
        }

        $reserved = (array) config('subdomains.reserved_labels', []);

        if (in_array($label, $reserved, true)) {
            return 'That name is reserved. Please pick another one.';
        }

        if (preg_match('/^\d+$/', $label) === 1) {
            return 'All-numeric subdomains are not available.';
        }

        return null;
    }

    public static function abuseReport(array $input): array
    {
        $errors = [];

        $fqdn = strtolower(trim((string) ($input['fqdn'] ?? '')));

        if ($fqdn === '') {
            $errors['fqdn'] = 'Enter the hostname you are reporting.';
        } elseif (!preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $fqdn)) {
            $errors['fqdn'] = 'Enter a valid hostname, for example mysite.kharcha.info.';
        }

        $emailError = self::email(trim((string) ($input['reporter_email'] ?? '')));

        if ($emailError !== null) {
            $errors['reporter_email'] = $emailError;
        }

        $allowed = ['phishing', 'malware', 'spam', 'copyright', 'illegal', 'other'];

        if (!in_array((string) ($input['category'] ?? ''), $allowed, true)) {
            $errors['category'] = 'Choose the category that best matches the issue.';
        }

        $message = trim((string) ($input['message'] ?? ''));

        if (mb_strlen($message) < 20) {
            $errors['message'] = 'Please describe the issue in at least 20 characters.';
        } elseif (mb_strlen($message) > 5000) {
            $errors['message'] = 'Please keep your report under 5000 characters.';
        }

        return $errors;
    }
}
