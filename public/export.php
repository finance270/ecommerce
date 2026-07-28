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
                    gross_amount, refund_amount, total_fee, net_amount,
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
            ['Kategori', 'Total', 'Jumlah Baris'],
            array_map(static fn($r) => [
                Profiles::LABELS[$r['fee_category']] ?? $r['fee_category'],
                $r['total'], $r['baris'],
            ], $pnl['kategori'])
        );

    case 'product_net':
        $psort = q('psort', 'bersih');
        $rows = Reports::productNet($from, $to, $platform, 5000, (string) $psort);
        csvOut("laba_bersih_per_produk_{$stamp}.csv", [
            'Platform', 'Produk', 'Pesanan', 'Qty', 'Pendapatan Kotor',
            'Diskon & Voucher Penjual', 'Pengembalian Dana', 'Biaya Platform',
            'Dana Diterima Bersih', 'Marjin %',
        ], array_map(static fn($r) => [
            $r['platform'], $r['produk'], $r['pesanan'], $r['qty'],
            round((float) $r['kotor'], 2), round((float) $r['potongan'], 2),
            round((float) $r['pengembalian'], 2), round((float) $r['biaya'], 2),
            round((float) $r['bersih'], 2),
            $r['marjin'] === null ? '' : round((float) $r['marjin'], 2),
        ], $rows));

    case 'monthly_settlement':
        $rows = Reports::monthlySettlement($from, $to, $platform);
        csvOut("rekap_bulanan_settlement_{$stamp}.csv", [
            'Bulan', 'Platform', 'Transaksi', 'Pendapatan Kotor', 'Diskon & Voucher Penjual',
            'Pengembalian Dana', 'Biaya Platform', 'Penyesuaian', 'Selisih Pencatatan',
            'Dana Diterima Bersih',
        ], array_map(static fn($r) => [
            $r['bulan'], $r['platform'], $r['trx'], $r['pendapatan_kotor'], $r['potongan'],
            $r['pengembalian'], $r['total_biaya'], $r['penyesuaian'],
            round((float) $r['selisih'], 2), $r['dana_diterima'],
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
