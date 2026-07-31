<?php
declare(strict_types=1);

/** Titik masuk bersama untuk seluruh halaman. */

foreach (['Config', 'Db', 'Value', 'XlsxReader', 'XlsxWriter', 'Profiles', 'Importer', 'CostImporter', 'Pemasang', 'Pusat', 'Tenant', 'Perm', 'Auth', 'Helpers', 'Tax', 'Reports'] as $class) {
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

// Perusahaan aktif boleh ditentukan lewat ?db= pada tautan. Ini yang membuat
// tiap perusahaan punya tautan masuknya sendiri - termasuk tautan direksi -
// tanpa perlu memilih dari daftar.
$dbPilihan = $_GET['db'] ?? null;
if (is_string($dbPilihan) && $dbPilihan !== '') {
    Tenant::pilih($dbPilihan);
}
