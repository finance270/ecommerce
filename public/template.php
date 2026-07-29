<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

Auth::require();
@set_time_limit(300);

$type   = q('type', 'hpp');
$format = q('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';
$ym     = q('ym');
if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
    $ym = null;
}

/** Kirim sebagai unduhan lalu hapus berkas sementaranya. */
function sendFile(string $path, string $filename, string $mime): never
{
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

function sendCsv(string $filename, array $header, array $rows, array $notes): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($notes as $n) {
        fputcsv($out, [$n], ';', '"', '');
    }
    if ($notes !== []) {
        fputcsv($out, [''], ';', '"', '');
    }
    fputcsv($out, $header, ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map(static fn($v): string => $v === null ? '' : (string) $v, $r), ';', '"', '');
    }
    fclose($out);
    exit;
}

$stamp = date('Ymd');

if ($type === 'beban') {
    $header = ['Periode (YYYY-MM)', 'Kategori', 'Keterangan', 'Jumlah'];
    $notes = [
        'Template Beban Operasional. Isi satu baris untuk tiap pos beban per bulan.',
        'Periode wajib format YYYY-MM (contoh 2026-01). Jumlah diisi angka rupiah tanpa titik/koma.',
        'Baris keterangan ini boleh dihapus. Kolom Kategori + Keterangan menjadi penanda baris,',
        'jadi kalau berkas diunggah ulang dengan isi sama, datanya tidak dobel.',
    ];
    $bulan = $ym ?? date('Y-m');
    $rows = [
        [$bulan, 'Gaji', 'Gaji karyawan', 0],
        [$bulan, 'Sewa', 'Sewa gudang/toko', 0],
        [$bulan, 'Listrik & Air', '', 0],
        [$bulan, 'Internet & Telepon', '', 0],
        [$bulan, 'Packaging', 'Kardus, bubble wrap, lakban', 0],
        [$bulan, 'Iklan', 'Iklan di luar platform', 0],
        [$bulan, 'Transportasi', '', 0],
        [$bulan, 'Penyusutan', '', 0],
        [$bulan, 'Lainnya', '', 0],
    ];

    if ($format === 'csv') {
        sendCsv("template_beban_operasional_{$stamp}.csv", $header, $rows, $notes);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'tpl_');
    XlsxWriter::write($tmp, 'Beban Operasional', $header, $rows, [20, 24, 42, 18], $notes);
    sendFile($tmp, "template_beban_operasional_{$stamp}.xlsx",
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

// ---- Template HPP: sudah terisi daftar produk yang benar-benar terjual ----
$produk = Reports::soldProducts($ym);
$bulan = $ym ?? date('Y-m');

$existing = [];
foreach (Reports::costList($bulan, null, 100000) as $c) {
    $existing[$c['cost_key']] = $c['cost_per_unit'];
}

$header = ['Periode (YYYY-MM)', 'Nama Produk', 'Variasi', 'SKU', 'HPP per Unit', 'Catatan'];
$notes = [
    'Template HPP per produk per bulan. Daftar produk di bawah diambil dari data penjualan Anda.',
    'Cukup isi kolom "HPP per Unit" (angka rupiah tanpa titik/koma). Baris yang dikosongkan akan dilewati.',
    'JANGAN mengubah kolom Nama Produk dan Variasi - keduanya dipakai untuk mencocokkan dengan data penjualan.',
    'Kolom SKU hanya keterangan, tidak dipakai mencocokkan. Baris keterangan ini boleh dihapus.',
];

$rows = [];
foreach ($produk as $p) {
    $key = Value::costKey($p['produk'], $p['variasi']);
    $rows[] = [
        $bulan,
        $p['produk'],
        $p['variasi'],
        $p['sku'] !== '' ? $p['sku'] : null,
        isset($existing[$key]) ? (float) $existing[$key] : null,
        null,
    ];
}
if ($rows === []) {
    $rows[] = [$bulan, 'Contoh: Kopi Susu 1KG', 'Biji Kopi', '', 0, 'Belum ada data penjualan'];
}

$suffix = $ym !== null ? '_' . str_replace('-', '', $ym) : '';
if ($format === 'csv') {
    sendCsv("template_hpp{$suffix}_{$stamp}.csv", $header, $rows, $notes);
}
$tmp = tempnam(sys_get_temp_dir(), 'tpl_');
XlsxWriter::write($tmp, 'HPP per Produk', $header, $rows, [20, 52, 26, 18, 16, 30], $notes);
sendFile($tmp, "template_hpp{$suffix}_{$stamp}.xlsx",
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
