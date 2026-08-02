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
     * Memindahkan seluruh isi satu database ke nama database lain.
     *
     * MariaDB tidak punya perintah "ganti nama database", jadi caranya adalah
     * memindahkan tabelnya satu per satu ke database baru. Untungnya
     * RENAME TABLE hanya mengubah catatan, bukan menyalin data - jadi
     * secepat apa pun besar datanya, dan seluruh tabel dipindahkan dalam satu
     * pernyataan sehingga tidak ada keadaan setengah jadi.
     *
     * View sengaja tidak ikut dipindahkan: definisinya menyebut nama database
     * lama secara eksplisit, sehingga kalau dipindahkan justru membawa nama
     * lama ikut serta. Lebih bersih dibuat ulang dari schema.sql.
     *
     * @return array{tabel:int,view:int,lama_dibuang:bool,sisa:int}
     */
    public static function pindahDatabase(string $lama, string $baru): array
    {
        $baru = trim($baru);
        if (!Tenant::namaDbAman($lama) || !Tenant::namaDbAman($baru)) {
            throw new RuntimeException('Nama database hanya boleh huruf, angka, garis bawah, dan strip.');
        }
        if ($lama === $baru) {
            throw new RuntimeException('Nama database barunya sama dengan yang sekarang.');
        }
        if ($baru === Pusat::namaDb()) {
            throw new RuntimeException('Nama itu dipakai database pusat, pilih nama lain.');
        }

        $root = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('db_host'), Config::get('db_port')),
            (string) Config::get('db_user'),
            (string) Config::get('db_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $st = $root->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
        $st->execute([$baru]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException(
                'Database ' . $baru . ' sudah ada. Pilih nama lain supaya tidak menimpa data yang ada di sana.'
            );
        }

        // Nama kolom information_schema diberi alias supaya tidak bergantung
        // pada besar-kecil huruf yang dikembalikan server.
        $st = $root->prepare(
            'SELECT table_name AS nama, table_type AS jenis
               FROM information_schema.tables WHERE table_schema = ?'
        );
        $st->execute([$lama]);

        $tabel = [];
        $view  = [];
        foreach ($st->fetchAll() as $r) {
            if ((string) $r['jenis'] === 'VIEW') {
                $view[] = (string) $r['nama'];
            } else {
                $tabel[] = (string) $r['nama'];
            }
        }
        if ($tabel === []) {
            throw new RuntimeException('Database ' . $lama . ' tidak berisi tabel apa pun.');
        }

        $root->exec(
            'CREATE DATABASE `' . $baru . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        foreach ($view as $v) {
            $root->exec('DROP VIEW IF EXISTS `' . $lama . '`.`' . $v . '`');
        }

        $pasangan = [];
        foreach ($tabel as $t) {
            $pasangan[] = '`' . $lama . '`.`' . $t . '` TO `' . $baru . '`.`' . $t . '`';
        }
        $root->exec('RENAME TABLE ' . implode(', ', $pasangan));

        // Menjalankan skema di database baru akan membuat ulang view-nya;
        // tabel yang sudah pindah dilewati karena CREATE TABLE IF NOT EXISTS.
        self::jalankanSkema(self::sambung($baru));

        // Database lama baru dibuang setelah dipastikan benar-benar kosong.
        $st = $root->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
        $st->execute([$lama]);
        $sisa = (int) $st->fetchColumn();
        if ($sisa === 0) {
            $root->exec('DROP DATABASE `' . $lama . '`');
        }

        return [
            'tabel'        => count($tabel),
            'view'         => count($view),
            'lama_dibuang' => $sisa === 0,
            'sisa'         => $sisa,
        ];
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
