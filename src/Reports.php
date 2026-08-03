<?php
declare(strict_types=1);

/**
 * Kumpulan query laporan.
 *
 * Catatan penting soal sudut pandang angka:
 *   - Tabel `orders`      dipakai untuk PERFORMA penjualan (tanggal pesanan).
 *   - Tabel `settlements` dipakai untuk AKUNTANSI/KEUANGAN (tanggal dana
 *     dilepaskan platform). Dua tanggal ini berbeda, jadi laporannya dipisah
 *     supaya tidak menyesatkan.
 */
final class Reports
{
    /**
     * Pendapatan kotor yang SUDAH dikurangi pengembalian dana.
     *
     * Barang yang direfund kembali ke penjual, jadi penjualannya memang tidak
     * pernah jadi. Menampilkannya sebagai "kotor" penuh lalu menguranginya
     * lagi di baris terpisah membuat omzet terlihat lebih besar dari yang
     * sebenarnya terjadi. Karena itu seluruh laporan memakai angka yang sudah
     * bersih dari refund, dan refund dilaporkan terpisah di halaman
     * Pengembalian.
     *
     * `refund_amount` disimpan bernilai negatif, jadi cukup ditambahkan.
     */
    public const KOTOR = '(gross_amount + refund_amount)';

    /**
     * Penjualan bersih - dasar penyebut seluruh persentase marjin.
     *
     *     penjualan bersih = pendapatan kotor - potongan & diskon
     *
     * Pendapatan kotor di aplikasi ini sudah bersih dari pengembalian dana
     * (lihat const KOTOR), jadi yang tersisa dikurangkan hanyalah potongan
     * dan diskon yang ditanggung penjual.
     *
     * Biaya platform (komisi, layanan, ongkir) SENGAJA tidak dikurangkan di
     * sini: itu biaya menjual, bukan pengurang penjualan. Marjin karena itu
     * berarti "berapa persen dari penjualan bersih yang benar-benar jadi
     * laba", ukuran yang sama dengan yang dipakai di laporan laba rugi pada
     * umumnya.
     *
     * @param array{kotor?:mixed,potongan?:mixed} $r baris hasil query
     */
    public static function penjualanBersih(array $r): float
    {
        return self::rantaiLaba($r)['penjualan_bersih'];
    }

    /**
     * Rantai nilai baku dari harga jual sampai laba, dipakai bersama oleh
     * halaman Laba & Biaya dan simulasi harga supaya keduanya tidak pernah
     * berbeda urutan maupun dasar hitungnya.
     *
     *   harga jual terdaftar
     * - pengembalian dana (bila ada)
     * - potongan & diskon ditanggung penjual
     * = harga setelah dikurang diskon        <- dasar hitung pajak
     * - biaya platform (+ penyesuaian/selisih)
     * = dana diterima bersih
     * - PPN            (persen x harga setelah diskon)
     * - pajak e-commerce (persen x harga setelah diskon)
     * = penjualan bersih                      <- dasar hitung seluruh marjin
     * - HPP
     * = laba kotor
     * - beban operasional (bila ada)
     * = laba usaha
     *
     * Persentase tiap baris diukur terhadap harga jual, KECUALI HPP dan laba
     * yang diukur terhadap penjualan bersih - itulah dasar yang bermakna
     * untuk keduanya.
     *
     * @param array $r butuh: kotor, potongan; opsional: biaya, lain, hpp
     */
    public static function rantaiLaba(array $r, ?float $ppnPersen = null, ?float $pphPersen = null): array
    {
        $ppn = $ppnPersen ?? Tax::PPN_PERSEN;
        $pph = $pphPersen ?? Tax::PPH_PERSEN;

        $harga    = (float) ($r['kotor'] ?? 0);
        $refund   = abs((float) ($r['refund'] ?? 0));
        $potongan = abs((float) ($r['potongan'] ?? 0));
        $biaya    = abs((float) ($r['biaya'] ?? 0));
        $lain     = (float) ($r['lain'] ?? 0);
        $hpp      = abs((float) ($r['hpp'] ?? 0));
        $beban    = abs((float) ($r['beban'] ?? 0));

        $setelahDiskon = $harga - $refund - $potongan;
        // Sebagian laporan sudah punya angka dana diterima langsung dari
        // settlement; itu lebih tepat dipakai daripada dihitung ulang, karena
        // sudah memuat penyesuaian dan selisih pencatatan platform.
        $danaDiterima = array_key_exists('bersih', $r)
            ? (float) $r['bersih']
            : $setelahDiskon - $biaya - $lain;
        $nilaiPpn = $setelahDiskon * $ppn / 100;
        // Laporan yang periodenya melintasi tanggal berlakunya PPh e-commerce
        // sudah menghitung nominalnya per baris di SQL; nilai itu dipakai apa
        // adanya. Menghitung ulang dengan satu tarif akan mengenakan pajak
        // pada bulan yang sebenarnya belum dipungut.
        $nilaiPph = array_key_exists('pph_nominal', $r)
            ? abs((float) $r['pph_nominal'])
            : $setelahDiskon * $pph / 100;
        $penjualan = $danaDiterima - $nilaiPpn - $nilaiPph;
        // Persentase yang ditampilkan mengikuti nominal yang benar-benar
        // dipakai, supaya keterangan tarif tidak bertentangan dengan angkanya.
        if (array_key_exists('pph_nominal', $r)) {
            $pph = $setelahDiskon > 0 ? $nilaiPph / $setelahDiskon * 100 : 0.0;
        }

        $labaKotor = $penjualan - $hpp;

        return [
            'harga'            => $harga,
            'refund'           => $refund,
            'potongan'         => $potongan,
            'setelah_diskon'   => $setelahDiskon,
            'biaya'            => $biaya,
            'lain'             => $lain,
            'dana_diterima'    => $danaDiterima,
            'ppn'              => $nilaiPpn,
            'pph'              => $nilaiPph,
            'penjualan_bersih' => $penjualan,
            'hpp'              => $hpp,
            'laba'             => $labaKotor,
            'beban'            => $beban,
            'laba_usaha'       => $labaKotor - $beban,
            'marjin'           => $penjualan > 0 ? $labaKotor / $penjualan * 100 : null,
            'marjin_usaha'     => $penjualan > 0 ? ($labaKotor - $beban) / $penjualan * 100 : null,
            'ppn_persen'       => $ppn,
            'pph_persen'       => $pph,
        ];
    }

    /** Bangun potongan WHERE + parameter untuk filter standar. */
    private static function filter(string $dateCol, ?string $from, ?string $to, ?string $platform, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $w = ['1=1'];
        $args = [];
        if ($from !== null) {
            $w[] = "{$p}{$dateCol} >= ?";
            $args[] = $from;
        }
        if ($to !== null) {
            $w[] = "{$p}{$dateCol} <= ?";
            $args[] = $to;
        }
        if ($platform !== null) {
            $w[] = "{$p}platform = ?";
            $args[] = $platform;
        }
        return [implode(' AND ', $w), $args];
    }

    /** Status pesanan yang diakui sebagai penjualan pada Laba & Biaya. */
    public const STATUS_DIAKUI = 'selesai';

    /**
     * Penyaring periode untuk Laporan Laba & Biaya dan turunannya.
     *
     * Dasarnya TANGGAL PESANAN, bukan tanggal dana dilepaskan, dan hanya
     * pesanan berstatus selesai yang dihitung. Keduanya dibaca dari salinan
     * di tabel settlement (ord_date, ord_status) yang disegarkan tiap impor.
     *
     * Syarat statusnya sekaligus menyaring baris yang pesanannya belum
     * dikenal: selama berkas pesanannya belum diunggah, salinan itu masih
     * kosong sehingga barisnya tidak ikut dihitung. Menu Monitoring yang
     * menunjukkan periode mana yang berkasnya masih kurang.
     *
     * Syarat terakhir membuang baris yang dibebankan pada pesanan tanpa nilai
     * produk - misalnya biaya yang muncul setelah pesanan batal. Tidak ada
     * produk yang bisa menanggungnya, jadi kalau ikut, ringkasan tidak akan
     * pernah sama dengan tabel laba per produk.
     */
    private static function filterPesanan(?string $from, ?string $to, ?string $platform, string $alias = ''): array
    {
        [$w, $a] = self::filter('ord_date', $from, $to, $platform, $alias);
        $p = $alias !== '' ? $alias . '.' : '';
        return [
            $w . " AND {$p}ord_status = " . Db::conn()->quote(self::STATUS_DIAKUI)
               . " AND {$p}ord_ada_produk = 1",
            $a,
        ];
    }

    /**
     * Penyaring satu bulan pada tabel settlement, dasar tanggal pesanan.
     *
     * Dipakai halaman HPP dan rincian produk supaya angkanya sebanding dengan
     * Laba & Biaya. Bulannya dijadikan rentang tanggal, bukan DATE_FORMAT,
     * supaya index (platform, ord_status, ord_date) tetap terpakai - dengan
     * fungsi di sisi kiri, MariaDB memindai seluruh tabel.
     */
    private static function filterBulanPesanan(?string $ym, ?string $platform): array
    {
        $w = 'ord_status = ' . Db::conn()->quote(self::STATUS_DIAKUI) . ' AND ord_ada_produk = 1';
        $a = [];
        if ($ym !== null) {
            $awal = $ym . '-01';
            $w .= ' AND ord_date >= ? AND ord_date <= ?';
            $a[] = $awal;
            $a[] = date('Y-m-t', strtotime($awal) ?: time());
        }
        if ($platform !== null) {
            $w .= ' AND platform = ?';
            $a[] = $platform;
        }
        return [$w, $a];
    }

    /** Rentang tanggal data yang tersedia. */
    public static function dataRange(): array
    {
        $o = Db::one('SELECT MIN(order_date) a, MAX(order_date) b FROM orders') ?? [];
        $s = Db::one('SELECT MIN(settlement_date) a, MAX(settlement_date) b FROM settlements') ?? [];
        return [
            'order_from'      => $o['a'] ?? null,
            'order_to'        => $o['b'] ?? null,
            'settlement_from' => $s['a'] ?? null,
            'settlement_to'   => $s['b'] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    public static function salesKpi(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        $row = Db::one(
            "SELECT
                COUNT(*)                                                        AS total_pesanan,
                SUM(status_norm='selesai')                                      AS pesanan_selesai,
                SUM(status_norm='batal')                                        AS pesanan_batal,
                SUM(status_norm='retur')                                        AS pesanan_retur,
                COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet,
                COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_before END),0) AS omzet_sebelum_diskon,
                COALESCE(SUM(CASE WHEN status_norm='selesai' THEN total_qty END),0)            AS qty,
                COUNT(DISTINCT buyer_username)                                  AS pembeli
             FROM orders WHERE {$w}",
            $a
        ) ?? [];

        $selesai = (int) ($row['pesanan_selesai'] ?? 0);
        $row['aov'] = $selesai > 0 ? (float) $row['omzet'] / $selesai : 0.0;
        $row['cancel_rate'] = ((int) ($row['total_pesanan'] ?? 0)) > 0
            ? (int) $row['pesanan_batal'] / (int) $row['total_pesanan'] * 100
            : 0.0;
        return $row;
    }

    public static function settlementKpi(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        return Db::one(
            "SELECT
                COUNT(*)                          AS total_trx,
                COALESCE(SUM(" . self::KOTOR . "),0) AS pendapatan_kotor,
                COALESCE(SUM(total_fee),0)        AS total_biaya,
                COALESCE(SUM(net_amount),0)       AS dana_diterima,
                COALESCE(SUM(refund_amount),0)    AS pengembalian
             FROM settlements WHERE {$w}",
            $a
        ) ?? [];
    }

    /** Deret harian untuk grafik. */
    public static function dailySeries(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT order_date AS tanggal,
                    COUNT(*) AS pesanan,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet
             FROM orders
             WHERE {$w} AND order_date IS NOT NULL
             GROUP BY order_date ORDER BY order_date",
            $a
        );
    }

    public static function channelSplit(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT platform, COALESCE(channel,'-') AS channel,
                    COUNT(*) AS pesanan,
                    SUM(status_norm='selesai') AS selesai,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet
             FROM orders WHERE {$w}
             GROUP BY platform, channel ORDER BY omzet DESC",
            $a
        );
    }

    // -----------------------------------------------------------------
    // Akuntansi / keuangan
    // -----------------------------------------------------------------

    /** Ringkasan laba kotor berdasarkan settlement. */
    public static function pnl(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);
        $head = Db::one(
            "SELECT
                COUNT(*) AS trx,
                COALESCE(SUM(" . self::KOTOR . "),0) AS pendapatan_kotor,
                COALESCE(SUM(discount_seller),0)   AS diskon_penjual,
                COALESCE(SUM(refund_amount),0)     AS pengembalian,
                COALESCE(SUM(adjustment_amount),0) AS penyesuaian,
                COALESCE(SUM(total_fee),0)         AS total_biaya,
                COALESCE(SUM(net_amount),0)        AS dana_diterima,
                -- PPh e-commerce dihitung per baris karena baru berlaku sejak
                -- tanggal tertentu; laporan yang mencakup sebelum dan sesudah
                -- tanggal itu jadi benar tanpa perlu dipecah dua.
                COALESCE(" . self::pphSql('ord_date', self::KOTOR . ' + total_potongan') . ",0) AS pph_nominal
             FROM settlements WHERE {$w}",
            $a
        ) ?? [];

        return ['ringkasan' => $head, 'kategori' => self::categoryTotals($from, $to, $platform)];
    }

    /**
     * Total per kategori akuntansi.
     *
     * Dibaca dari kolom fee_* pada tabel `settlements`, bukan dengan
     * menjumlah ulang `settlement_fees`. Nilainya identik (kolom itu diisi
     * dari rincian yang sama saat import) tetapi tabelnya jauh lebih kecil,
     * sehingga halaman tetap ringan saat data sudah menumpuk.
     *
     * @return list<array{fee_category:string,total:float}>
     */
    public static function categoryTotals(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);

        $select = [];
        foreach (Profiles::FEE_CATEGORIES as $cat) {
            $select[] = "COALESCE(SUM(fee_{$cat}),0) AS `c_{$cat}`";
        }
        $select[] = 'COALESCE(SUM(total_potongan),0)    AS `c_potongan`';
        // Pengembalian sengaja tidak masuk daftar kategori: nilainya sudah
        // dipotong dari pendapatan kotor, jadi menampilkannya lagi di sini
        // akan menghitungnya dua kali. Rinciannya ada di halaman Pengembalian.
        $select[] = 'COALESCE(SUM(adjustment_amount),0) AS `c_penyesuaian`';

        $row = Db::one('SELECT ' . implode(', ', $select) . " FROM settlements WHERE {$w}", $a) ?? [];

        $out = [];
        foreach ($row as $key => $val) {
            $v = (float) $val;
            if ($v === 0.0) {
                continue;
            }
            $out[] = ['fee_category' => substr($key, 2), 'total' => $v];
        }
        usort($out, static fn(array $x, array $y): int => $x['total'] <=> $y['total']);
        return $out;
    }

    /**
     * Jembatan angka per platform, selalu berimbang:
     *   pendapatan kotor + potongan + biaya platform
     *   + penyesuaian + selisih pencatatan = dana diterima bersih
     *
     * Pendapatan kotor memakai kolom paling kotor yang tersedia
     * ("Subtotal sebelum diskon" untuk Tokopedia, "Harga Asli Produk" untuk
     * Shopee) DIKURANGI pengembalian, sehingga diskon penjual tampil sebagai
     * baris tersendiri dan kedua platform bisa dibandingkan setara.
     *
     * "Dana diterima bersih" diambil dari kolom resmi platform, sehingga baris
     * selisih memperlihatkan secara jujur bila laporan platform sendiri tidak
     * bulat.
     */
    public static function bridge(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);
        $rows = Db::all(
            "SELECT platform,
                    COALESCE(SUM(" . self::KOTOR . "),0) AS kotor,
                    COALESCE(SUM(total_potongan),0)    AS potongan,
                    COALESCE(SUM(total_fee),0)         AS biaya,
                    COALESCE(SUM(adjustment_amount),0) AS penyesuaian,
                    COALESCE(SUM(net_amount),0)        AS bersih
             FROM settlements WHERE {$w} GROUP BY platform",
            $a
        );

        $out = [];
        foreach ($rows as $r) {
            $subtotal = (float) $r['kotor'] + (float) $r['potongan']
                + (float) $r['biaya'] + (float) $r['penyesuaian'];
            $out[] = [
                'platform'    => (string) $r['platform'],
                'kotor'       => (float) $r['kotor'],
                'potongan'    => (float) $r['potongan'],
                'biaya'       => (float) $r['biaya'],
                'penyesuaian' => (float) $r['penyesuaian'],
                'selisih'     => (float) $r['bersih'] - $subtotal,
                'bersih'      => (float) $r['bersih'],
            ];
        }
        return $out;
    }

    /** Jumlahkan seluruh baris jembatan menjadi satu ringkasan. */
    public static function bridgeTotals(array $bridge): array
    {
        $t = ['kotor' => 0.0, 'potongan' => 0.0, 'biaya' => 0.0,
              'penyesuaian' => 0.0, 'selisih' => 0.0, 'bersih' => 0.0];
        foreach ($bridge as $b) {
            foreach ($t as $k => $_) {
                $t[$k] += (float) ($b[$k] ?? 0);
            }
        }
        return $t;
    }

    /**
     * Rincian per komponen biaya (nama kolom asli dari platform).
     *
     * Pengelompokan memakai fee_code (kode pendek), bukan fee_label yang
     * panjang - satu kode selalu punya satu label, jadi hasilnya sama tetapi
     * jauh lebih murah. Labelnya diambil dari tabel kamus yang kecil.
     */
    public static function feeDetail(?string $from, ?string $to, ?string $platform, bool $includeRincian = false): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);
        $extra = $includeRincian ? '' : " AND fee_category <> 'rincian'";

        $rows = Db::all(
            "SELECT platform, fee_code, fee_category,
                    SUM(amount) AS total, COUNT(*) AS jumlah_transaksi
             FROM settlement_fees
             WHERE {$w}{$extra}
             GROUP BY platform, fee_code, fee_category
             ORDER BY ABS(SUM(amount)) DESC",
            $a
        );
        if ($rows === []) {
            return [];
        }

        $labels = [];
        foreach (Db::all('SELECT platform, fee_code, fee_label FROM fee_dictionary') as $d) {
            $labels[$d['platform'] . '|' . $d['fee_code']] = $d['fee_label'];
        }
        foreach ($rows as &$r) {
            $r['fee_label'] = $labels[$r['platform'] . '|' . $r['fee_code']]
                ?? str_replace('_', ' ', (string) $r['fee_code']);
        }
        return $rows;
    }

    /** Arus settlement per bulan - untuk jurnal / rekap bulanan. */
    public static function monthlySettlement(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);
        $rows = Db::all(
            "SELECT DATE_FORMAT(ord_date,'%Y-%m') AS bulan, platform,
                    COUNT(*) AS trx,
                    COALESCE(SUM(" . self::KOTOR . "),0) AS pendapatan_kotor,
                    COALESCE(SUM(total_potongan),0)    AS potongan,
                    COALESCE(SUM(total_fee),0)         AS total_biaya,
                    COALESCE(SUM(adjustment_amount),0) AS penyesuaian,
                    COALESCE(SUM(net_amount),0)        AS dana_diterima
             FROM settlements
             WHERE {$w} AND ord_date IS NOT NULL
             GROUP BY bulan, platform ORDER BY bulan DESC, platform",
            $a
        );
        foreach ($rows as &$r) {
            $r['selisih'] = (float) $r['dana_diterima'] - ((float) $r['pendapatan_kotor']
                + (float) $r['potongan']
                + (float) $r['total_biaya'] + (float) $r['penyesuaian']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Tanggal selesainya pesanan, dipilih dari kolom yang memang terisi.
     *
     * Shopee mengisi "Waktu Pesanan Selesai" (completed_at), Tokopedia
     * mengisi "Delivered Time" (delivered_at) - tidak ada satu kolom yang
     * terisi di kedua platform. Dari tanggal inilah hitungan pencairan
     * dimulai, bukan dari tanggal pesanan dibuat.
     */
    private const TGL_SELESAI = 'DATE(COALESCE(o.completed_at, o.delivered_at, o.shipped_at, o.order_date))';

    /**
     * Rentang tanggal settlement yang benar-benar dimiliki tiap platform.
     *
     * Dipakai sebagai batas penilaian "dana belum dilepas". Tanpa batas ini,
     * seluruh pesanan dari periode yang berkas penghasilannya memang belum
     * pernah diunggah akan tampak seperti dana tertahan - padahal yang belum
     * ada hanyalah datanya.
     *
     * @return array<string,array{awal:string,akhir:string}>
     */
    public static function cakupanSettlement(): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT platform, MIN(settlement_date) awal, MAX(settlement_date) akhir
               FROM settlements WHERE settlement_date IS NOT NULL GROUP BY platform'
        ) as $r) {
            $out[(string) $r['platform']] = [
                'awal'  => (string) $r['awal'],
                'akhir' => (string) $r['akhir'],
            ];
        }
        return $out;
    }

    /**
     * Pesanan selesai yang dananya belum dilepas, dikelompokkan menurut umur.
     *
     * Platform mencairkan dana beberapa hari setelah pesanan selesai, jadi
     * pesanan yang baru selesai kemarin memang belum boleh dianggap
     * bermasalah. Yang perlu dilihat adalah yang sudah melewati tenggat itu.
     *
     * @return array<int,array{platform:string,kelompok:string,urut:int,jumlah:int,nilai:float}>
     */
    public static function danaBelumDilepas(int $batasHari = 7, ?string $platform = null): array
    {
        $tgl = self::TGL_SELESAI;
        $a = [$batasHari, $batasHari * 2];
        $w = '';
        if ($platform !== null && $platform !== '') {
            $w = ' AND o.platform = ?';
            $a[] = $platform;
        }

        return Db::all(
            "SELECT o.platform,
                    CASE WHEN {$tgl} > c.akhir THEN 3
                         WHEN DATEDIFF(CURDATE(), {$tgl}) <= ? THEN 0
                         WHEN DATEDIFF(CURDATE(), {$tgl}) <= ? THEN 1
                         ELSE 2 END AS urut,
                    COUNT(*) AS jumlah,
                    COALESCE(SUM(o.items_subtotal_after), 0) AS nilai,
                    MIN({$tgl}) AS paling_lama
               FROM orders o
               LEFT JOIN settlements s ON s.platform = o.platform AND s.order_id = o.order_id
               JOIN (SELECT platform, MIN(settlement_date) awal, MAX(settlement_date) akhir
                       FROM settlements WHERE settlement_date IS NOT NULL GROUP BY platform) c
                 ON c.platform = o.platform
              WHERE o.status_norm = 'selesai' AND s.id IS NULL
                AND {$tgl} >= c.awal {$w}
              GROUP BY o.platform, urut
              ORDER BY o.platform, urut",
            $a
        );
    }

    /**
     * Daftar pesanannya, yang paling lama menunggu lebih dulu.
     *
     * @return array<int,array>
     */
    public static function danaBelumDilepasRinci(
        int $batasHari = 7,
        ?string $platform = null,
        int $limit = 300
    ): array {
        $tgl = self::TGL_SELESAI;
        $a = [$batasHari];
        $w = '';
        if ($platform !== null && $platform !== '') {
            $w = ' AND o.platform = ?';
            $a[] = $platform;
        }

        return Db::all(
            "SELECT o.platform, o.order_id, o.order_date, o.status_raw,
                    {$tgl} AS tgl_selesai,
                    DATEDIFF(CURDATE(), {$tgl}) AS umur,
                    o.items_subtotal_after AS nilai
               FROM orders o
               LEFT JOIN settlements s ON s.platform = o.platform AND s.order_id = o.order_id
               JOIN (SELECT platform, MIN(settlement_date) awal, MAX(settlement_date) akhir
                       FROM settlements WHERE settlement_date IS NOT NULL GROUP BY platform) c
                 ON c.platform = o.platform
              WHERE o.status_norm = 'selesai' AND s.id IS NULL
                AND {$tgl} BETWEEN c.awal AND c.akhir
                AND DATEDIFF(CURDATE(), {$tgl}) > ? {$w}
              ORDER BY umur DESC, o.order_id
              LIMIT {$limit}",
            $a
        );
    }

    /**
     * Rekonsiliasi: pesanan selesai yang belum ada catatan settlement-nya.
     * Ini adalah "piutang" ke platform - uang yang belum cair.
     */
    public static function unsettledOrders(?string $from, ?string $to, ?string $platform, int $limit = 200): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform, 'o');
        return Db::all(
            "SELECT o.platform, o.order_id, o.order_date, o.status_raw,
                    o.items_subtotal_after AS nilai_pesanan, o.order_amount
             FROM orders o
             LEFT JOIN settlements s ON s.platform = o.platform AND s.order_id = o.order_id
             WHERE {$w} AND o.status_norm = 'selesai' AND s.id IS NULL
             ORDER BY o.order_date DESC
             LIMIT {$limit}",
            $a
        );
    }

    public static function reconciliationSummary(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform, 'o');
        return Db::all(
            "SELECT o.platform,
                    COUNT(*) AS pesanan_selesai,
                    SUM(s.id IS NOT NULL) AS sudah_settle,
                    SUM(s.id IS NULL)     AS belum_settle,
                    COALESCE(SUM(CASE WHEN s.id IS NULL THEN o.items_subtotal_after END),0) AS nilai_belum_settle,
                    COALESCE(SUM(o.items_subtotal_after),0) AS nilai_total
             FROM orders o
             LEFT JOIN settlements s ON s.platform = o.platform AND s.order_id = o.order_id
             WHERE {$w} AND o.status_norm = 'selesai'
             GROUP BY o.platform",
            $a
        );
    }

    /** Settlement yang pesanannya belum pernah diupload (file order belum masuk). */
    public static function orphanSettlements(?string $from, ?string $to, ?string $platform, int $limit = 200): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform, 's');
        return Db::all(
            "SELECT s.platform, s.order_id, s.settlement_date, s.trx_type,
                    s.gross_amount, s.total_fee, s.net_amount
             FROM settlements s
             LEFT JOIN orders o ON o.platform = s.platform AND o.order_id = s.order_id
             WHERE {$w} AND o.id IS NULL
             ORDER BY s.settlement_date DESC
             LIMIT {$limit}",
            $a
        );
    }

    /**
     * Penarikan dana ke rekening bank.
     *
     * Hanya baris bernilai MINUS yang diambil. Tabel ini menampung seluruh
     * mutasi saldo platform, termasuk dana masuk dari penjualan - kalau
     * semuanya ikut, angkanya bukan lagi "penarikan" melainkan mutasi saldo,
     * dan totalnya nyaris saling meniadakan.
     */
    public static function withdrawals(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('withdraw_date', $from, $to, $platform);
        return Db::all(
            "SELECT platform, withdraw_date, COUNT(*) AS jumlah, SUM(amount) AS total, status
             FROM withdrawals WHERE {$w} AND amount < 0
             GROUP BY platform, withdraw_date, status
             ORDER BY withdraw_date DESC",
            $a
        );
    }

    /**
     * Laba bersih per produk.
     *
     * Settlement diberikan platform per PESANAN, bukan per produk. Karena itu
     * nilai settlement dibagi ke tiap baris produk secara proporsional terhadap
     * nilai kotor produk dalam pesanan tersebut:
     *
     *     porsi produk = subtotal produk sebelum diskon
     *                    / subtotal seluruh produk pesanan sebelum diskon
     *
     * Angkanya alokasi, bukan angka resmi platform per produk - platform memang
     * tidak menyediakannya. Totalnya tetap sama dengan total settlement pesanan
     * yang ikut teralokasi.
     *
     * Hanya pesanan yang berkas pesanan DAN berkas penghasilannya sudah diunggah
     * yang bisa dihitung; sisanya dilaporkan lewat productNetCoverage().
     */
    public static function productNet(?string $from, ?string $to, ?string $platform, int $limit = 100, string $sort = 'bersih'): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);
        $order = match ($sort) {
            'kotor'  => 'kotor',
            'qty'    => 'qty',
            'marjin' => 'marjin',
            default  => 'bersih',
        };

        // Urutan join sengaja dimulai dari settlement yang sudah tersaring
        // tanggal (paling sedikit barisnya), lalu ke orders lewat kunci unik,
        // baru ke baris produknya. Kalau dibalik, MariaDB memindai seluruh
        // tabel order_items dan halaman jadi lambat saat data menumpuk.
        return Db::all(
            "SELECT i.platform,
                    COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COUNT(DISTINCT i.order_id) AS pesanan,
                    SUM(i.qty)                 AS qty,
                    SUM(st.gross_amount   * i.subtotal_before_disc / o.items_subtotal_before) AS kotor,
                    SUM(st.total_potongan * i.subtotal_before_disc / o.items_subtotal_before) AS potongan,
                    SUM(st.total_fee      * i.subtotal_before_disc / o.items_subtotal_before) AS biaya,
                    SUM(st.net_amount     * i.subtotal_before_disc / o.items_subtotal_before) AS bersih,
                    CASE WHEN SUM(st.gross_amount * i.subtotal_before_disc / o.items_subtotal_before) > 0
                         THEN SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before)
                              / SUM(st.gross_amount * i.subtotal_before_disc / o.items_subtotal_before) * 100
                         ELSE NULL END AS marjin
             FROM (
                 SELECT platform, order_id,
                        SUM(gross_amount)      AS gross_amount,
                        SUM(total_potongan)    AS total_potongan,
                        SUM(refund_amount)     AS refund_amount,
                        SUM(total_fee)         AS total_fee,
                        SUM(net_amount)        AS net_amount
                 FROM settlements
                 WHERE {$w}
                 GROUP BY platform, order_id
             ) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             GROUP BY i.platform, produk
             ORDER BY {$order} DESC
             LIMIT {$limit}",
            $a
        );
    }

    /**
     * Berapa bagian settlement yang berhasil dialokasikan ke produk.
     * Sisanya = pesanan yang berkas pesanannya belum diunggah.
     */
    public static function productNetCoverage(?string $from, ?string $to, ?string $platform): array
    {
        // Satu kali baca settlements dengan LEFT JOIN ke orders; tidak perlu
        // sub-query beragregasi supaya tetap ringan saat data menumpuk.
        [$w, $a] = self::filterPesanan($from, $to, $platform, 's');
        $row = Db::one(
            "SELECT COUNT(DISTINCT s.platform, s.order_id) AS total_pesanan,
                    COALESCE(SUM(s.net_amount),0)          AS total_bersih,
                    COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN CONCAT(s.platform,'|',s.order_id) END)
                                                           AS covered_pesanan,
                    COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN s.net_amount ELSE 0 END),0)
                                                           AS covered_bersih
             FROM settlements s
             LEFT JOIN orders o ON o.platform = s.platform AND o.order_id = s.order_id
                               AND o.items_subtotal_before > 0
             WHERE {$w}",
            $a
        ) ?? [];

        return [
            'total_pesanan'   => (int) ($row['total_pesanan'] ?? 0),
            'total_bersih'    => (float) ($row['total_bersih'] ?? 0),
            'covered_pesanan' => (int) ($row['covered_pesanan'] ?? 0),
            'covered_bersih'  => (float) ($row['covered_bersih'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------
    // HPP & beban operasional
    // -----------------------------------------------------------------

    /**
     * HPP dicocokkan memakai BULAN PESANAN, sama seperti seluruh halaman
     * Laba & Biaya, supaya biaya dan pendapatannya berada pada periode yang
     * sama. Dipakai bersama oleh laporan laba per produk dan pemantauan HPP.
     */
    private static function costJoinSql(): string
    {
        return "LEFT JOIN product_cost pc
                    ON pc.cost_key = i.cost_key AND pc.period_ym = st.period_ym";
    }

    /**
     * Potongan SQL untuk PPh e-commerce yang baru berlaku sejak tanggal
     * tertentu.
     *
     * @param string $kolomTanggal kolom tanggal acuan pada baris tersebut
     * @param string $dasar        ekspresi dasar pengenaan (setelah diskon)
     */
    private static function pphSql(string $kolomTanggal, string $dasar): string
    {
        $mulai = Db::conn()->quote(Tax::PPH_MULAI);
        $tarif = Tax::PPH_PERSEN / 100;
        return "SUM(CASE WHEN {$kolomTanggal} >= {$mulai} THEN ({$dasar}) * {$tarif} ELSE 0 END)";
    }

    /** Sub-query settlement per pesanan + bulan periodenya. */
    private static function settlementPerOrderSql(string $where): string
    {
        // gross_amount di sini sudah bersih dari pengembalian (lihat const
        // KOTOR), sehingga seluruh laporan per produk yang memakai sub-query
        // ini ikut bersih tanpa perlu mengurangkan refund lagi.
        //
        // period_ym memakai BULAN PESANAN, sejalan dengan dasar periode
        // Laba & Biaya. HPP per bulan dicocokkan lewat kolom ini, jadi kalau
        // dasarnya berbeda, HPP bulan lain yang terpakai dan marjinnya salah.
        // Untuk baris yang pesanannya belum dikenal, tanggal settlement
        // dipakai sebagai cadangan supaya laporan lintas periode tetap punya
        // bulan - baris itu sendiri sudah tersaring oleh syarat statusnya.
        return "SELECT platform, order_id,
                       DATE_FORMAT(COALESCE(MAX(ord_date), MAX(settlement_date)),'%Y-%m') AS period_ym,
                       COALESCE(MAX(ord_date), MAX(settlement_date)) AS period_awal,
                       SUM(" . self::KOTOR . ") AS gross_amount,
                       SUM(total_potongan)      AS total_potongan,
                       SUM(total_fee)           AS total_fee,
                       SUM(net_amount)          AS net_amount
                FROM settlements
                WHERE {$where}
                GROUP BY platform, order_id";
    }

    /**
     * Laba per produk: alokasi settlement dikurangi pajak, lalu dikurangi HPP.
     *
     * Rantai nilainya sengaja sama persis dengan Reports::rantaiLaba() dan
     * halaman Simulasi Harga:
     *
     *     setelah diskon  = kotor + potongan            (potongan bernilai minus)
     *     PPN             = setelah diskon x 11%
     *     PPh e-commerce  = setelah diskon x 0,5%
     *     penjualan bersih= dana diterima - PPN - PPh
     *     laba            = penjualan bersih - HPP
     *
     * Ditulis sebagai potongan SQL bernama supaya rumus yang panjang ini
     * hanya ada di satu tempat - MariaDB tidak mengizinkan alias SELECT
     * dipakai ulang di baris SELECT lain, jadi tanpa ini rumusnya harus
     * disalin berkali-kali dan gampang menyimpang satu sama lain.
     */
    public static function productProfit(?string $from, ?string $to, ?string $platform, int $limit = 100, string $sort = 'laba'): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);

        // Marjin bisa NULL (produk tanpa penjualan bersih positif); baris
        // seperti itu didorong ke belakang, bukan menempati puncak daftar
        // "marjin terburuk" hanya karena nilainya kosong.
        $order = match ($sort) {
            'kotor'      => 'kotor DESC',
            'kotor_asc'  => 'kotor ASC',
            'qty'        => 'qty DESC',
            'qty_asc'    => 'qty ASC',
            'bersih'     => 'bersih DESC',
            'bersih_asc' => 'bersih ASC',
            'marjin'     => 'marjin_laba IS NULL, marjin_laba DESC',
            'marjin_asc' => 'marjin_laba IS NULL, marjin_laba ASC',
            'laba_asc'   => 'laba ASC',
            default      => 'laba DESC',
        };
        $sub = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();

        $porsi     = 'i.subtotal_before_disc / o.items_subtotal_before';
        $kotor     = "SUM(st.gross_amount * {$porsi})";
        $potongan  = "SUM(st.total_potongan * {$porsi})";
        $biaya     = "SUM(st.total_fee * {$porsi})";
        $dana      = "SUM(st.net_amount * {$porsi})";
        $hpp       = 'SUM(i.qty * COALESCE(pc.cost_per_unit, 0))';
        $setelah   = "({$kotor} + {$potongan})";
        $ppn       = "({$setelah} * " . (Tax::PPN_PERSEN / 100) . ')';
        // PPh e-commerce baru dipungut sejak tanggal berlakunya, jadi
        // syaratnya per baris - bukan satu tarif untuk seluruh rentang.
        // Laporan yang mencakup Juli dan Agustus sekaligus jadi benar tanpa
        // perlu dipecah dua.
        $pph       = '(' . self::pphSql('st.period_awal', "(st.gross_amount + st.total_potongan) * {$porsi}") . ')';
        $penjualan = "({$dana} - {$ppn} - {$pph})";
        $laba      = "({$penjualan} - {$hpp})";

        // Hasil pengelompokan dibungkus sekali lagi supaya pengurutan boleh
        // memakai nama kolomnya di dalam ekspresi - MariaDB menolak alias yang
        // berisi fungsi agregat dipakai begitu (mis. "marjin_laba IS NULL").
        // Jumlah barisnya sudah sedikit di titik ini, jadi tidak membebani.
        return Db::all(
            "SELECT * FROM (
                SELECT i.platform,
                       COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                       COUNT(DISTINCT i.order_id) AS pesanan,
                       SUM(i.qty)                 AS qty,
                       {$kotor}     AS kotor,
                       {$potongan}  AS potongan,
                       {$biaya}     AS biaya,
                       {$dana}      AS bersih,
                       -{$ppn}      AS ppn,
                       -{$pph}      AS pph,
                       {$penjualan} AS penjualan,
                       {$hpp}       AS hpp,
                       SUM(CASE WHEN pc.id IS NULL THEN i.qty ELSE 0 END) AS qty_tanpa_hpp,
                       {$laba}      AS laba,
                       CASE WHEN {$penjualan} > 0 THEN {$laba} / {$penjualan} * 100
                            ELSE NULL END AS marjin_laba
                  FROM ({$sub}) st
                  STRAIGHT_JOIN orders o
                      ON o.platform = st.platform AND o.order_id = st.order_id
                     AND o.items_subtotal_before > 0
                  STRAIGHT_JOIN order_items i ON i.order_pk = o.id
                  {$join}
                 GROUP BY i.platform, produk
             ) p
             ORDER BY {$order}
             LIMIT {$limit}",
            $a
        );
    }

    /** Ringkasan HPP untuk seluruh rentang: total HPP + qty yang belum punya HPP. */
    public static function costSummary(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filterPesanan($from, $to, $platform);
        $sub = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();

        $row = Db::one(
            "SELECT COALESCE(SUM(i.qty * COALESCE(pc.cost_per_unit,0)),0)         AS hpp,
                    COALESCE(SUM(i.qty),0)                                        AS qty_total,
                    COALESCE(SUM(CASE WHEN pc.id IS NULL THEN i.qty ELSE 0 END),0) AS qty_tanpa_hpp,
                    COUNT(DISTINCT CASE WHEN pc.id IS NULL THEN i.cost_key END)   AS produk_tanpa_hpp
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}",
            $a
        ) ?? [];

        return [
            'hpp'              => (float) ($row['hpp'] ?? 0),
            'qty_total'        => (int) ($row['qty_total'] ?? 0),
            'qty_tanpa_hpp'    => (int) ($row['qty_tanpa_hpp'] ?? 0),
            'produk_tanpa_hpp' => (int) ($row['produk_tanpa_hpp'] ?? 0),
        ];
    }

    /**
     * Pemantauan: produk yang terjual pada suatu bulan tapi HPP-nya belum diisi.
     * Diurutkan dari yang nilainya paling besar supaya yang paling berpengaruh
     * ke laba dikerjakan lebih dulu.
     */
    public static function missingCosts(?string $ym, int $limit = 300): array
    {
        [$w, $a] = self::filterBulanPesanan($ym, null);
        $sub = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();

        return Db::all(
            "SELECT st.period_ym,
                    COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COALESCE(i.variation,'') AS variasi,
                    i.cost_key,
                    SUM(i.qty) AS qty,
                    SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before) AS nilai_bersih
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}
             WHERE pc.id IS NULL
             GROUP BY st.period_ym, produk, variasi, i.cost_key
             ORDER BY nilai_bersih DESC
             LIMIT {$limit}",
            $a
        );
    }

    /** Ringkasan kelengkapan HPP per bulan. */
    public static function costCoverageByMonth(): array
    {
        $sub = self::settlementPerOrderSql('ord_status = ' . Db::conn()->quote(self::STATUS_DIAKUI));
        $join = self::costJoinSql();

        return Db::all(
            "SELECT st.period_ym,
                    COUNT(DISTINCT i.cost_key)                                  AS produk,
                    COUNT(DISTINCT CASE WHEN pc.id IS NULL THEN i.cost_key END) AS produk_tanpa_hpp,
                    SUM(i.qty)                                                  AS qty,
                    SUM(CASE WHEN pc.id IS NULL THEN i.qty ELSE 0 END)          AS qty_tanpa_hpp,
                    SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before) AS bersih,
                    SUM(i.qty * COALESCE(pc.cost_per_unit,0))                   AS hpp
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}
             GROUP BY st.period_ym
             ORDER BY st.period_ym DESC"
        );
    }

    /** Marjin sehat untuk produk kopi bubuk/biji: dipakai sebagai nilai awal. */
    public const MARJIN_MIN = 60.0;
    public const MARJIN_MAX = 80.0;

    /**
     * Uji kewajaran HPP untuk produk yang HPP-nya SUDAH diisi.
     *
     * Marjin laba = (dana bersih - HPP) / dana bersih, dan yang dianggap wajar
     * adalah sebuah RENTANG, bukan sekadar batas atas. Untuk kopi bubuk/biji
     * marjin sehat berkisar 60-80%:
     *
     *   - di BAWAH batas bawah  -> marjin terlalu tipis; harga jual kekecilan
     *     atau HPP-nya kemahalan, dua-duanya perlu ditindaklanjuti;
     *   - di ATAS batas atas    -> HPP kemungkinan terlalu kecil atau belum
     *     lengkap (ongkos kemasan, susut, dan sejenisnya belum dihitung);
     *   - mendekati 100%        -> HPP nyaris nol dibanding pendapatan, hampir
     *     pasti salah isi;
     *   - negatif               -> HPP melebihi pendapatan (jual rugi).
     *
     * Ketiga ambang bisa diatur dari halaman karena tiap jenis produk berbeda.
     */
    public static function costMarginCheck(
        ?string $ym,
        ?string $platform,
        float $minPct = self::MARJIN_MIN,
        float $maxPct = self::MARJIN_MAX,
        float $badPct = 100.0
    ): array {
        [$w, $a] = self::filterBulanPesanan($ym, $platform);
        $sub = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();

        $rows = Db::all(
            "SELECT st.period_ym,
                    COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COALESCE(i.variation,'') AS variasi,
                    -- Tetap sama untuk seluruh baris dalam satu grup: kunci HPP
                    -- dihitung dari nama+variasi yang sudah dinormalkan.
                    MAX(i.cost_key)       AS cost_key,
                    MAX(pc.cost_per_unit) AS hpp_unit,
                    SUM(i.qty)            AS qty,
                    SUM(st.gross_amount   * i.subtotal_before_disc / o.items_subtotal_before) AS kotor,
                    SUM(st.total_potongan * i.subtotal_before_disc / o.items_subtotal_before) AS potongan,
                    SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before) AS bersih,
                    SUM(i.qty * pc.cost_per_unit) AS hpp
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}
             WHERE pc.id IS NOT NULL
             GROUP BY st.period_ym, produk, variasi
             HAVING qty > 0",
            $a
        );

        foreach ($rows as &$r) {
            $bersih = (float) $r['bersih'];
            $hpp    = (float) $r['hpp'];
            $qty    = (int) $r['qty'];
            $c      = self::rantaiLaba($r);
            $laba   = $c['laba'];
            $jual   = $c['penjualan_bersih'];

            $r['laba'] = $laba;
            $r['penjualan_bersih'] = $jual;
            $r['bersih_unit'] = $qty > 0 ? $bersih / $qty : 0.0;
            $r['marjin'] = $c['marjin'];

            // Dibandingkan pada ketelitian yang sama dengan yang ditampilkan
            // (1 desimal). Sejak nilai 0 pada template diabaikan, HPP selalu
            // lebih besar dari nol sehingga marjin tidak pernah persis 100%;
            // tanpa pembulatan ini ambang 100% tidak akan pernah tercapai
            // walau HPP-nya cuma Rp 1.
            $m = $r['marjin'] === null ? null : round($r['marjin'], 1);
            if ($jual <= 0) {
                $r['status'] = 'periksa';
                $r['alasan'] = 'Penjualan bersih nol atau negatif';
            } elseif ($laba < 0) {
                $r['status'] = 'rugi';
                $r['alasan'] = 'HPP lebih besar dari pendapatan bersih (jual rugi)';
            } elseif ($m >= $badPct) {
                $r['status'] = 'parah';
                $r['alasan'] = 'HPP nyaris nol dibanding pendapatan - hampir pasti salah isi';
            } elseif ($m > $maxPct) {
                $r['status'] = 'periksa';
                $r['alasan'] = 'Marjin di atas rentang wajar, HPP kemungkinan terlalu kecil atau belum lengkap';
            } elseif ($m < $minPct) {
                $r['status'] = 'tipis';
                $r['alasan'] = 'Marjin di bawah rentang wajar - harga jual terlalu rendah atau HPP terlalu tinggi';
            } else {
                $r['status'] = 'wajar';
                $r['alasan'] = '';
            }
        }
        unset($r);

        usort($rows, static function (array $x, array $y): int {
            $rank = ['parah' => 0, 'rugi' => 1, 'tipis' => 2, 'periksa' => 3, 'wajar' => 4];
            $c = $rank[$x['status']] <=> $rank[$y['status']];
            return $c !== 0 ? $c : ((float) $y['bersih'] <=> (float) $x['bersih']);
        });

        return $rows;
    }

    // -----------------------------------------------------------------
    // Rincian satu produk
    // -----------------------------------------------------------------

    /**
     * Nama produk + variasi untuk satu kunci HPP.
     *
     * Kunci HPP dihitung dari nama+variasi yang sudah dinormalkan (huruf kecil,
     * spasi dirapikan), jadi beberapa ejaan bisa berbagi satu kunci. Yang
     * dipakai sebagai judul adalah ejaan yang paling sering muncul.
     */
    public static function productIdentity(string $costKey): ?array
    {
        return Db::one(
            "SELECT COALESCE(NULLIF(product_name,''),'(tanpa nama)') AS produk,
                    COALESCE(variation,'')                          AS variasi,
                    MAX(COALESCE(NULLIF(seller_sku,''), ''))         AS sku,
                    COUNT(*)                                        AS baris
             FROM order_items
             WHERE cost_key = ?
             GROUP BY produk, variasi
             ORDER BY baris DESC
             LIMIT 1",
            [$costKey]
        );
    }

    /** Filter settlement untuk laporan per produk. */
    private static function productWhere(?string $ym, ?string $platform): array
    {
        return self::filterBulanPesanan($ym, $platform);
    }

    /**
     * Rincian satu produk, dikelompokkan per platform atau per bulan.
     *
     * Nilai settlement dialokasikan ke baris produk memakai porsi
     * subtotal-sebelum-diskon, sama persis dengan cara laporan laba per produk
     * menghitungnya - jadi angkanya konsisten antar halaman.
     *
     * @param string $dim 'platform' | 'bulan'
     */
    public static function productBreakdown(
        string $costKey,
        ?string $ym,
        ?string $platform,
        string $dim = 'platform'
    ): array {
        [$w, $a] = self::productWhere($ym, $platform);
        $sub  = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();
        $label = $dim === 'bulan' ? 'st.period_ym' : 'i.platform';
        $a[] = $costKey;

        $rows = Db::all(
            "SELECT {$label} AS label,
                    COUNT(DISTINCT CONCAT(i.platform,'|',i.order_id)) AS pesanan,
                    SUM(i.qty)                 AS qty,
                    SUM(i.qty_returned)        AS qty_retur,
                    SUM(st.gross_amount   * i.subtotal_before_disc / o.items_subtotal_before) AS kotor,
                    SUM(st.total_potongan * i.subtotal_before_disc / o.items_subtotal_before) AS potongan,
                    SUM(st.total_fee      * i.subtotal_before_disc / o.items_subtotal_before) AS biaya,
                    SUM(st.net_amount     * i.subtotal_before_disc / o.items_subtotal_before) AS bersih,
                    SUM(i.qty * COALESCE(pc.cost_per_unit,0))          AS hpp,
                    SUM(CASE WHEN pc.id IS NULL THEN i.qty ELSE 0 END) AS qty_tanpa_hpp,
                    MAX(pc.cost_per_unit)                             AS hpp_unit,
                    " . self::pphSql(
                        'st.period_awal',
                        '(st.gross_amount + st.total_potongan) * i.subtotal_before_disc / o.items_subtotal_before'
                    ) . " AS pph_nominal
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}
             WHERE i.cost_key = ?
             GROUP BY label
             ORDER BY bersih DESC",
            $a
        );

        foreach ($rows as &$r) {
            self::addProductDerived($r);
        }
        unset($r);

        return $rows;
    }

    /** Jumlahkan beberapa baris rincian produk menjadi satu baris total. */
    public static function productTotal(array $rows): array
    {
        $t = [
            'pesanan' => 0, 'qty' => 0, 'qty_retur' => 0, 'kotor' => 0.0, 'potongan' => 0.0,
            'biaya' => 0.0, 'bersih' => 0.0, 'hpp' => 0.0,
            'qty_tanpa_hpp' => 0, 'pph_nominal' => 0.0,
        ];
        foreach ($rows as $r) {
            foreach ($t as $k => $_) {
                $t[$k] += $r[$k] ?? 0;
            }
        }
        self::addProductDerived($t);
        return $t;
    }

    /** Kolom turunan yang sama untuk baris rincian maupun totalnya. */
    private static function addProductDerived(array &$r): void
    {
        $bersih = (float) ($r['bersih'] ?? 0);
        $hpp    = (float) ($r['hpp'] ?? 0);
        $qty    = (int) ($r['qty'] ?? 0);

        $c = self::rantaiLaba($r);

        $r['laba']             = $c['laba'];
        $r['penjualan_bersih'] = $c['penjualan_bersih'];
        $r['marjin']           = $c['marjin'];
        $r['bersih_unit']      = $qty > 0 ? $bersih / $qty : 0.0;
        $r['laba_unit']        = $qty > 0 ? $c['laba'] / $qty : 0.0;
    }

    /** Jumlah pesanan yang memuat produk ini (untuk penomoran halaman). */
    public static function productOrderCount(string $costKey, ?string $ym, ?string $platform): int
    {
        [$w, $a] = self::productWhere($ym, $platform);
        $sub = self::settlementPerOrderSql($w);
        $a[] = $costKey;

        return (int) Db::val(
            "SELECT COUNT(DISTINCT CONCAT(i.platform,'|',i.order_id))
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             WHERE i.cost_key = ?",
            $a,
            0
        );
    }

    /**
     * Pesanan TERAKHIR yang memuat produk ini dan sudah lengkap biayanya.
     *
     * Dipakai sebagai dasar simulasi harga karena tarif komisi dan biaya
     * layanan berubah dari waktu ke waktu - pesanan paling akhir mencerminkan
     * tarif yang berlaku sekarang, sedangkan rerata beberapa bulan masih
     * membawa tarif lama.
     *
     * Syaratnya: pesanan berstatus selesai DAN punya nilai biaya. Pesanan yang
     * batal atau yang biayanya belum tercatat tidak mewakili tarif apa pun.
     *
     * @return array{platform:string,order_id:string,settlement_date:string}|null
     */
    public static function latestSettledOrder(string $costKey, ?string $platform): ?array
    {
        $a = [$costKey];
        $wPlatform = '';
        if ($platform !== null) {
            $wPlatform = ' AND o.platform = ?';
            $a[] = $platform;
        }

        return Db::one(
            "SELECT o.platform, o.order_id, MAX(s.settlement_date) AS settlement_date
             FROM order_items i
             STRAIGHT_JOIN orders o
                 ON o.id = i.order_pk AND o.items_subtotal_before > 0
                AND o.status_norm = 'selesai'
             STRAIGHT_JOIN settlements s
                 ON s.platform = o.platform AND s.order_id = o.order_id
                AND s.settlement_date IS NOT NULL
             WHERE i.cost_key = ? {$wPlatform}
             GROUP BY o.platform, o.order_id
             HAVING SUM(s.total_fee) <> 0
             ORDER BY settlement_date DESC, o.order_id DESC
             LIMIT 1",
            $a
        );
    }

    /**
     * Bahan simulasi harga dari SATU pesanan tertentu.
     *
     * Nilai settlement dialokasikan ke baris produk memakai porsi
     * subtotal-sebelum-diskon, cara yang sama dengan seluruh laporan per
     * produk, sehingga angkanya konsisten antar halaman.
     */
    public static function pricingFromOrder(string $costKey, string $platform, string $orderId): array
    {
        $kosong = ['qty' => 0, 'harga' => 0.0, 'refund' => 0.0, 'potongan' => 0.0,
                   'biaya' => 0.0, 'lain' => 0.0, 'bersih' => 0.0, 'harga_unit' => null,
                   'pajak_platform' => 0.0,
                   'refund_pct' => null, 'potongan_pct' => null, 'biaya_pct' => null,
                   'lain_pct' => null, 'bersih_pct' => null];

        $row = Db::one(
            "SELECT SUM(i.qty)                  AS qty,
                    SUM(i.subtotal_before_disc) AS harga,
                    SUM(st.refund_amount  * i.subtotal_before_disc / o.items_subtotal_before) AS refund,
                    SUM(st.total_potongan * i.subtotal_before_disc / o.items_subtotal_before) AS potongan,
                    SUM(st.total_fee      * i.subtotal_before_disc / o.items_subtotal_before) AS biaya,
                    SUM(st.fee_pajak      * i.subtotal_before_disc / o.items_subtotal_before) AS pajak_platform,
                    SUM(st.net_amount     * i.subtotal_before_disc / o.items_subtotal_before) AS bersih
             FROM (SELECT platform, order_id,
                          SUM(refund_amount)  AS refund_amount,
                          SUM(total_potongan) AS total_potongan,
                          SUM(total_fee)      AS total_fee,
                          SUM(fee_pajak)      AS fee_pajak,
                          SUM(net_amount)     AS net_amount
                   FROM settlements
                   WHERE platform = ? AND order_id = ? AND settlement_date IS NOT NULL
                   GROUP BY platform, order_id) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             WHERE i.cost_key = ?",
            [$platform, $orderId, $costKey]
        ) ?? [];

        $qty   = (int) ($row['qty'] ?? 0);
        $harga = (float) ($row['harga'] ?? 0);
        if ($qty === 0 || $harga <= 0) {
            return $kosong;
        }

        $refund   = abs((float) ($row['refund'] ?? 0));
        $potongan = abs((float) ($row['potongan'] ?? 0));
        $biaya    = abs((float) ($row['biaya'] ?? 0));
        $bersih   = (float) ($row['bersih'] ?? 0);
        $lain     = $harga - $refund - $potongan - $biaya - $bersih;

        return [
            'qty'          => $qty,
            'harga'        => $harga,
            'refund'       => $refund,
            'potongan'     => $potongan,
            'biaya'        => $biaya,
            'lain'         => $lain,
            'bersih'       => $bersih,
            // Kalau marketplace sudah memungut PPh Pasal 22, nilainya ada di
            // sini dan sudah termasuk dalam biaya - simulasi tidak boleh
            // menambahkannya lagi.
            'pajak_platform' => abs((float) ($row['pajak_platform'] ?? 0)),
            'harga_unit'   => $harga / $qty,
            'refund_pct'   => $refund / $harga * 100,
            'potongan_pct' => $potongan / $harga * 100,
            'biaya_pct'    => $biaya / $harga * 100,
            'lain_pct'     => $lain / $harga * 100,
            'bersih_pct'   => $bersih / $harga * 100,
        ];
    }

    /** Rincian komponen biaya & potongan untuk satu pesanan. */
    public static function breakdownFromOrder(string $costKey, string $platform, string $orderId): array
    {
        $rows = Db::all(
            "SELECT sf.fee_category, sf.fee_label,
                    SUM(sf.amount * i.subtotal_before_disc / o.items_subtotal_before) AS nilai
             FROM order_items i
             STRAIGHT_JOIN orders o ON o.id = i.order_pk AND o.items_subtotal_before > 0
             STRAIGHT_JOIN settlement_fees sf
                 ON sf.platform = o.platform AND sf.order_id = o.order_id
             WHERE i.cost_key = ? AND o.platform = ? AND o.order_id = ?
               AND sf.fee_category NOT IN ('total','informasi','rincian','refund','penyesuaian')
             GROUP BY sf.fee_category, sf.fee_label
             HAVING nilai <> 0
             ORDER BY nilai",
            [$costKey, $platform, $orderId]
        );

        $out = ['potongan' => [], 'biaya' => []];
        foreach ($rows as $r) {
            $kel = $r['fee_category'] === 'potongan' ? 'potongan' : 'biaya';
            $out[$kel][] = [
                'kategori' => (string) $r['fee_category'],
                'label'    => (string) $r['fee_label'],
                'nilai'    => (float) $r['nilai'],
            ];
        }
        return $out;
    }

    /**
     * HPP terakhir yang tercatat untuk produk ini.
     *
     * Diambil dari bulan paling akhir yang ada isinya, bukan bulan berjalan:
     * HPP bulan berjalan sering belum diunggah, dan mengembalikan nol akan
     * membuat simulasi tampak untung besar padahal modalnya belum dihitung.
     */
    public static function latestCost(string $costKey): ?array
    {
        return Db::one(
            'SELECT period_ym, cost_per_unit
             FROM product_cost
             WHERE cost_key = ? AND cost_per_unit <> 0
             ORDER BY period_ym DESC
             LIMIT 1',
            [$costKey]
        );
    }

    /** Daftar pesanan yang memuat produk ini, lengkap dengan nilai alokasinya. */
    public static function productOrders(
        string $costKey,
        ?string $ym,
        ?string $platform,
        int $limit = 50,
        int $offset = 0
    ): array {
        [$w, $a] = self::productWhere($ym, $platform);
        $sub  = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();
        $a[] = $costKey;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $rows = Db::all(
            "SELECT o.platform, o.order_id, o.order_date, o.status_raw, o.status_norm,
                    o.buyer_username, st.period_ym,
                    SUM(i.qty)          AS qty,
                    SUM(i.qty_returned) AS qty_retur,
                    SUM(st.gross_amount   * i.subtotal_before_disc / o.items_subtotal_before) AS kotor,
                    SUM(st.total_potongan * i.subtotal_before_disc / o.items_subtotal_before) AS potongan,
                    SUM(st.total_fee      * i.subtotal_before_disc / o.items_subtotal_before) AS biaya,
                    SUM(st.net_amount     * i.subtotal_before_disc / o.items_subtotal_before) AS bersih,
                    SUM(i.qty * COALESCE(pc.cost_per_unit,0))          AS hpp,
                    SUM(CASE WHEN pc.id IS NULL THEN i.qty ELSE 0 END) AS qty_tanpa_hpp
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}
             WHERE i.cost_key = ?
             GROUP BY o.id
             ORDER BY bersih DESC
             LIMIT {$limit} OFFSET {$offset}",
            $a
        );

        foreach ($rows as &$r) {
            self::addProductDerived($r);
        }
        unset($r);

        return $rows;
    }

    // -----------------------------------------------------------------
    // Pengembalian dana (refund)
    // -----------------------------------------------------------------

    /**
     * Ringkasan pengembalian dana.
     *
     * Nilai refund disimpan negatif; di sini dibalik jadi positif supaya
     * laporannya terbaca sebagai "berapa besar pengembalian", bukan sebagai
     * pengurang. Pembanding "kotor" adalah pendapatan kotor SEBELUM dikurangi
     * refund - itulah dasar yang benar untuk mengukur tingkat pengembalian.
     */
    public static function refundSummary(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        $row = Db::one(
            "SELECT COALESCE(-SUM(refund_amount),0)  AS refund,
                    COALESCE(SUM(gross_amount),0)    AS kotor_sebelum,
                    SUM(refund_amount <> 0)          AS trx_refund,
                    COUNT(DISTINCT CASE WHEN refund_amount <> 0
                                        THEN CONCAT(platform,'|',order_id) END) AS pesanan_refund,
                    COUNT(DISTINCT CONCAT(platform,'|',order_id))               AS pesanan_total
             FROM settlements WHERE {$w}",
            $a
        ) ?? [];

        $kotor = (float) ($row['kotor_sebelum'] ?? 0);
        $row['rasio'] = $kotor > 0 ? (float) ($row['refund'] ?? 0) / $kotor * 100 : null;
        $row['rasio_pesanan'] = ((int) ($row['pesanan_total'] ?? 0)) > 0
            ? (int) $row['pesanan_refund'] / (int) $row['pesanan_total'] * 100
            : null;
        return $row;
    }

    /**
     * Pengembalian per bulan settlement, dipecah per platform.
     *
     * Pembandingnya adalah pendapatan kotor SELURUH bulan itu, bukan hanya
     * transaksi yang kena refund - kalau hanya yang kena refund yang dibagi,
     * rasionya akan terbaca seolah-olah hampir seluruh bulan dikembalikan.
     * Baris tanpa refund disaring lewat HAVING, setelah penjumlahan.
     */
    public static function refundByMonth(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        return Db::all(
            "SELECT DATE_FORMAT(settlement_date,'%Y-%m') AS bulan, platform,
                    COALESCE(-SUM(refund_amount),0) AS refund,
                    COALESCE(SUM(gross_amount),0)   AS kotor_sebelum,
                    SUM(refund_amount <> 0)         AS trx_refund
             FROM settlements
             WHERE {$w} AND settlement_date IS NOT NULL
             GROUP BY bulan, platform
             HAVING refund <> 0
             ORDER BY bulan DESC, platform",
            $a
        );
    }

    /** Produk yang paling sering / paling besar dikembalikan. */
    public static function refundByProduct(?string $from, ?string $to, ?string $platform, int $limit = 100): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        // Hanya settlement yang benar-benar ada refund-nya yang ditelusuri,
        // sehingga penelusuran ke baris produk tetap ringan.
        $sub = "SELECT platform, order_id,
                       SUM(refund_amount) AS refund_amount
                FROM settlements
                WHERE {$w} AND refund_amount <> 0
                GROUP BY platform, order_id";

        return Db::all(
            "SELECT COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COALESCE(i.variation,'') AS variasi,
                    MAX(i.cost_key)          AS cost_key,
                    COUNT(DISTINCT CONCAT(i.platform,'|',i.order_id)) AS pesanan,
                    SUM(i.qty_returned)      AS qty_retur,
                    COALESCE(-SUM(st.refund_amount * i.subtotal_before_disc
                                  / o.items_subtotal_before),0) AS refund
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             GROUP BY produk, variasi
             ORDER BY refund DESC
             LIMIT {$limit}",
            $a
        );
    }

    /** Daftar transaksi pengembalian, untuk ditelusuri satu per satu. */
    public static function refundList(?string $from, ?string $to, ?string $platform, int $limit = 200): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        $limit = max(1, min(2000, $limit));
        return Db::all(
            "SELECT platform, order_id, settlement_date, trx_type,
                    gross_amount,
                    -refund_amount AS refund,
                    net_amount
             FROM settlements
             WHERE {$w} AND refund_amount <> 0
             ORDER BY refund DESC
             LIMIT {$limit}",
            $a
        );
    }

    // -----------------------------------------------------------------
    // Pemantauan kelengkapan data
    // -----------------------------------------------------------------

    /** Kapan tiap jenis berkas terakhir diunggah. */
    public static function uploadStatus(): array
    {
        return Db::all(
            "SELECT platform, dataset,
                    COUNT(*)            AS jumlah,
                    MAX(created_at)     AS terakhir,
                    MAX(period_to)      AS data_sampai,
                    MIN(period_from)    AS data_dari
             FROM uploads
             WHERE status IN ('success','partial')
             GROUP BY platform, dataset
             ORDER BY platform, dataset"
        );
    }

    /**
     * Kelengkapan data per bulan: pesanan, settlement, alokasi produk,
     * HPP, dan beban operasional - untuk melihat periode mana yang belum
     * diperbarui.
     */
    public static function dataMonitor(): array
    {
        $bulan = [];
        $touch = static function (array &$bulan, ?string $ym): void {
            if ($ym !== null && $ym !== '' && !isset($bulan[$ym])) {
                $bulan[$ym] = [
                    'ym' => $ym, 'pesanan' => 0, 'pesanan_update' => null,
                    'settlement' => 0, 'settlement_update' => null,
                    'settle_pesanan' => 0, 'settle_tanpa_order' => 0,
                    'nilai_bersih' => 0.0, 'nilai_tanpa_order' => 0.0,
                    'produk' => 0, 'produk_tanpa_hpp' => 0,
                    'beban' => 0.0, 'beban_baris' => 0,
                ];
            }
        };

        foreach (Db::all(
            "SELECT DATE_FORMAT(order_date,'%Y-%m') ym, COUNT(*) n, MAX(updated_at) upd
             FROM orders WHERE order_date IS NOT NULL GROUP BY ym"
        ) as $r) {
            $touch($bulan, $r['ym']);
            $bulan[$r['ym']]['pesanan'] = (int) $r['n'];
            $bulan[$r['ym']]['pesanan_update'] = $r['upd'];
        }

        foreach (Db::all(
            "SELECT DATE_FORMAT(settlement_date,'%Y-%m') ym, COUNT(*) n, MAX(updated_at) upd
             FROM settlements WHERE settlement_date IS NOT NULL GROUP BY ym"
        ) as $r) {
            $touch($bulan, $r['ym']);
            $bulan[$r['ym']]['settlement'] = (int) $r['n'];
            $bulan[$r['ym']]['settlement_update'] = $r['upd'];
        }

        // Settlement yang berkas pesanannya belum diunggah - inilah penyebab
        // "alokasi produk" tidak mencapai 100%.
        foreach (Db::all(
            "SELECT st.period_ym AS ym,
                    COUNT(*) AS total,
                    SUM(o.id IS NULL) AS tanpa_order,
                    SUM(st.net_amount) AS bersih,
                    SUM(CASE WHEN o.id IS NULL THEN st.net_amount ELSE 0 END) AS bersih_tanpa_order
             FROM (
                 SELECT platform, order_id,
                        DATE_FORMAT(MAX(settlement_date),'%Y-%m') AS period_ym,
                        SUM(net_amount) AS net_amount
                 FROM settlements WHERE settlement_date IS NOT NULL
                 GROUP BY platform, order_id
             ) st
             LEFT JOIN orders o ON o.platform = st.platform AND o.order_id = st.order_id
                               AND o.items_subtotal_before > 0
             GROUP BY st.period_ym"
        ) as $r) {
            $touch($bulan, $r['ym']);
            $bulan[$r['ym']]['settle_pesanan'] = (int) $r['total'];
            $bulan[$r['ym']]['settle_tanpa_order'] = (int) $r['tanpa_order'];
            $bulan[$r['ym']]['nilai_bersih'] = (float) $r['bersih'];
            $bulan[$r['ym']]['nilai_tanpa_order'] = (float) $r['bersih_tanpa_order'];
        }

        foreach (self::costCoverageByMonth() as $r) {
            $touch($bulan, $r['period_ym']);
            $bulan[$r['period_ym']]['produk'] = (int) $r['produk'];
            $bulan[$r['period_ym']]['produk_tanpa_hpp'] = (int) $r['produk_tanpa_hpp'];
        }

        [$g, $ga] = self::expenseGuard();
        foreach (Db::all(
            "SELECT period_ym ym, SUM(amount) total, COUNT(*) n
             FROM operating_expense WHERE {$g} GROUP BY period_ym",
            $ga
        ) as $r) {
            $touch($bulan, $r['ym']);
            $bulan[$r['ym']]['beban'] = (float) $r['total'];
            $bulan[$r['ym']]['beban_baris'] = (int) $r['n'];
        }

        krsort($bulan);
        return array_values($bulan);
    }

    /** Rincian settlement yang belum ada data pesanannya, per bulan. */
    public static function unmatchedSettlements(?string $ym, int $limit = 300): array
    {
        [$w, $a] = self::filterBulanPesanan($ym, null);
        return Db::all(
            "SELECT st.period_ym, st.platform, st.order_id, st.net_amount
             FROM (
                 SELECT platform, order_id,
                        DATE_FORMAT(MAX(settlement_date),'%Y-%m') AS period_ym,
                        SUM(net_amount) AS net_amount
                 FROM settlements WHERE {$w}
                 GROUP BY platform, order_id
             ) st
             LEFT JOIN orders o ON o.platform = st.platform AND o.order_id = st.order_id
                               AND o.items_subtotal_before > 0
             WHERE o.id IS NULL
             ORDER BY st.net_amount DESC
             LIMIT {$limit}",
            $a
        );
    }

    // -----------------------------------------------------------------
    // Penghapusan data (khusus admin, ditegakkan di halaman pemanggil)
    // -----------------------------------------------------------------

    /** Hapus HPP: satu baris, atau seluruh baris pada satu bulan. */
    public static function deleteCost(?int $id, ?string $ym): int
    {
        if ($id !== null) {
            return Db::q('DELETE FROM product_cost WHERE id = ?', [$id])->rowCount();
        }
        if ($ym !== null) {
            return Db::q('DELETE FROM product_cost WHERE period_ym = ?', [$ym])->rowCount();
        }
        return 0;
    }

    /** Hapus beban operasional: satu baris, atau seluruh baris pada satu bulan. */
    public static function deleteExpense(?int $id, ?string $ym): int
    {
        if ($id !== null) {
            return Db::q('DELETE FROM operating_expense WHERE id = ?', [$id])->rowCount();
        }
        if ($ym !== null) {
            return Db::q('DELETE FROM operating_expense WHERE period_ym = ?', [$ym])->rowCount();
        }
        return 0;
    }

    /** Daftar HPP yang tersimpan. */
    public static function costList(?string $ym, ?string $search, int $limit = 500): array
    {
        $w = ['1=1'];
        $a = [];
        if ($ym !== null) {
            $w[] = 'period_ym = ?';
            $a[] = $ym;
        }
        if ($search !== null) {
            $w[] = '(product_name LIKE ? OR variation LIKE ? OR sku LIKE ?)';
            $like = '%' . $search . '%';
            array_push($a, $like, $like, $like);
        }
        return Db::all(
            'SELECT * FROM product_cost WHERE ' . implode(' AND ', $w)
            . " ORDER BY period_ym DESC, product_name LIMIT {$limit}",
            $a
        );
    }

    /**
     * Saringan baku untuk beban operasional:
     *   - nilai 0 tidak pernah ikut (baris begitu dianggap belum diisi);
     *   - kategori gaji disaring sesuai hak akses pengguna yang sedang masuk.
     *
     * @return array{0:string,1:array}
     */
    private static function expenseGuard(?string $access = null): array
    {
        $access ??= Auth::salaryAccess();
        [$salaryWhere, $salaryArgs] = Perm::salarySqlFilter($access);
        return ['amount <> 0 AND ' . $salaryWhere, $salaryArgs];
    }

    /** Beban operasional: total per bulan dan per kategori. */
    public static function expenseByMonth(?string $from, ?string $to): array
    {
        [$w, $a] = self::periodFilter($from, $to);
        [$g, $ga] = self::expenseGuard();
        return Db::all(
            "SELECT period_ym, SUM(amount) AS total, COUNT(*) AS baris
             FROM operating_expense WHERE {$w} AND {$g}
             GROUP BY period_ym ORDER BY period_ym DESC",
            array_merge($a, $ga)
        );
    }

    public static function expenseByCategory(?string $from, ?string $to): array
    {
        [$w, $a] = self::periodFilter($from, $to);
        [$g, $ga] = self::expenseGuard();
        return Db::all(
            "SELECT category, SUM(amount) AS total, COUNT(*) AS baris
             FROM operating_expense WHERE {$w} AND {$g}
             GROUP BY category ORDER BY total DESC",
            array_merge($a, $ga)
        );
    }

    public static function expenseList(?string $ym, int $limit = 500): array
    {
        $w = ['1=1'];
        $a = [];
        if ($ym !== null) {
            $w[] = 'period_ym = ?';
            $a[] = $ym;
        }
        [$g, $ga] = self::expenseGuard();
        return Db::all(
            'SELECT * FROM operating_expense WHERE ' . implode(' AND ', $w) . " AND {$g}"
            . " ORDER BY period_ym DESC, category, description LIMIT {$limit}",
            array_merge($a, $ga)
        );
    }

    public static function expenseTotal(?string $from, ?string $to): float
    {
        [$w, $a] = self::periodFilter($from, $to);
        [$g, $ga] = self::expenseGuard();
        return (float) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM operating_expense WHERE {$w} AND {$g}",
            array_merge($a, $ga),
            0
        );
    }

    /** Berapa nilai beban yang disembunyikan dari pengguna ini. */
    public static function expenseHidden(?string $from, ?string $to): array
    {
        $access = Auth::salaryAccess();
        if ($access === 'all') {
            return ['baris' => 0, 'total' => 0.0];
        }
        [$w, $a] = self::periodFilter($from, $to);
        // Kebalikan dari hak akses pengguna.
        [$g, $ga] = Perm::salarySqlFilter($access === 'only' ? 'none' : 'only');
        $row = Db::one(
            "SELECT COUNT(*) baris, COALESCE(SUM(amount),0) total
             FROM operating_expense WHERE {$w} AND amount <> 0 AND {$g}",
            array_merge($a, $ga)
        ) ?? [];
        return ['baris' => (int) ($row['baris'] ?? 0), 'total' => (float) ($row['total'] ?? 0)];
    }

    /** Filter rentang tanggal terhadap kolom period_ym ('YYYY-MM'). */
    private static function periodFilter(?string $from, ?string $to): array
    {
        $w = ['1=1'];
        $a = [];
        if ($from !== null) {
            $w[] = 'period_ym >= ?';
            $a[] = substr($from, 0, 7);
        }
        if ($to !== null) {
            $w[] = 'period_ym <= ?';
            $a[] = substr($to, 0, 7);
        }
        return [implode(' AND ', $w), $a];
    }

    /** Daftar bulan yang tersedia dari data settlement. */
    public static function availableMonths(): array
    {
        return array_column(
            Db::all(
                "SELECT DISTINCT DATE_FORMAT(settlement_date,'%Y-%m') AS ym
                 FROM settlements WHERE settlement_date IS NOT NULL ORDER BY ym DESC"
            ),
            'ym'
        );
    }

    /** Produk yang pernah terjual - dipakai mengisi template HPP. */
    public static function soldProducts(?string $ym = null): array
    {
        if ($ym === null) {
            return Db::all(
                "SELECT COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                        COALESCE(i.variation,'') AS variasi,
                        MAX(COALESCE(i.seller_sku,'')) AS sku,
                        SUM(i.qty) AS qty
                 FROM order_items i
                 GROUP BY produk, variasi
                 ORDER BY qty DESC"
            );
        }
        [$wBulan, $aBulan] = self::filterBulanPesanan($ym, null);
        $sub = self::settlementPerOrderSql($wBulan);
        return Db::all(
            "SELECT COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COALESCE(i.variation,'') AS variasi,
                    MAX(COALESCE(i.seller_sku,'')) AS sku,
                    SUM(i.qty) AS qty
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             GROUP BY produk, variasi
             ORDER BY qty DESC",
            $aBulan
        );
    }

    // -----------------------------------------------------------------
    // Performa
    // -----------------------------------------------------------------

    public static function topProducts(?string $from, ?string $to, ?string $platform, int $limit = 50, string $sort = 'omzet'): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform, 'i');
        $order = $sort === 'qty' ? 'qty_terjual' : 'omzet';
        return Db::all(
            "SELECT i.platform,
                    COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COUNT(DISTINCT i.order_id)          AS pesanan,
                    SUM(i.qty)                          AS qty_terjual,
                    SUM(i.qty_returned)                 AS qty_retur,
                    SUM(i.subtotal_after_disc)          AS omzet,
                    SUM(i.discount_seller)              AS diskon_penjual
             FROM order_items i
             WHERE {$w} AND i.status_norm = 'selesai'
             GROUP BY i.platform, produk
             ORDER BY {$order} DESC
             LIMIT {$limit}",
            $a
        );
    }

    public static function topVariants(?string $from, ?string $to, ?string $platform, string $product, int $limit = 30): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform, 'i');
        $a[] = $product;
        return Db::all(
            "SELECT COALESCE(NULLIF(i.variation,''),'(tanpa varian)') AS varian,
                    COALESCE(NULLIF(i.seller_sku,''),'-') AS sku,
                    SUM(i.qty) AS qty_terjual,
                    SUM(i.subtotal_after_disc) AS omzet
             FROM order_items i
             WHERE {$w} AND i.status_norm='selesai' AND i.product_name = ?
             GROUP BY varian, sku ORDER BY omzet DESC LIMIT {$limit}",
            $a
        );
    }

    /** Performa mingguan - inti dari rutinitas upload tiap minggu. */
    public static function weekly(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT YEARWEEK(order_date, 3) AS yw,
                    MIN(order_date) AS mulai,
                    MAX(order_date) AS selesai_tgl,
                    COUNT(*) AS pesanan,
                    SUM(status_norm='selesai') AS pesanan_selesai,
                    SUM(status_norm='batal')   AS pesanan_batal,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN total_qty END),0) AS qty
             FROM orders
             WHERE {$w} AND order_date IS NOT NULL
             GROUP BY yw ORDER BY yw DESC",
            $a
        );
    }

    public static function monthlySales(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT DATE_FORMAT(order_date,'%Y-%m') AS bulan, platform,
                    COUNT(*) AS pesanan,
                    SUM(status_norm='selesai') AS pesanan_selesai,
                    SUM(status_norm='batal') AS pesanan_batal,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN total_qty END),0) AS qty
             FROM orders
             WHERE {$w} AND order_date IS NOT NULL
             GROUP BY bulan, platform ORDER BY bulan DESC, platform",
            $a
        );
    }

    public static function byProvince(?string $from, ?string $to, ?string $platform, int $limit = 40): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT COALESCE(NULLIF(province,''),'(tidak diketahui)') AS provinsi,
                    COUNT(*) AS pesanan,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet
             FROM orders WHERE {$w}
             GROUP BY provinsi ORDER BY omzet DESC LIMIT {$limit}",
            $a
        );
    }

    public static function byPayment(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT COALESCE(NULLIF(payment_method,''),'(tidak diketahui)') AS metode,
                    COUNT(*) AS pesanan,
                    COALESCE(SUM(CASE WHEN status_norm='selesai' THEN items_subtotal_after END),0) AS omzet
             FROM orders WHERE {$w}
             GROUP BY metode ORDER BY pesanan DESC",
            $a
        );
    }

    public static function byCourier(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('order_date', $from, $to, $platform);
        return Db::all(
            "SELECT COALESCE(NULLIF(shipping_provider, ''), NULLIF(shipping_option,''), '(tidak diketahui)') AS kurir,
                    COUNT(*) AS pesanan,
                    COALESCE(SUM(shipping_fee_original),0) AS ongkir
             FROM orders WHERE {$w}
             GROUP BY kurir ORDER BY pesanan DESC",
            $a
        );
    }
}
