<?php
declare(strict_types=1);

/**
 * Definisi hak akses: daftar tab dan aturan khusus kategori gaji.
 */
final class Perm
{
    /** kunci tab => [label menu, berkas, keterangan singkat] */
    public const TABS = [
        'dashboard'   => ['Dashboard', 'index.php', 'Ringkasan omzet dan tren penjualan'],
        'upload'      => ['Upload Data', 'upload.php', 'Mengunggah berkas ekspor Tokopedia/Shopee'],
        'orders'      => ['Pesanan', 'orders.php', 'Daftar & detail pesanan'],
        'products'    => ['Produk', 'products.php', 'Produk terlaris dan varian'],
        'performance' => ['Performa', 'performance.php', 'Rekap mingguan/bulanan, kurir, provinsi'],
        'pnl'         => ['Laba & Biaya', 'pnl.php', 'Laporan laba rugi lengkap'],
        'costs'       => ['HPP', 'costs.php', 'Impor & pantau HPP per produk'],
        'expenses'    => ['Beban', 'expenses.php', 'Impor & lihat beban operasional'],
        'settlements' => ['Settlement', 'settlements.php', 'Rincian dana yang dilepaskan platform'],
        'refunds'     => ['Pengembalian', 'refunds.php', 'Refund per bulan, per produk, dan per transaksi'],
        'recon'       => ['Rekonsiliasi', 'reconciliation.php', 'Pesanan vs settlement'],
        'monitoring'  => ['Monitoring', 'monitoring.php', 'Kelengkapan data per periode'],
        'uploads'     => ['Riwayat Upload', 'uploads.php', 'Catatan berkas yang pernah diproses'],
    ];

    /** Tab yang selalu khusus admin dan tidak bisa diberikan ke peran lain. */
    public const ADMIN_ONLY_TABS = ['users'];

    /**
     * Nama akun khusus direksi: masuk lewat halaman tersendiri dengan kata
     * sandi saja, tanpa username. Akun ini tidak bisa dihapus dan tidak bisa
     * dijadikan admin - hak aksesnya hanya sebatas tab yang dicentang.
     */
    public const AKUN_DIREKSI = '__direksi__';

    /**
     * Kata kunci penanda kategori gaji.
     *
     * Kategori beban dianggap "gaji" bila namanya memuat salah satu kata ini.
     * Karena itu beri nama kategori dengan jelas, misalnya "Gaji", "Gaji
     * Karyawan", atau "THR" - jangan "Beban Personalia" yang tidak terdeteksi.
     */
    public const SALARY_KEYWORDS = ['gaji', 'upah', 'salary', 'payroll', 'thr', 'tunjangan'];

    public static function isSalaryCategory(?string $category): bool
    {
        $c = mb_strtolower(trim((string) $category));
        if ($c === '') {
            return false;
        }
        foreach (self::SALARY_KEYWORDS as $k) {
            if (str_contains($c, $k)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Potongan SQL untuk menyaring kategori gaji sesuai hak akses.
     *
     * @return array{0:string,1:array} [kondisi WHERE, parameter]
     */
    public static function salarySqlFilter(string $access, string $column = 'category'): array
    {
        if ($access === 'all') {
            return ['1=1', []];
        }

        $conds = [];
        $args  = [];
        foreach (self::SALARY_KEYWORDS as $k) {
            $conds[] = "LOWER({$column}) LIKE ?";
            $args[]  = '%' . $k . '%';
        }
        $isSalary = '(' . implode(' OR ', $conds) . ')';

        return $access === 'only'
            ? [$isSalary, $args]
            : ['NOT ' . $isSalary, $args];
    }

    public static function accessLabel(string $access): string
    {
        return match ($access) {
            'only' => 'Hanya kategori gaji',
            'none' => 'Tanpa kategori gaji',
            default => 'Semua kategori',
        };
    }
}
