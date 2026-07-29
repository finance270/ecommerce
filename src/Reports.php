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
                COUNT(*)                       AS total_trx,
                COALESCE(SUM(gross_amount),0)  AS pendapatan_kotor,
                COALESCE(SUM(total_fee),0)     AS total_biaya,
                COALESCE(SUM(net_amount),0)    AS dana_diterima,
                COALESCE(SUM(refund_amount),0) AS pengembalian
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
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        $head = Db::one(
            "SELECT
                COUNT(*) AS trx,
                COALESCE(SUM(gross_amount),0)      AS pendapatan_kotor,
                COALESCE(SUM(discount_seller),0)   AS diskon_penjual,
                COALESCE(SUM(refund_amount),0)     AS pengembalian,
                COALESCE(SUM(adjustment_amount),0) AS penyesuaian,
                COALESCE(SUM(total_fee),0)         AS total_biaya,
                COALESCE(SUM(net_amount),0)        AS dana_diterima
             FROM settlements WHERE {$w}",
            $a
        ) ?? [];

        [$w2, $a2] = self::filter('settlement_date', $from, $to, $platform);
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
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);

        $select = [];
        foreach (Profiles::FEE_CATEGORIES as $cat) {
            $select[] = "COALESCE(SUM(fee_{$cat}),0) AS `c_{$cat}`";
        }
        $select[] = 'COALESCE(SUM(total_potongan),0)    AS `c_potongan`';
        $select[] = 'COALESCE(SUM(refund_amount),0)     AS `c_refund`';
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
     *   pendapatan kotor + potongan + pengembalian + biaya platform
     *   + penyesuaian + selisih pencatatan = dana diterima bersih
     *
     * Pendapatan kotor memakai kolom paling kotor yang tersedia
     * ("Subtotal sebelum diskon" untuk Tokopedia, "Harga Asli Produk" untuk
     * Shopee), sehingga diskon penjual tampil sebagai baris tersendiri dan
     * kedua platform bisa dibandingkan setara.
     *
     * "Dana diterima bersih" diambil dari kolom resmi platform, sehingga baris
     * selisih memperlihatkan secara jujur bila laporan platform sendiri tidak
     * bulat.
     */
    public static function bridge(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        $rows = Db::all(
            "SELECT platform,
                    COALESCE(SUM(gross_amount),0)      AS kotor,
                    COALESCE(SUM(total_potongan),0)    AS potongan,
                    COALESCE(SUM(refund_amount),0)     AS refund,
                    COALESCE(SUM(total_fee),0)         AS biaya,
                    COALESCE(SUM(adjustment_amount),0) AS penyesuaian,
                    COALESCE(SUM(net_amount),0)        AS bersih
             FROM settlements WHERE {$w} GROUP BY platform",
            $a
        );

        $out = [];
        foreach ($rows as $r) {
            $subtotal = (float) $r['kotor'] + (float) $r['potongan'] + (float) $r['refund']
                + (float) $r['biaya'] + (float) $r['penyesuaian'];
            $out[] = [
                'platform'    => (string) $r['platform'],
                'kotor'       => (float) $r['kotor'],
                'potongan'    => (float) $r['potongan'],
                'refund'      => (float) $r['refund'],
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
        $t = ['kotor' => 0.0, 'potongan' => 0.0, 'refund' => 0.0, 'biaya' => 0.0,
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
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
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
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        $rows = Db::all(
            "SELECT DATE_FORMAT(settlement_date,'%Y-%m') AS bulan, platform,
                    COUNT(*) AS trx,
                    COALESCE(SUM(gross_amount),0)      AS pendapatan_kotor,
                    COALESCE(SUM(total_potongan),0)    AS potongan,
                    COALESCE(SUM(refund_amount),0)     AS pengembalian,
                    COALESCE(SUM(total_fee),0)         AS total_biaya,
                    COALESCE(SUM(adjustment_amount),0) AS penyesuaian,
                    COALESCE(SUM(net_amount),0)        AS dana_diterima
             FROM settlements
             WHERE {$w} AND settlement_date IS NOT NULL
             GROUP BY bulan, platform ORDER BY bulan DESC, platform",
            $a
        );
        foreach ($rows as &$r) {
            $r['selisih'] = (float) $r['dana_diterima'] - ((float) $r['pendapatan_kotor']
                + (float) $r['potongan'] + (float) $r['pengembalian']
                + (float) $r['total_biaya'] + (float) $r['penyesuaian']);
        }
        return $rows;
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

    public static function withdrawals(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('withdraw_date', $from, $to, $platform);
        return Db::all(
            "SELECT platform, withdraw_date, COUNT(*) AS jumlah, SUM(amount) AS total, status
             FROM withdrawals WHERE {$w}
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
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
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
                    SUM(st.refund_amount  * i.subtotal_before_disc / o.items_subtotal_before) AS pengembalian,
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
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform, 's');
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
     * HPP dicocokkan memakai BULAN SETTLEMENT, sama seperti seluruh halaman
     * Laba & Biaya, supaya biaya dan pendapatannya berada pada periode yang
     * sama. Dipakai bersama oleh laporan laba per produk dan pemantauan HPP.
     */
    private static function costJoinSql(): string
    {
        return "LEFT JOIN product_cost pc
                    ON pc.cost_key = i.cost_key AND pc.period_ym = st.period_ym";
    }

    /** Sub-query settlement per pesanan + bulan settlement-nya. */
    private static function settlementPerOrderSql(string $where): string
    {
        return "SELECT platform, order_id,
                       DATE_FORMAT(MAX(settlement_date),'%Y-%m') AS period_ym,
                       SUM(gross_amount)   AS gross_amount,
                       SUM(total_potongan) AS total_potongan,
                       SUM(refund_amount)  AS refund_amount,
                       SUM(total_fee)      AS total_fee,
                       SUM(net_amount)     AS net_amount
                FROM settlements
                WHERE {$where}
                GROUP BY platform, order_id";
    }

    /** Laba per produk: alokasi settlement dikurangi HPP. */
    public static function productProfit(?string $from, ?string $to, ?string $platform, int $limit = 100, string $sort = 'laba'): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
        $order = match ($sort) {
            'kotor'  => 'kotor',
            'qty'    => 'qty',
            'bersih' => 'bersih',
            'marjin' => 'marjin_laba',
            default  => 'laba',
        };
        $sub = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();

        return Db::all(
            "SELECT i.platform,
                    COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COUNT(DISTINCT i.order_id) AS pesanan,
                    SUM(i.qty)                 AS qty,
                    SUM(st.gross_amount   * i.subtotal_before_disc / o.items_subtotal_before) AS kotor,
                    SUM(st.total_potongan * i.subtotal_before_disc / o.items_subtotal_before) AS potongan,
                    SUM(st.refund_amount  * i.subtotal_before_disc / o.items_subtotal_before) AS pengembalian,
                    SUM(st.total_fee      * i.subtotal_before_disc / o.items_subtotal_before) AS biaya,
                    SUM(st.net_amount     * i.subtotal_before_disc / o.items_subtotal_before) AS bersih,
                    SUM(i.qty * COALESCE(pc.cost_per_unit, 0))          AS hpp,
                    SUM(CASE WHEN pc.id IS NULL THEN i.qty ELSE 0 END)  AS qty_tanpa_hpp,
                    SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before)
                        - SUM(i.qty * COALESCE(pc.cost_per_unit, 0))    AS laba,
                    CASE WHEN SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before) > 0
                         THEN (SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before)
                               - SUM(i.qty * COALESCE(pc.cost_per_unit, 0)))
                              / SUM(st.net_amount * i.subtotal_before_disc / o.items_subtotal_before) * 100
                         ELSE NULL END AS marjin_laba
             FROM ({$sub}) st
             STRAIGHT_JOIN orders o
                 ON o.platform = st.platform AND o.order_id = st.order_id
                AND o.items_subtotal_before > 0
             STRAIGHT_JOIN order_items i ON i.order_pk = o.id
             {$join}
             GROUP BY i.platform, produk
             ORDER BY {$order} DESC
             LIMIT {$limit}",
            $a
        );
    }

    /** Ringkasan HPP untuk seluruh rentang: total HPP + qty yang belum punya HPP. */
    public static function costSummary(?string $from, ?string $to, ?string $platform): array
    {
        [$w, $a] = self::filter('settlement_date', $from, $to, $platform);
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
        $w = 'settlement_date IS NOT NULL';
        $a = [];
        if ($ym !== null) {
            $w .= " AND DATE_FORMAT(settlement_date,'%Y-%m') = ?";
            $a[] = $ym;
        }
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
        $sub = self::settlementPerOrderSql('settlement_date IS NOT NULL');
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

    /**
     * Uji kewajaran HPP untuk produk yang HPP-nya SUDAH diisi.
     *
     * Marjin laba = (dana bersih - HPP) / dana bersih. Marjin mendekati 100%
     * berarti HPP nyaris nol dibanding pendapatannya - hampir pasti salah
     * isi. Marjin negatif berarti HPP melebihi pendapatan (jual rugi).
     * Ambang batasnya bisa diatur dari halaman.
     */
    public static function costMarginCheck(?string $ym, ?string $platform, float $warnPct = 70.0, float $badPct = 100.0): array
    {
        $w = 'settlement_date IS NOT NULL';
        $a = [];
        if ($ym !== null) {
            $w .= " AND DATE_FORMAT(settlement_date,'%Y-%m') = ?";
            $a[] = $ym;
        }
        if ($platform !== null) {
            $w .= ' AND platform = ?';
            $a[] = $platform;
        }
        $sub = self::settlementPerOrderSql($w);
        $join = self::costJoinSql();

        $rows = Db::all(
            "SELECT st.period_ym,
                    COALESCE(NULLIF(i.product_name,''),'(tanpa nama)') AS produk,
                    COALESCE(i.variation,'') AS variasi,
                    MAX(pc.cost_per_unit) AS hpp_unit,
                    SUM(i.qty)            AS qty,
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
            $laba   = $bersih - $hpp;

            $r['laba'] = $laba;
            $r['bersih_unit'] = $qty > 0 ? $bersih / $qty : 0.0;
            $r['marjin'] = $bersih > 0 ? $laba / $bersih * 100 : null;

            // Dibandingkan pada ketelitian yang sama dengan yang ditampilkan
            // (1 desimal). Sejak nilai 0 pada template diabaikan, HPP selalu
            // lebih besar dari nol sehingga marjin tidak pernah persis 100%;
            // tanpa pembulatan ini ambang 100% tidak akan pernah tercapai
            // walau HPP-nya cuma Rp 1.
            $m = $r['marjin'] === null ? null : round($r['marjin'], 1);
            if ($bersih <= 0) {
                $r['status'] = 'periksa';
                $r['alasan'] = 'Pendapatan bersih nol atau negatif';
            } elseif ($laba < 0) {
                $r['status'] = 'rugi';
                $r['alasan'] = 'HPP lebih besar dari pendapatan bersih (jual rugi)';
            } elseif ($m >= $badPct) {
                $r['status'] = 'parah';
                $r['alasan'] = 'HPP nyaris nol dibanding pendapatan - hampir pasti salah isi';
            } elseif ($m > $warnPct) {
                $r['status'] = 'periksa';
                $r['alasan'] = 'Marjin di atas batas wajar, HPP kemungkinan terlalu kecil';
            } else {
                $r['status'] = 'wajar';
                $r['alasan'] = '';
            }
        }
        unset($r);

        usort($rows, static function (array $x, array $y): int {
            $rank = ['parah' => 0, 'rugi' => 1, 'periksa' => 2, 'wajar' => 3];
            $c = $rank[$x['status']] <=> $rank[$y['status']];
            return $c !== 0 ? $c : ((float) $y['bersih'] <=> (float) $x['bersih']);
        });

        return $rows;
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
        $w = 'settlement_date IS NOT NULL';
        $a = [];
        if ($ym !== null) {
            $w .= " AND DATE_FORMAT(settlement_date,'%Y-%m') = ?";
            $a[] = $ym;
        }
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
        $sub = self::settlementPerOrderSql("settlement_date IS NOT NULL AND DATE_FORMAT(settlement_date,'%Y-%m') = ?");
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
            [$ym]
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
