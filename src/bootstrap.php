<?php
declare(strict_types=1);

/** Titik masuk bersama untuk seluruh halaman. */

foreach (['Config', 'Db', 'Value', 'XlsxReader', 'XlsxWriter', 'Profiles', 'Importer', 'CostImporter', 'Perm', 'Auth', 'Helpers', 'Tax', 'Reports'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}

Config::load();
date_default_timezone_set((string) Config::get('app_tz', 'Asia/Jakarta'));

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
