<?php
declare(strict_types=1);

/**
 * Database pusat: daftar akun dan daftar perusahaan.
 *
 * Pemisahannya begini:
 *
 *   pusat  - siapa orangnya (email + kata sandi) dan PT mana saja yang boleh
 *            dia buka. Isinya hanya itu, tidak ada data penjualan sama sekali.
 *   per PT - seluruh data penjualan, DAN hak akses per tab untuk orang itu di
 *            PT tersebut. Jadi satu orang bisa admin di PT A tapi hanya boleh
 *            melihat laba rugi di PT B.
 *
 * Dengan begitu satu email bisa memegang beberapa PT tanpa mencampur datanya,
 * dan menghapus satu PT tidak menyentuh PT lain.
 *
 * Database ini opsional. Kalau belum dipasang, aplikasi tetap berjalan seperti
 * sebelumnya: daftar perusahaan dibaca dari config/tenants.json dan masuknya
 * memakai username per database.
 */
final class Pusat
{
    public const PERAN = ['pemilik', 'admin', 'staf'];

    private static ?PDO $pdo = null;
    private static ?bool $siap = null;

    public static function namaDb(): string
    {
        return (string) Config::get('db_pusat');
    }

    /** Sambungan ke database pusat; null bila memang belum ada. */
    public static function pdo(): ?PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        try {
            return self::$pdo = Pemasang::sambung(self::namaDb());
        } catch (Throwable) {
            return null;
        }
    }

    /** Apakah database pusat sudah dipasang dan bisa dipakai? */
    public static function tersedia(): bool
    {
        if (self::$siap !== null) {
            return self::$siap;
        }
        $pdo = self::pdo();
        if ($pdo === null) {
            return self::$siap = false;
        }
        try {
            $st = $pdo->query("SHOW TABLES LIKE 'akun'");
            return self::$siap = ($st !== false && $st->fetchColumn() !== false);
        } catch (Throwable) {
            return self::$siap = false;
        }
    }

    /** Membuat database pusat beserta tabelnya. Aman diulang. */
    public static function pasang(): void
    {
        Pemasang::buatDatabase(self::namaDb());
        self::$pdo = null;
        self::$siap = null;
        $pdo = Pemasang::sambung(self::namaDb());

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS akun (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                nama VARCHAR(150) NOT NULL DEFAULT "",
                is_super TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at DATETIME NULL,
                UNIQUE KEY uq_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS perusahaan (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kode VARCHAR(40) NOT NULL,
                nama VARCHAR(150) NOT NULL,
                db_name VARCHAR(64) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_kode (kode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS anggota (
                akun_id INT UNSIGNED NOT NULL,
                perusahaan_id INT UNSIGNED NOT NULL,
                peran ENUM("pemilik","admin","staf") NOT NULL DEFAULT "staf",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (akun_id, perusahaan_id),
                KEY idx_perusahaan (perusahaan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::$pdo = $pdo;
        self::$siap = true;
    }

    // ---------------------------------------------------------------- akun

    public static function jumlahAkun(): int
    {
        $pdo = self::pdo();
        return $pdo === null ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM akun')->fetchColumn();
    }

    public static function akunByEmail(string $email): ?array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM akun WHERE email = ? AND is_active = 1');
        $st->execute([self::normalEmail($email)]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function akunById(int $id): ?array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM akun WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function buatAkun(string $email, string $sandi, string $nama, bool $super = false): int
    {
        $email = self::normalEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Alamat email tidak valid.');
        }
        if (strlen($sandi) < 8) {
            throw new RuntimeException('Kata sandi minimal 8 karakter.');
        }
        $pdo = self::pdo();
        if ($pdo === null) {
            throw new RuntimeException('Database pusat belum siap.');
        }
        $st = $pdo->prepare('SELECT id FROM akun WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch() !== false) {
            throw new RuntimeException('Email tersebut sudah terdaftar.');
        }
        $pdo->prepare('INSERT INTO akun (email, password_hash, nama, is_super) VALUES (?,?,?,?)')
            ->execute([$email, password_hash($sandi, PASSWORD_DEFAULT), trim($nama), $super ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    public static function gantiSandi(int $akunId, string $sandi): void
    {
        if (strlen($sandi) < 8) {
            throw new RuntimeException('Kata sandi minimal 8 karakter.');
        }
        self::pdo()?->prepare('UPDATE akun SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($sandi, PASSWORD_DEFAULT), $akunId]);
    }

    public static function catatMasuk(int $akunId): void
    {
        self::pdo()?->prepare('UPDATE akun SET last_login_at = NOW() WHERE id = ?')->execute([$akunId]);
    }

    public static function normalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    // ---------------------------------------------------------- perusahaan

    /** @return array<int,array> semua perusahaan aktif */
    public static function semuaPerusahaan(): array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return [];
        }
        return $pdo->query('SELECT * FROM perusahaan WHERE is_active = 1 ORDER BY nama')->fetchAll();
    }

    public static function perusahaanByKode(string $kode): ?array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM perusahaan WHERE kode = ?');
        $st->execute([Tenant::normalKode($kode)]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Perusahaan yang boleh dibuka satu akun, lengkap dengan perannya.
     *
     * @return array<int,array{kode:string,nama:string,db_name:string,peran:string}>
     */
    public static function perusahaanAkun(int $akunId): array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT p.*, a.peran
               FROM anggota a
               JOIN perusahaan p ON p.id = a.perusahaan_id
              WHERE a.akun_id = ? AND p.is_active = 1
              ORDER BY p.nama'
        );
        $st->execute([$akunId]);
        return $st->fetchAll();
    }

    /** Peran akun di satu perusahaan, atau null bila bukan anggota. */
    public static function peran(int $akunId, string $kode): ?string
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT a.peran FROM anggota a JOIN perusahaan p ON p.id = a.perusahaan_id
              WHERE a.akun_id = ? AND p.kode = ? AND p.is_active = 1'
        );
        $st->execute([$akunId, Tenant::normalKode($kode)]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /**
     * Mendaftarkan PT baru: membuat databasenya, memasang skema, lalu
     * menjadikan akun pemanggil sebagai pemiliknya.
     *
     * Seluruh langkah dijalankan berurutan dan yang paling mahal - membuat
     * database - dilakukan lebih dulu, supaya baris di tabel perusahaan tidak
     * pernah menunjuk database yang gagal dibuat.
     *
     * @return array{kode:string,nama:string,db:string}
     */
    public static function daftarkanPerusahaan(
        string $kode,
        string $nama,
        string $db,
        int $akunId
    ): array {
        $kode = Tenant::normalKode($kode);
        $nama = trim($nama);
        $db   = trim($db);

        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $kode) !== 1) {
            throw new RuntimeException(
                'Kode perusahaan hanya boleh huruf kecil, angka, garis bawah, dan strip (minimal 2 karakter).'
            );
        }
        if ($nama === '') {
            throw new RuntimeException('Nama perusahaan wajib diisi.');
        }
        if (!Tenant::namaDbAman($db)) {
            throw new RuntimeException('Nama database hanya boleh huruf, angka, garis bawah, dan strip.');
        }
        $pdo = self::pdo();
        if ($pdo === null) {
            throw new RuntimeException('Database pusat belum siap.');
        }
        if (self::perusahaanByKode($kode) !== null) {
            throw new RuntimeException('Kode perusahaan tersebut sudah dipakai.');
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM perusahaan WHERE db_name = ?');
        $st->execute([$db]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException('Database tersebut sudah dipakai perusahaan lain.');
        }

        $akun = self::akunById($akunId);
        if ($akun === null) {
            throw new RuntimeException('Akun tidak ditemukan.');
        }

        Pemasang::buatDatabase($db);
        $dbPdo = Pemasang::sambung($db);
        Pemasang::jalankanSkema($dbPdo);
        Pemasang::buatAkunDireksi($dbPdo);
        Pemasang::pastikanPengguna($dbPdo, (string) $akun['email'], (string) $akun['nama'], true);

        $pdo->prepare('INSERT INTO perusahaan (kode, nama, db_name) VALUES (?,?,?)')
            ->execute([$kode, $nama, $db]);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO anggota (akun_id, perusahaan_id, peran) VALUES (?,?,?)')
            ->execute([$akunId, $pid, 'pemilik']);

        Tenant::lupakan();
        return ['kode' => $kode, 'nama' => $nama, 'db' => $db];
    }

    /** Mengganti nama tampilan satu PT. Kode dan databasenya tidak berubah. */
    public static function ubahNama(int $perusahaanId, string $nama): void
    {
        $nama = trim($nama);
        if ($nama === '') {
            throw new RuntimeException('Nama perusahaan tidak boleh kosong.');
        }
        self::pdo()?->prepare('UPDATE perusahaan SET nama = ? WHERE id = ?')
            ->execute([$nama, $perusahaanId]);
        Tenant::lupakan();
    }

    /**
     * Mengganti nama database satu PT, beserta isinya.
     *
     * @return array{tabel:int,view:int,lama_dibuang:bool,sisa:int}
     */
    public static function ubahDatabase(int $perusahaanId, string $dbBaru): array
    {
        $dbBaru = trim($dbBaru);
        $pdo = self::pdo();
        if ($pdo === null) {
            throw new RuntimeException('Database pusat belum siap.');
        }

        $st = $pdo->prepare('SELECT db_name FROM perusahaan WHERE id = ?');
        $st->execute([$perusahaanId]);
        $lama = $st->fetchColumn();
        if ($lama === false) {
            throw new RuntimeException('Perusahaan tidak ditemukan.');
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM perusahaan WHERE db_name = ? AND id <> ?');
        $st->execute([$dbBaru, $perusahaanId]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException('Database tersebut sudah dipakai perusahaan lain.');
        }

        $hasil = Pemasang::pindahDatabase((string) $lama, $dbBaru);

        // Catatan pusat diperbarui terakhir: kalau pemindahannya gagal di
        // tengah jalan, daftar pusat masih menunjuk database yang benar.
        $pdo->prepare('UPDATE perusahaan SET db_name = ? WHERE id = ?')->execute([$dbBaru, $perusahaanId]);

        // Sambungan yang sedang dipakai menunjuk database yang barusan hilang.
        Tenant::lupakan();
        Db::reset();
        return $hasil;
    }

    /**
     * Mendaftarkan PT yang databasenya SUDAH ada ke dalam daftar pusat.
     *
     * Dipakai saat pemasangan lama beralih ke akun pusat: databasenya tidak
     * disentuh sama sekali, hanya dicatat dan diberi pemilik.
     */
    public static function daftarkanPerusahaanYangAda(
        string $kode,
        string $nama,
        string $db,
        int $akunId
    ): void {
        $kode = Tenant::normalKode($kode);
        $pdo = self::pdo();
        if ($pdo === null) {
            throw new RuntimeException('Database pusat belum siap.');
        }
        $akun = self::akunById($akunId);
        if ($akun === null) {
            throw new RuntimeException('Akun tidak ditemukan.');
        }
        if (!Tenant::namaDbAman($db)) {
            throw new RuntimeException('Nama database tidak valid: ' . $db);
        }

        $ada = self::perusahaanByKode($kode);
        if ($ada === null) {
            $pdo->prepare('INSERT INTO perusahaan (kode, nama, db_name) VALUES (?,?,?)')
                ->execute([$kode, trim($nama) ?: $kode, $db]);
            $pid = (int) $pdo->lastInsertId();
        } else {
            $pid = (int) $ada['id'];
        }

        $pdo->prepare(
            'INSERT INTO anggota (akun_id, perusahaan_id, peran) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE peran = VALUES(peran)'
        )->execute([$akunId, $pid, 'pemilik']);

        Pemasang::pastikanPengguna(
            Pemasang::sambung($db),
            (string) $akun['email'],
            (string) $akun['nama'],
            true
        );
    }

    // ------------------------------------------------------------- anggota

    /** @return array<int,array> anggota satu perusahaan beserta emailnya */
    public static function anggota(int $perusahaanId): array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT k.email, k.nama, k.id AS akun_id, a.peran, k.last_login_at
               FROM anggota a JOIN akun k ON k.id = a.akun_id
              WHERE a.perusahaan_id = ?
              ORDER BY FIELD(a.peran, "pemilik", "admin", "staf"), k.email'
        );
        $st->execute([$perusahaanId]);
        return $st->fetchAll();
    }

    /**
     * Memberi satu akun akses ke satu perusahaan, sekaligus menyiapkan baris
     * penggunanya di database PT tersebut.
     */
    public static function tambahAnggota(int $akunId, array $perusahaan, string $peran): void
    {
        if (!in_array($peran, self::PERAN, true)) {
            throw new RuntimeException('Peran tidak dikenal.');
        }
        $akun = self::akunById($akunId);
        if ($akun === null) {
            throw new RuntimeException('Akun tidak ditemukan.');
        }
        $pdo = self::pdo();
        if ($pdo === null) {
            throw new RuntimeException('Database pusat belum siap.');
        }

        $pdo->prepare(
            'INSERT INTO anggota (akun_id, perusahaan_id, peran) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE peran = VALUES(peran)'
        )->execute([$akunId, (int) $perusahaan['id'], $peran]);

        $dbPdo = Pemasang::sambung((string) $perusahaan['db_name']);
        Pemasang::pastikanPengguna(
            $dbPdo,
            (string) $akun['email'],
            (string) $akun['nama'],
            $peran !== 'staf'
        );
    }

    /**
     * Mencabut akses satu akun dari satu perusahaan.
     *
     * Baris penggunanya di database PT dinonaktifkan, bukan dihapus, supaya
     * riwayat unggahan yang menunjuk ke pengguna itu tetap utuh.
     */
    public static function hapusAnggota(int $akunId, array $perusahaan): void
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return;
        }
        $akun = self::akunById($akunId);
        $pdo->prepare('DELETE FROM anggota WHERE akun_id = ? AND perusahaan_id = ?')
            ->execute([$akunId, (int) $perusahaan['id']]);

        if ($akun !== null) {
            try {
                Pemasang::sambung((string) $perusahaan['db_name'])
                    ->prepare('UPDATE users SET is_active = 0 WHERE username = ?')
                    ->execute([$akun['email']]);
            } catch (Throwable) {
                // Database PT sedang tidak bisa dihubungi: keanggotaan pusat
                // sudah dicabut, jadi orangnya tetap tidak bisa masuk.
            }
        }
    }

    /** Jumlah pemilik satu perusahaan - dipakai agar pemilik terakhir tidak hilang. */
    public static function jumlahPemilik(int $perusahaanId): int
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return 0;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM anggota WHERE perusahaan_id = ? AND peran = "pemilik"');
        $st->execute([$perusahaanId]);
        return (int) $st->fetchColumn();
    }
}
