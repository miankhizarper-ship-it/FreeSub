<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in development server.
 *
 *   php -S localhost:8000 router.php
 *
 * Apache uses .htaccess instead; this file exists only so `php -S` behaves the
 * same way: real files are served as-is, unknown paths render 404.php with a
 * proper 404 status, dot files and application internals stay unreachable.
 *
 * Plain `php -S` without this router also works for the app itself — it just
 * answers unknown URLs with 200 instead of 404. FreeSub only generates
 * extension-less URLs in one place (the CSP fallback page), so the app does not
 * depend on URL rewriting being available.
 */

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($uri) ? rawurldecode($uri) : '/';
// The built-in server hands the path over as SANITIZED_PATH_INFO; normalise it.
$pathInfo = $_SERVER['PATH_INFO'] ?? $_SERVER['SANITIZED_PATH_INFO'] ?? '';

if (is_string($pathInfo) && $pathInfo !== '') {
    $path = rawurldecode($pathInfo);
}

if ($path === '' || $path === false) {
    $path = '/';
}

$root = __DIR__;
$target = $root . $path;

// Mirror the Apache denies: dot files (.env, .git, ...) and application
// internals (config/, includes/, storage/, database/, tests/) are never served.
if (preg_match('#(^|/)\.#', $path) === 1
    || preg_match('#^/(config|includes|storage|database|tests)(/|$)#', $path) === 1
) {
    http_response_code(404);
    require $root . '/404.php';

    return true;
}

// Directory request: serve its index.php when present.
if (is_dir($target)) {
    if (is_file($target . '/index.php')) {
        require $target . '/index.php';

        return true;
    }

    http_response_code(404);
    require $root . '/404.php';

    return true;
}

// Real file: let the built-in server handle it (static assets, favicons, ...).
if (is_file($target)) {
    if (str_ends_with(strtolower($target), '.php')) {
        require $target;

        return true;
    }

    return false;
}

http_response_code(404);
require $root . '/404.php';

return true;
