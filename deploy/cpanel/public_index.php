<?php

declare(strict_types=1);

/*
 * The front door for shared hosting. Copy this file to public_html/index.php (next to the contents of the app's public/ folder).
 * The application itself lives OUTSIDE the web folder, in ~/velmora (a link to the current release, see deploy/cpanel/deploy.sh), so nobody can ever download
 * .env, the code, or the logs. If your app folder has another name or place, change $app_dir only.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_dir = dirname(__DIR__).'/velmora';

if (file_exists($maintenance = $app_dir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_dir.'/vendor/autoload.php';

$app = require_once $app_dir.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);   // assets and the public storage link live here, in the web folder

$app->handleRequest(Request::capture());
