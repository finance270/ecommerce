<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

Auth::require();

[$from, $to] = dateRange();
$platform = platformFilter();
$report = (string) (q('report') ?? '');

/**
 * Ekspor CSV dengan BOM UTF-8 supaya langsung rapi saat dibuka di Excel,
 * dan pemisah titik koma sesuai kebiasaan Excel lokal Indonesia.
 */
function csvOut(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map(
            static fn($v): string => $v === null ? '' : (string) $v,
            is_array($r) ? array_values($r) : [$r]
        ), ';', '"', '');
    }
    fclose($out);
    exit;
}

$stamp = date('Ymd_His');

// Setiap laporan menempel pada tab tertentu; ekspor tidak boleh jadi jalan
// pintas mengambil data dari tab yang tidak boleh dibuka.
$tabLaporan = [
    'orders' => 'orders', 'settlements' => 'settlements',
    'fee_detail' => 'pnl', 'fee_category' => 'pnl', 'monthly_settlement' => 'pnl',
    'product_net' => 'pnl', 'product_profit' => 'pnl',
    'products' => 'products', 'weekly' => 'performance',
    'unsettled' => 'recon', 'unmatched' => 'monitoring', 'monitoring' => 'monitoring',
    'missing_cost' => 'costs', 'cost_check' => 'costs',
    'expenses' => 'expenses',
    'refunds' => 'refunds', 'refund_product' => 'refunds',
];
if (!isset($tabLaporan[$report]) || !Auth::can($tabLaporan[$report])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Akun Anda tidak berhak mengunduh laporan ini.';
    exit;
}

switch ($report) {
    case 'orders':
        $w = ['1=1'];
        $a = [];
        if ($from !== null)     { $w[] = 'order_date >= ?'; $a[] = $from; }
        if ($to !== null)       { $w[] = 'order_date <= ?'; $a[] = $to; }
        if ($platform !== null) { $w[] = 'platform = ?';    $a[] = $platform; }
        $status = q('status');
        if (in_array($status, ['selesai', 'batal', 'proses', 'retur'], true)) {
            $w[] = 'status_norm = ?';
            $a[] = $status;
        }
        $search = q('q');
        if ($search !== null) {
            $w[] = '(order_id LIKE ? OR buyer_username LIKE ? OR recipient LIKE ? OR tracking_no LIKE ?)';
            $like = '%' . $search . '%';
            array_push($a, $like, $like, $like, $like);
        }
        $rows = Db::all(
            'SELECT platform, order_id, order_date, status_raw, channel, buyer_username, recipient,
                    province, city, payment_method, shipping_provider, tracking_no,
                    line_count, total_qty, items_subtotal_before, discount_seller, discount_platform,
                    items_subtotal_after, shipping_fee_original, order_amount
             FROM orders WHERE ' . implode(' AND ', $w) . ' ORDER BY order_date DESC',
            $a
        );
        csvOut("pesanan_{$stamp}.csv", [
            'Platform', 'No Pesanan', 'Tanggal', 'Status', 'Channel', 'Username Pembeli', 'Penerima',
            'Provinsi', 'Kota', 'Metode Bayar', 'Kurir', 'No Resi',
            'Jumlah Baris', 'Total Qty', 'Subtotal Sebelum Diskon', 'Diskon Penjual', 'Diskon Platform',
            'Subtotal Setelah Diskon', 'Ongkir', 'Total Bayar',
        ], $rows);
        // no break - csvOut() keluar

    case 'settlements':
        $w = ['1=1'];
        $a = [];
        if ($from !== null)     { $w[] = 'settlement_date >= ?'; $a[] = $from; }
        if ($to !== null)       { $w[] = 'settlement_date <= ?'; $a[] = $to; }
        if ($platform !== null) { $w[] = 'platform = ?';         $a[] = $platform; }
        $rows = Db::all(
            'SELECT platform, order_id, trx_type, settlement_date, order_time,
                    (gross_amount + refund_amount) AS gross_amount,
                    -refund_amount AS refund_amount, total_fee, net_amount,
                    fee_komisi, fee_layanan, fee_administrasi, fee_pembayaran, fee_proses,
                    fee_pengiriman, fee_afiliasi, fee_iklan, fee_kampanye, fee_pajak,
                    fee_asuransi, fee_fulfillment, fee_promosi, fee_lainnya
             FROM settlements WHERE ' . implode(' AND ', $w) . ' ORDER BY settlement_date DESC',
            $a
        );
        csvOut("settlement_{$stamp}.csv", [
            'Platform', 'No Pesanan', 'Jenis Transaksi', 'Tanggal Dana Dilepas', 'Waktu Pesanan',
            'Pendapatan Kotor', 'Pengembalian', 'Total Biaya', 'Dana Diterima',
            'Biaya Komisi', 'Biaya Layanan', 'Biaya Administrasi', 'Biaya Pembayaran', 'Biaya Proses',
            'Biaya Pengiriman', 'Komisi Afiliasi', 'Biaya Iklan', 'Biaya Kampanye', 'Pajak',
            'Asuransi', 'Fulfillment', 'Promosi', 'Lainnya',
        ], $rows);

    case 'fee_detail':
        $rows = Reports::feeDetail($from, $to, $platform, true);
        csvOut("rincian_biaya_{$stamp}.csv",
            ['Platform', 'Kode', 'Komponen Biaya', 'Kategori', 'Total', 'Jumlah Transaksi'],
            array_map(static fn($r) => [
                $r['platform'], $r['fee_code'], $r['fee_label'],
                Profiles::LABELS[$r['fee_category']] ?? $r['fee_category'],
                $r['total'], $r['jumlah_transaksi'],
            ], $rows)
        );

    case 'fee_category':
        $pnl = Reports::pnl($from, $to, $platform);
        csvOut("biaya_per_kategori_{$stamp}.csv",
            ['Kategori', 'Total'],
            array_map(static fn($r) => [
                Profiles::LABELS[$r['fee_category']] ?? $r['fee_category'],
                $r['total'],
            ], $pnl['kategori'])
        );

    case 'monitoring':
        $rows = Reports::dataMonitor();
        csvOut("monitoring_kelengkapan_{$stamp}.csv", [
            'Bulan', 'Pesanan', 'Settlement', 'Pesanan Settle', 'Belum Ada Data Pesanan',
            'Alokasi Produk %', 'Nilai Belum Teralokasi', 'Produk Terjual', 'Produk Tanpa HPP',
            'Beban Operasional', 'Pesanan Diperbarui', 'Settlement Diperbarui',
        ], array_map(static function (array $b): array {
            $alok = $b['settle_pesanan'] > 0
                ? round(($b['settle_pesanan'] - $b['settle_tanpa_order']) / $b['settle_pesanan'] * 100, 2)
                : '';
            return [
                $b['ym'], $b['pesanan'], $b['settlement'], $b['settle_pesanan'],
                $b['settle_tanpa_order'], $alok, round($b['nilai_tanpa_order'], 2),
                $b['produk'], $b['produk_tanpa_hpp'], round($b['beban'], 2),
                $b['pesanan_update'], $b['settlement_update'],
            ];
        }, $rows));

    case 'unmatched':
        $ym = q('ym');
        if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            $ym = null;
        }
        $rows = Reports::unmatchedSettlements($ym, 100000);
        csvOut("settlement_tanpa_data_pesanan_{$stamp}.csv",
            ['Bulan Settle', 'Platform', 'No Pesanan', 'Dana Bersih'],
            array_map(static fn($r) => [$r['period_ym'], $r['platform'], $r['order_id'], $r['net_amount']], $rows)
        );

    case 'cost_check':
        $ym = q('ym');
        if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            $ym = null;
        }
        $min = (float) (q('min') ?? Reports::MARJIN_MIN);
        $max = (float) (q('max') ?? Reports::MARJIN_MAX);
        $bad = (float) (q('bad') ?? 100);
        $rows = Reports::costMarginCheck($ym, $platform, $min, $max, $bad);
        csvOut("uji_kewajaran_hpp_{$stamp}.csv", [
            'Status', 'Bulan', 'Produk', 'Variasi', 'Qty', 'HPP per Unit',
            'Bersih per Unit', 'Total HPP', 'Total Bersih', 'Laba', 'Marjin %', 'Catatan',
        ], array_map(static fn($r) => [
            $r['status'], $r['period_ym'], $r['produk'], $r['variasi'], $r['qty'],
            round((float) $r['hpp_unit'], 2), round((float) $r['bersih_unit'], 2),
            round((float) $r['hpp'], 2), round((float) $r['bersih'], 2), round((float) $r['laba'], 2),
            $r['marjin'] === null ? '' : round((float) $r['marjin'], 2), $r['alasan'],
        ], $rows));

    case 'missing_cost':
        $ym = q('ym');
        if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            $ym = null;
        }
        $rows = Reports::missingCosts($ym, 100000);
        csvOut("hpp_belum_diisi_{$stamp}.csv",
            ['Periode', 'Nama Produk', 'Variasi', 'Qty Terjual', 'Nilai Bersih'],
            array_map(static fn($r) => [
                $r['period_ym'], $r['produk'], $r['variasi'], $r['qty'], round((float) $r['nilai_bersih'], 2),
            ], $rows)
        );

    case 'expenses':
        $ym = q('ym');
        if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            $ym = null;
        }
        $rows = Reports::expenseList($ym, 100000);
        csvOut("beban_operasional_{$stamp}.csv",
            ['Periode', 'Kategori', 'Keterangan', 'Jumlah'],
            array_map(static fn($r) => [$r['period_ym'], $r['category'], $r['description'], $r['amount']], $rows)
        );

    case 'product_profit':
        $psort = q('psort', 'laba');
        $rows = Reports::productProfit($from, $to, $platform, 5000, (string) $psort);
        csvOut("laba_per_produk_{$stamp}.csv", [
            'Platform', 'Produk', 'Pesanan', 'Qty', 'Pendapatan Kotor',
            'Diskon & Voucher Penjual', 'Biaya Platform',
            'Dana Diterima Bersih', 'HPP', 'Laba', 'Marjin Laba %', 'Qty Tanpa HPP',
        ], array_map(static fn($r) => [
            $r['platform'], $r['produk'], $r['pesanan'], $r['qty'],
            round((float) $r['kotor'], 2), round((float) $r['potongan'], 2),
            round((float) $r['biaya'], 2),
            round((float) $r['bersih'], 2), round((float) $r['hpp'], 2),
            round((float) $r['laba'], 2),
            $r['marjin_laba'] === null ? '' : round((float) $r['marjin_laba'], 2),
            $r['qty_tanpa_hpp'],
        ], $rows));

    case 'product_net':
        $psort = q('psort', 'bersih');
        $rows = Reports::productNet($from, $to, $platform, 5000, (string) $psort);
        csvOut("laba_bersih_per_produk_{$stamp}.csv", [
            'Platform', 'Produk', 'Pesanan', 'Qty', 'Pendapatan Kotor',
            'Diskon & Voucher Penjual', 'Biaya Platform',
            'Dana Diterima Bersih', 'Marjin %',
        ], array_map(static fn($r) => [
            $r['platform'], $r['produk'], $r['pesanan'], $r['qty'],
            round((float) $r['kotor'], 2), round((float) $r['potongan'], 2),
            round((float) $r['biaya'], 2),
            round((float) $r['bersih'], 2),
            $r['marjin'] === null ? '' : round((float) $r['marjin'], 2),
        ], $rows));

    case 'monthly_settlement':
        $rows = Reports::monthlySettlement($from, $to, $platform);
        csvOut("rekap_bulanan_settlement_{$stamp}.csv", [
            'Bulan', 'Platform', 'Transaksi', 'Pendapatan Kotor', 'Diskon & Voucher Penjual',
            'Biaya Platform', 'Penyesuaian', 'Selisih Pencatatan',
            'Dana Diterima Bersih',
        ], array_map(static fn($r) => [
            $r['bulan'], $r['platform'], $r['trx'], $r['pendapatan_kotor'], $r['potongan'],
            $r['total_biaya'], $r['penyesuaian'],
            round((float) $r['selisih'], 2), $r['dana_diterima'],
        ], $rows));

    case 'refunds':
        $rows = Reports::refundList($from, $to, $platform, 100000);
        csvOut("pengembalian_{$stamp}.csv", [
            'Platform', 'No Pesanan', 'Tanggal Dana Dilepas', 'Jenis Transaksi',
            'Kotor Sebelum Refund', 'Pengembalian', 'Dana Diterima',
        ], array_map(static fn($r) => [
            $r['platform'], $r['order_id'], $r['settlement_date'], $r['trx_type'],
            round((float) $r['gross_amount'], 2), round((float) $r['refund'], 2),
            round((float) $r['net_amount'], 2),
        ], $rows));

    case 'refund_product':
        $rows = Reports::refundByProduct($from, $to, $platform, 5000);
        csvOut("pengembalian_per_produk_{$stamp}.csv", [
            'Produk', 'Variasi', 'Pesanan', 'Qty Retur', 'Nilai Pengembalian',
        ], array_map(static fn($r) => [
            $r['produk'], $r['variasi'], $r['pesanan'], $r['qty_retur'],
            round((float) $r['refund'], 2),
        ], $rows));

    case 'products':
        $sort = q('sort', 'omzet') === 'qty' ? 'qty' : 'omzet';
        $rows = Reports::topProducts($from, $to, $platform, 5000, $sort);
        csvOut("produk_{$stamp}.csv",
            ['Platform', 'Produk', 'Pesanan', 'Qty Terjual', 'Qty Retur', 'Omzet', 'Diskon Penjual'],
            array_map(static fn($r) => [
                $r['platform'], $r['produk'], $r['pesanan'], $r['qty_terjual'],
                $r['qty_retur'], $r['omzet'], $r['diskon_penjual'],
            ], $rows)
        );

    case 'weekly':
        $rows = Reports::weekly($from, $to, $platform);
        csvOut("performa_mingguan_{$stamp}.csv",
            ['Mulai', 'Sampai', 'Pesanan', 'Selesai', 'Batal', 'Qty', 'Omzet'],
            array_map(static fn($r) => [
                $r['mulai'], $r['selesai_tgl'], $r['pesanan'], $r['pesanan_selesai'],
                $r['pesanan_batal'], $r['qty'], $r['omzet'],
            ], $rows)
        );

    case 'unsettled':
        $rows = Reports::unsettledOrders($from, $to, $platform, 100000);
        csvOut("pesanan_belum_settle_{$stamp}.csv",
            ['Platform', 'No Pesanan', 'Tanggal', 'Status', 'Nilai Produk', 'Total Bayar'],
            array_map(static fn($r) => [
                $r['platform'], $r['order_id'], $r['order_date'], $r['status_raw'],
                $r['nilai_pesanan'], $r['order_amount'],
            ], $rows)
        );

    default:
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Jenis laporan tidak dikenal.';
        exit;
}
