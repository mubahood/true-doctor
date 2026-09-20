<?php

/**
 * The front controller, for a host whose document root cannot be moved.
 *
 * On shared cPanel hosting the domain's document root is a fixed directory
 * (~/true_doctor) and it is not `public/`. The usual advice — "point the
 * document root at public/" — needs a control panel, so instead the contents
 * of public/ are mirrored into the document root by deploy/deploy.sh and this
 * file replaces Laravel's own index.php.
 *
 * The only difference from Laravel's is where it looks for the application:
 * one directory up and across, OUTSIDE the web root. That is the part that
 * matters. It means .env, the source, the storage directory and the vendor
 * tree are all somewhere Apache will not serve even if a rewrite rule breaks
 * — which is the protection the public/ layout exists to give in the first
 * place, kept rather than traded away.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/** The application root, beside the document root rather than inside it. */
$app_base = dirname(__DIR__).'/true_doctor_app';

// A clear failure beats a white page: if the path is wrong, say which path.
if (! is_file($app_base.'/vendor/autoload.php')) {
    http_response_code(500);
    exit('Application not found at '.$app_base.' — check deploy/docroot-index.php.');
}

// Laravel's own maintenance-mode short circuit, before anything is booted.
if (file_exists($maintenance = $app_base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_base.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_base.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
