<?php

declare(strict_types=1);

/**
 * Tiny, dependency-free .env loader.
 *
 * Real environment variables (getenv / $_ENV / $_SERVER) always win over the
 * values written in the .env file, which makes container deployments and CI
 * overrides safe: the file is only ever a local default.
 */

if (!function_exists('env_load')) {
    /**
     * Parse a .env file into the process environment.
     *
     * Supported syntax:
     *   KEY=value
     *   KEY="quoted value with # hash"
     *   KEY='single quoted'
     *   export KEY=value
     *   # comment
     */
    function env_load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            $length = strlen($value);

            if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
                $quote = $value[0];
                $value = substr($value, 1, -1);

                if ($quote === '"') {
                    $value = str_replace(['\n', '\r', '\t', '\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                }
            } elseif (($hash = strpos($value, ' #')) !== false) {
                // Unquoted values may carry an inline comment.
                $value = rtrim(substr($value, 0, $hash));
            }

            // Never clobber variables provided by the real environment.
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable with type coercion, mirroring common dotenv
     * behaviour: true/false/null/empty and numeric strings are normalised.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower(trim($value))) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => trim($value),
        };
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, $default);

        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int
    {
        $value = env($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
