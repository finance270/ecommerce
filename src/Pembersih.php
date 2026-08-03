<?php
declare(strict_types=1);

/**
 * Menghapus data terimpor untuk satu bulan.
 *
 * Dipakai saat berkas terlanjur diunggah ke perusahaan yang keliru. Impor
 * bersifat idempoten - mengunggah ulang tidak menggandakan - tetapi tidak ada
 * yang bisa membatalkan unggahan ke database yang salah, karena data itu sah
 * menurut database tersebut. Karena itu penghapusannya per bulan, sama seperti
 * cara berkasnya diekspor dari platform.
 *
 * Dua dasar tanggal dipakai sesuai jenis datanya:
 *   pesanan     - orders.order_date        (kapan pesanan dibuat)
 *   penghasilan - settlements.settlement_date (kapan dananya dilepas)
 *
 * Itu sengaja berbeda, karena begitulah kedua berkas disusun platform. Satu
 * pesanan bulan Juni bisa dananya dilepas bulan Juli; menghapus "Juli" pada
 * penghasilan tidak menyentuh pesanan Juni-nya, dan itu memang benar.
 */
final class Pembersih
{
    public const JENIS = ['pesanan', 'penghasilan'];

    /** @return array{0:string,1:string} tanggal awal dan akhir bulan */
    public static function rentang(string $ym): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
            throw new RuntimeException('Bulan harus dalam bentuk YYYY-MM.');
        }
        $awal = $ym . '-01';
        $akhir = date('Y-m-t', strtotime($awal) ?: time());
        return [$awal, $akhir];
    }

    /**
     * Hitung apa saja yang akan terhapus, tanpa menghapus apa pun.
     *
     * @return array<string,int> nama bagian => jumlah baris
     */
    public static function hitung(string $ym, array $jenis, ?string $platform = null): array
    {
        [$awal, $akhir] = self::rentang($ym);
        $out = [];

        if (in_array('pesanan', $jenis, true)) {
            [$sql, $arg] = self::syaratPesanan($awal, $akhir, $platform);
            $out['pesanan'] = (int) Db::val("SELECT COUNT(*) FROM orders o WHERE {$sql}", $arg, 0);
            $out['baris produk'] = (int) Db::val(
                "SELECT COUNT(*) FROM order_items i JOIN orders o ON o.id = i.order_pk WHERE {$sql}",
                $arg,
                0
            );
        }

        if (in_array('penghasilan', $jenis, true)) {
            [$sql, $arg] = self::syaratSettlement($awal, $akhir, $platform);
            $out['settlement'] = (int) Db::val("SELECT COUNT(*) FROM settlements s WHERE {$sql}", $arg, 0);
            $out['rincian biaya'] = (int) Db::val(
                "SELECT COUNT(*) FROM settlement_fees f JOIN settlements s ON s.id = f.settlement_id WHERE {$sql}",
                $arg,
                0
            );
            [$wsql, $warg] = self::syaratPenarikan($awal, $akhir, $platform);
            $out['penarikan dana'] = (int) Db::val("SELECT COUNT(*) FROM withdrawals w WHERE {$wsql}", $warg, 0);
        }

        return $out;
    }

    /**
     * Hapus sungguhan.
     *
     * @return array<string,int> jumlah baris yang benar-benar terhapus
     */
    public static function jalankan(string $ym, array $jenis, ?string $platform = null): array
    {
        [$awal, $akhir] = self::rentang($ym);
        $hasil = [];

        if (in_array('pesanan', $jenis, true)) {
            [$sql, $arg] = self::syaratPesanan($awal, $akhir, $platform);
            $ids = Db::q("SELECT o.id FROM orders o WHERE {$sql}", $arg)->fetchAll(PDO::FETCH_COLUMN);
            $hasil = array_merge($hasil, self::hapusPesanan(array_map('intval', $ids)));
        }

        if (in_array('penghasilan', $jenis, true)) {
            [$sql, $arg] = self::syaratSettlement($awal, $akhir, $platform);
            $ids = Db::q("SELECT s.id FROM settlements s WHERE {$sql}", $arg)->fetchAll(PDO::FETCH_COLUMN);
            $hasil = array_merge($hasil, self::hapusSettlement(array_map('intval', $ids)));

            [$wsql, $warg] = self::syaratPenarikan($awal, $akhir, $platform);
            $hasil['penarikan dana'] = Db::q("DELETE w FROM withdrawals w WHERE {$wsql}", $warg)->rowCount();
        }

        return $hasil;
    }

    /** @param int[] $ids */
    private static function hapusPesanan(array $ids): array
    {
        $n = ['pesanan' => 0, 'baris produk' => 0];
        // Dipotong per 500 supaya satu bulan yang besar tidak mengunci tabel
        // terlalu lama - laporan lain masih bisa dibaca sementara ini jalan.
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $n['baris produk'] += Db::q("DELETE FROM order_items WHERE order_pk IN ({$ph})", $chunk)->rowCount();
            Db::q("DELETE FROM order_raw WHERE order_pk IN ({$ph})", $chunk);
            $n['pesanan'] += Db::q("DELETE FROM orders WHERE id IN ({$ph})", $chunk)->rowCount();
        }
        return $n;
    }

    /** @param int[] $ids */
    private static function hapusSettlement(array $ids): array
    {
        $n = ['settlement' => 0, 'rincian biaya' => 0];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $n['rincian biaya'] += Db::q(
                "DELETE FROM settlement_fees WHERE settlement_id IN ({$ph})",
                $chunk
            )->rowCount();
            Db::q("DELETE FROM settlement_raw WHERE settlement_id IN ({$ph})", $chunk);
            $n['settlement'] += Db::q("DELETE FROM settlements WHERE id IN ({$ph})", $chunk)->rowCount();
        }
        return $n;
    }

    /** @return array{0:string,1:array} */
    private static function syaratPesanan(string $awal, string $akhir, ?string $platform): array
    {
        $sql = 'o.order_date BETWEEN ? AND ?';
        $arg = [$awal, $akhir];
        if ($platform !== null && $platform !== '') {
            $sql .= ' AND o.platform = ?';
            $arg[] = $platform;
        }
        return [$sql, $arg];
    }

    /** @return array{0:string,1:array} */
    private static function syaratSettlement(string $awal, string $akhir, ?string $platform): array
    {
        $sql = 's.settlement_date BETWEEN ? AND ?';
        $arg = [$awal, $akhir];
        if ($platform !== null && $platform !== '') {
            $sql .= ' AND s.platform = ?';
            $arg[] = $platform;
        }
        return [$sql, $arg];
    }

    /** @return array{0:string,1:array} */
    private static function syaratPenarikan(string $awal, string $akhir, ?string $platform): array
    {
        $sql = 'w.withdraw_date BETWEEN ? AND ?';
        $arg = [$awal, $akhir];
        if ($platform !== null && $platform !== '') {
            $sql .= ' AND w.platform = ?';
            $arg[] = $platform;
        }
        return [$sql, $arg];
    }

    /**
     * Bulan yang benar-benar ada datanya, untuk mengisi pilihan.
     *
     * @return array<string,array{pesanan:int,settlement:int}>
     */
    public static function bulanTersedia(): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT DATE_FORMAT(order_date, '%Y-%m') ym, COUNT(*) n FROM orders
              WHERE order_date IS NOT NULL GROUP BY ym"
        ) as $r) {
            $out[(string) $r['ym']]['pesanan'] = (int) $r['n'];
        }
        foreach (Db::all(
            "SELECT DATE_FORMAT(settlement_date, '%Y-%m') ym, COUNT(*) n FROM settlements
              WHERE settlement_date IS NOT NULL GROUP BY ym"
        ) as $r) {
            $out[(string) $r['ym']]['settlement'] = (int) $r['n'];
        }
        krsort($out);
        foreach ($out as $ym => $v) {
            $out[$ym] = ['pesanan' => $v['pesanan'] ?? 0, 'settlement' => $v['settlement'] ?? 0];
        }
        return $out;
    }
}
