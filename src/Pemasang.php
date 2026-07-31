<?php
declare(strict_types=1);

/**
 * Pemasangan skema untuk satu database perusahaan.
 *
 * Dipakai dua tempat: halaman Pemasangan (setup.php) untuk perusahaan yang
 * sudah ada, dan halaman Perusahaan saat menambah PT baru. Logikanya
 * dikumpulkan di sini supaya keduanya tidak pernah berbeda cara memasangnya.
 */
final class Pemasang
{
    /**
     * Membuat database perusahaan bila belum ada.
     *
     * Sambungannya dibuat tanpa nama database - PDO tidak bisa dipakai untuk
     * CREATE DATABASE kalau database tujuannya belum ada.
     */
    public static function buatDatabase(string $namaDb): void
    {
        if (!Tenant::namaDbAman($namaDb)) {
            throw new RuntimeException('Nama database tidak valid: ' . $namaDb);
        }
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('db_host'), Config::get('db_port')),
            (string) Config::get('db_user'),
            (string) Config::get('db_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS `' . $namaDb . '` '
            . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    /** Sambungan langsung ke satu database, di luar perusahaan yang aktif. */
    public static function sambung(string $namaDb): PDO
    {
        if (!Tenant::namaDbAman($namaDb)) {
            throw new RuntimeException('Nama database tidak valid: ' . $namaDb);
        }
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('db_host'),
                Config::get('db_port'),
                $namaDb
            ),
            (string) Config::get('db_user'),
            (string) Config::get('db_pass'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+07:00'",
            ]
        );
    }

    /**
     * Menjalankan database/schema.sql.
     *
     * @return int jumlah pernyataan yang dijalankan
     */
    public static function jalankanSkema(PDO $pdo): int
    {
        $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Berkas database/schema.sql tidak ditemukan.');
        }

        // Buang dulu baris komentar, baru dipecah per pernyataan. Kalau komentar
        // tidak dibuang lebih dulu, blok komentar di atas tiap CREATE TABLE ikut
        // terbawa dan pernyataannya justru terlewat.
        $lines = preg_split('/\R/', $sql) ?: [];
        $body = implode("\n", array_filter(
            $lines,
            static fn(string $l): bool => preg_match('/^\s*--/', $l) !== 1
        ));

        $applied = 0;
        // Skema tidak memakai trigger/prosedur, sehingga pemisahan dengan
        // titik koma di akhir baris aman.
        foreach (preg_split('/;\s*\R/', $body) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
            $applied++;
        }
        if ($applied === 0) {
            throw new RuntimeException('Tidak ada pernyataan SQL yang dijalankan; periksa isi database/schema.sql.');
        }
        return $applied;
    }

    /**
     * Membuat akun direksi bila belum ada.
     *
     * Dipanggil SETELAH skema, bukan bersama migrasi kolom: pada pemasangan
     * baru tabel users belum ada saat migrasi dijalankan, sehingga akunnya
     * tidak akan pernah terbuat.
     */
    public static function buatAkunDireksi(PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([Perm::AKUN_DIREKSI]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, permissions, salary_access, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            Perm::AKUN_DIREKSI,
            password_hash('123', PASSWORD_DEFAULT),
            'Direksi',
            'viewer',
            json_encode(['pnl']),
            'all',
        ]);
        return true;
    }

    /**
     * Memastikan satu akun pusat punya baris pengguna di database perusahaan.
     *
     * Identitas (email + kata sandi) disimpan terpusat, tetapi hak akses per
     * tab tetap milik masing-masing database - itulah yang membuat satu orang
     * bisa jadi admin di PT A dan hanya melihat laba rugi di PT B.
     *
     * @return int id baris users di database perusahaan tersebut
     */
    public static function pastikanPengguna(PDO $pdo, string $email, string $nama, bool $admin): int
    {
        $st = $pdo->prepare('SELECT id, role FROM users WHERE username = ?');
        $st->execute([$email]);
        $row = $st->fetch();

        if ($row !== false) {
            // Peran disamakan dengan keanggotaan pusat supaya pencabutan hak
            // admin di halaman Perusahaan benar-benar berlaku di sini.
            $pdo->prepare('UPDATE users SET role = ?, is_active = 1 WHERE id = ?')
                ->execute([$admin ? 'admin' : ($row['role'] === 'admin' ? 'viewer' : $row['role']), $row['id']]);
            return (int) $row['id'];
        }

        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, permissions, salary_access, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $email,
            // Kata sandi tidak disimpan di sini: masuknya lewat akun pusat.
            // Nilai acak dipakai supaya baris ini tidak pernah bisa dipakai
            // masuk lewat halaman masuk per perusahaan.
            password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT),
            $nama !== '' ? $nama : $email,
            $admin ? 'admin' : 'viewer',
            $admin ? null : json_encode([]),
            'all',
        ]);
        return (int) $pdo->lastInsertId();
    }
}
