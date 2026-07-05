<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Serve Legacy /public/assets/* Requests
|--------------------------------------------------------------------------
|
| Many views hard-code asset paths as /public/assets/... Serve them from
| the actual public/assets directory without bootstrapping the framework.
|
*/

$uri = $_SERVER['REQUEST_URI'] ?? '';
if (str_starts_with($uri, '/public/assets/')) {
    $relative = parse_url($uri, PHP_URL_PATH);
    $path = __DIR__ . '/assets/' . substr($relative, strlen('/public/assets/'));
    if (file_exists($path) && is_file($path)) {
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        readfile($path);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/
// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
