<?php
declare(strict_types=1);

/**
 * Daftar perusahaan (tenant), masing-masing dengan databasenya sendiri.
 *
 * Datanya TERPISAH TOTAL antar perusahaan: satu database satu perusahaan,
 * termasuk daftar penggunanya. Jadi akun dan kata sandi direksi PT A tidak
 * berlaku di PT B - persis seperti yang dibutuhkan kalau orangnya berbeda.
 *
 * Daftarnya dibaca dari (urutan prioritas):
 *   1. berkas config/tenants.json  - paling mudah diubah lewat code-server
 *   2. environment TENANTS         - format: kode|Nama|nama_database, dipisah ';'
 *   3. bila keduanya kosong: satu perusahaan saja memakai DB_NAME yang ada,
 *      sehingga pemasangan lama tetap jalan tanpa diubah apa pun.
 *
 * Contoh config/tenants.json:
 *   [
 *     {"kode": "anomali", "nama": "Anomali Coffee", "db": "ecommerce"},
 *     {"kode": "ptkedua", "nama": "PT Kedua",       "db": "ecommerce_ptkedua"}
 *   ]
 */
final class Tenant
{
    public const SESSION_KEY = 'tenant';

    private static ?array $daftar = null;
    private static ?string $aktif = null;

    /** @return array<string,array{kode:string,nama:string,db:string}> */
    public static function all(): array
    {
        if (self::$daftar !== null) {
            return self::$daftar;
        }

        $out = [];
        foreach (self::baca() as $t) {
            $kode = self::normalKode((string) ($t['kode'] ?? ''));
            $db   = trim((string) ($t['db'] ?? ''));
            if ($kode === '' || $db === '' || !self::namaDbAman($db)) {
                continue;   // baris rusak dilewati, bukan dijadikan galat fatal
            }
            $out[$kode] = [
                'kode' => $kode,
                'nama' => trim((string) ($t['nama'] ?? '')) ?: $kode,
                'db'   => $db,
            ];
        }

        if ($out === []) {
            // Pemasangan lama / tunggal.
            $db = (string) Config::get('db_name');
            $out['utama'] = [
                'kode' => 'utama',
                'nama' => (string) Config::get('app_name'),
                'db'   => $db,
            ];
        }

        return self::$daftar = $out;
    }

    /** Apakah aplikasi ini melayani lebih dari satu perusahaan? */
    public static function banyak(): bool
    {
        return count(self::all()) > 1;
    }

    public static function ada(string $kode): bool
    {
        return isset(self::all()[self::normalKode($kode)]);
    }

    /** Kode perusahaan yang sedang aktif pada sesi ini. */
    public static function kodeAktif(): string
    {
        if (self::$aktif !== null) {
            return self::$aktif;
        }
        $dari = (string) ($_SESSION[self::SESSION_KEY] ?? '');
        if ($dari !== '' && self::ada($dari)) {
            return self::$aktif = self::normalKode($dari);
        }
        return self::$aktif = array_key_first(self::all());
    }

    /** @return array{kode:string,nama:string,db:string} */
    public static function aktif(): array
    {
        return self::all()[self::kodeAktif()];
    }

    /**
     * Pindah perusahaan. Sesi lama dibuang seluruhnya karena id pengguna hanya
     * berlaku di database asalnya - membawanya ke database lain bisa membuat
     * seseorang masuk sebagai orang yang sama sekali berbeda.
     */
    public static function pilih(string $kode): bool
    {
        if (!self::ada($kode)) {
            return false;
        }
        $kode = self::normalKode($kode);
        if (($_SESSION[self::SESSION_KEY] ?? null) !== $kode) {
            $_SESSION = [];
            $_SESSION[self::SESSION_KEY] = $kode;
            session_regenerate_id(true);
        }
        self::$aktif = $kode;
        Db::reset();
        return true;
    }

    /** Kode dinormalkan supaya tautan tidak sensitif huruf besar/kecil. */
    public static function normalKode(string $kode): string
    {
        return strtolower(trim($kode));
    }

    /**
     * Nama database hanya boleh huruf, angka, garis bawah, dan strip.
     * Nama ini masuk ke DSN dan ke perintah CREATE DATABASE, jadi tidak boleh
     * berisi apa pun yang bisa keluar dari konteksnya.
     */
    public static function namaDbAman(string $db): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $db) === 1;
    }

    /** Sumber daftar perusahaan, apa adanya. */
    private static function baca(): array
    {
        $file = dirname(__DIR__) . '/config/tenants.json';
        if (is_readable($file)) {
            $isi = json_decode((string) file_get_contents($file), true);
            if (is_array($isi)) {
                return $isi;
            }
        }

        $env = trim((string) (getenv('TENANTS') ?: ''));
        if ($env !== '') {
            $out = [];
            foreach (explode(';', $env) as $baris) {
                $b = array_map('trim', explode('|', $baris));
                if (count($b) >= 3) {
                    $out[] = ['kode' => $b[0], 'nama' => $b[1], 'db' => $b[2]];
                }
            }
            return $out;
        }

        return [];
    }
}
