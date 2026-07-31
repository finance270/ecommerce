<?php
declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL berkas aset dengan penanda versi dari waktu ubah berkasnya.
 *
 * Dipakai supaya browser otomatis mengambil ulang CSS/JS setiap kali
 * aplikasi diperbarui. Penanda versi manual pernah membuat pengguna
 * memakai berkas lama dari cache setelah aplikasi di-update.
 */
function assetUrl(string $file): string
{
    $path = dirname(__DIR__) . '/public/' . ltrim($file, '/');
    $ver = is_file($path) ? (string) filemtime($path) : '0';
    return $file . '?v=' . $ver;
}

/** Format rupiah, mis. 1234567 => "1.234.567" */
function rp(mixed $v, bool $withPrefix = false): string
{
    $n = (float) $v;
    $s = number_format(abs($n), 0, ',', '.');
    $s = ($n < 0 ? '-' : '') . ($withPrefix ? 'Rp ' : '') . $s;
    return $s;
}

function num(mixed $v, int $dec = 0): string
{
    return number_format((float) $v, $dec, ',', '.');
}

function pct(mixed $part, mixed $total, int $dec = 1): string
{
    $t = (float) $total;
    if ($t == 0.0) {
        return '-';
    }
    return number_format((float) $part / $t * 100, $dec, ',', '.') . '%';
}

function shortDate(?string $d): string
{
    if ($d === null || $d === '') {
        return '-';
    }
    $ts = strtotime($d);
    if ($ts === false) {
        return (string) $d;
    }
    static $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function humanBytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $f = (float) $b;
    while ($f >= 1024 && $i < count($u) - 1) {
        $f /= 1024;
        $i++;
    }
    return number_format($f, $i === 0 ? 0 : 1, ',', '.') . ' ' . $u[$i];
}

function badgeStatus(string $s): string
{
    $map = [
        'selesai' => 'ok',
        'batal'   => 'bad',
        'retur'   => 'warn',
        'proses'  => 'info',
    ];
    return $map[$s] ?? 'muted';
}

/** Ambil parameter GET yang aman untuk dipakai di URL. */
function q(string $key, ?string $default = null): ?string
{
    $v = $_GET[$key] ?? $default;
    if (!is_string($v)) {
        return $default;
    }
    $v = trim($v);
    return $v === '' ? $default : $v;
}

/** Rentang tanggal aktif dari query string, dengan nilai bawaan. */
/**
 * Rentang tanggal baku: 1 Januari tahun ini sampai akhir bulan lalu.
 *
 * Bulan berjalan sengaja tidak diikutkan karena datanya belum lengkap - dana
 * settlement baru masuk beberapa hari setelah pesanan selesai, jadi bulan yang
 * sedang berlangsung selalu terlihat lebih kecil dari kenyataan.
 *
 * @return array{0:string,1:string}
 */
function defaultDateRange(): array
{
    $from = date('Y-01-01');
    $to   = date('Y-m-t', strtotime('first day of last month'));
    if ($to < $from) {
        // Bulan Januari: bulan lalu masih tahun sebelumnya, jadi pakai bulan
        // berjalan supaya rentangnya tidak terbalik.
        $to = date('Y-m-t');
    }
    return [$from, $to];
}

function dateRange(string $defaultFrom = '', string $defaultTo = ''): array
{
    if ($defaultFrom === '' && $defaultTo === '') {
        [$defaultFrom, $defaultTo] = defaultDateRange();
    }
    $from = q('from', $defaultFrom !== '' ? $defaultFrom : null);
    $to   = q('to', $defaultTo !== '' ? $defaultTo : null);
    $valid = static fn(?string $d): ?string =>
        $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : null;
    return [$valid($from), $valid($to)];
}

function buildQuery(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }
    return http_build_query($params);
}

/** Pilihan platform yang valid. */
function platformFilter(): ?string
{
    $p = q('platform');
    return in_array($p, ['tokopedia', 'shopee'], true) ? $p : null;
}

function flash(?string $msg = null, string $type = 'info'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
