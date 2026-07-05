<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

$mimeTypes = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'svg' => 'image/svg+xml',
    'gif' => 'image/gif',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
    'otf' => 'font/otf',
];

// Serve legacy hard-coded /public/assets/* paths from the real assets dir.
if (str_starts_with($uri, '/public/assets/')) {
    $assetPath = __DIR__.'/public/assets/'.substr($uri, strlen('/public/assets/'));
    if (file_exists($assetPath) && ! is_dir($assetPath)) {
        $ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        $mimeType = $mimeTypes[$ext] ?? (mime_content_type($assetPath) ?: 'application/octet-stream');
        header('Content-Type: '.$mimeType);
        readfile($assetPath);
        return true;
    }
}

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if (
    $uri !== '/'
    && (
        (file_exists(__DIR__.$uri) && ! is_dir(__DIR__.$uri))
        || (file_exists(__DIR__.'/public'.$uri) && ! is_dir(__DIR__.'/public'.$uri))
    )
) {
    return false;
}

require_once __DIR__.'/public/index.php';
