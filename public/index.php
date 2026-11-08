<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Waar staat de app? Normaal één map hoger. Bij het zip-pakket staat deze map
// als public_html naast de app-map booking/ (zie App\Support\Indeling).
$basis = is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/..' : __DIR__.'/../booking';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $basis.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $basis.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $basis.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
