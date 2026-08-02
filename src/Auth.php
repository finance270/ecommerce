<?php
declare(strict_types=1);

final class Auth
{
    /** Kunci sesi untuk identitas pusat (email), bertahan saat pindah PT. */
    public const SESI_AKUN = 'akun';

    private static ?array $cache = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$cache;
        }
        self::$loaded = true;

        $id = $_SESSION['uid'] ?? null;
        if ($id === null) {
            // Masuk lewat akun pusat: id pengguna di database PT ini belum
            // ditentukan (misalnya baru berpindah PT). Ditentukan sekarang,
            // setelah keanggotaannya di PT ini diperiksa.
            $id = self::sambungkanAkun();
            if ($id === null) {
                return self::$cache = null;
            }
        }

        // Sengaja SELECT * : pada instalasi lama kolom permissions dan
        // salary_access belum ada, dan setup.php - satu-satunya halaman yang
        // menambahkannya - memanggil fungsi ini lebih dulu. Menyebut kolomnya
        // secara eksplisit membuat proses pembaruan tidak akan pernah bisa
        // dijalankan. Nilai yang belum ada ditangani lewat ?? di bawah.
        $row = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
        if ($row === null) {
            unset($_SESSION['uid']);
            return self::$cache = null;
        }
        unset($row['password_hash']);
        return self::$cache = $row;
    }

    /**
     * Hubungkan akun pusat yang sedang masuk dengan barisnya di database PT
     * yang sedang aktif.
     *
     * Keanggotaan diperiksa di sini, bukan hanya saat memilih PT: dengan
     * begitu mencabut akses seseorang langsung berlaku pada permintaan
     * berikutnya, tanpa menunggu sesinya berakhir.
     *
     * @return int|null id baris users, atau null bila tidak berhak
     */
    private static function sambungkanAkun(): ?int
    {
        $akunId = (int) ($_SESSION[self::SESI_AKUN] ?? 0);
        if ($akunId <= 0 || !Pusat::tersedia()) {
            return null;
        }
        $akun = Pusat::akunById($akunId);
        if ($akun === null) {
            unset($_SESSION[self::SESI_AKUN]);
            return null;
        }
        $peran = Pusat::peran($akunId, Tenant::kodeAktif());
        if ($peran === null) {
            return null;   // bukan anggota PT ini
        }

        try {
            $id = Pemasang::pastikanPengguna(
                Db::conn(),
                (string) $akun['email'],
                (string) $akun['nama'],
                $peran !== 'staf'
            );
        } catch (Throwable) {
            return null;   // database PT belum terpasang
        }
        $_SESSION['uid'] = $id;
        return $id;
    }

    /** Akun pusat yang sedang masuk, bila memang masuk lewat email. */
    public static function akun(): ?array
    {
        $id = (int) ($_SESSION[self::SESI_AKUN] ?? 0);
        return $id > 0 ? Pusat::akunById($id) : null;
    }

    /**
     * Masuk dengan email lewat database pusat.
     * Belum menentukan PT - itu dilakukan terpisah lewat masukPerusahaan().
     */
    public static function attemptAkun(string $email, string $password): bool
    {
        if (!Pusat::tersedia()) {
            return false;
        }
        $akun = Pusat::akunByEmail($email);
        if ($akun === null || !password_verify($password, (string) $akun['password_hash'])) {
            return false;
        }
        $tenant = $_SESSION[Tenant::SESSION_KEY] ?? null;
        $_SESSION = [];
        session_regenerate_id(true);
        if ($tenant !== null) {
            $_SESSION[Tenant::SESSION_KEY] = $tenant;
        }
        $_SESSION[self::SESI_AKUN] = (int) $akun['id'];
        self::$loaded = false;
        self::$cache = null;
        Pusat::catatMasuk((int) $akun['id']);
        return true;
    }

    /**
     * Buka satu PT untuk akun pusat yang sedang masuk.
     * Mengembalikan false bila akun itu bukan anggota PT tersebut.
     */
    public static function masukPerusahaan(string $kode): bool
    {
        $akunId = (int) ($_SESSION[self::SESI_AKUN] ?? 0);
        if ($akunId <= 0 || Pusat::peran($akunId, $kode) === null) {
            return false;
        }
        if (!Tenant::pilih($kode)) {
            return false;
        }
        unset($_SESSION['uid']);
        self::$loaded = false;
        self::$cache = null;
        return self::user() !== null;
    }

    public static function require(): array
    {
        $u = self::user();
        if ($u === null) {
            // Akun pusatnya masih masuk, hanya PT ini yang tidak boleh dibuka.
            // Menyuruh masuk ulang akan membingungkan - yang perlu dia lakukan
            // adalah memilih PT lain.
            $adaAkun = (int) ($_SESSION[self::SESI_AKUN] ?? 0) > 0;
            header('Location: ' . ($adaAkun ? 'pilih-perusahaan.php' : 'login.php'));
            exit;
        }
        return $u;
    }

    /**
     * Pastikan pengguna boleh membuka tab tertentu.
     * Pemeriksaan dilakukan di setiap halaman, bukan hanya dengan
     * menyembunyikan menu - menyembunyikan menu saja bukan pengamanan.
     */
    public static function requireTab(string $tab): array
    {
        $u = self::require();
        if (!self::can($tab)) {
            http_response_code(403);
            require_once __DIR__ . '/../public/_layout.php';
            render_head('Akses ditolak', '');
            echo '<div class="alert bad"><b>Akses ditolak.</b> Akun Anda tidak diberi hak untuk '
                . 'membuka halaman ini. Hubungi administrator bila ini keliru.</div>';

            // Arahkan ke tab pertama yang boleh dibuka. Pengguna yang belum
            // diberi hak apa pun tidak punya tujuan sama sekali, jadi tawarkan
            // keluar - bukan tautan yang berujung di halaman ini lagi.
            $tabs = self::allowedTabs();
            if ($tabs !== []) {
                [$label, $file] = Perm::TABS[$tabs[0]];
                echo '<p><a class="btn ghost" href="' . e($file) . '">Kembali ke ' . e($label) . '</a></p>';
            } else {
                echo '<p class="muted">Akun Anda belum diberi hak atas satu halaman pun. '
                    . 'Minta administrator mengatur hak akses di menu Pengguna.</p>'
                    . '<p><a class="btn ghost" href="logout.php">Keluar</a></p>';
            }
            render_foot();
            exit;
        }
        return $u;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** Daftar tab yang boleh dibuka pengguna saat ini. */
    public static function allowedTabs(): array
    {
        $u = self::user();
        if ($u === null) {
            return [];
        }
        if (($u['role'] ?? '') === 'admin') {
            return array_keys(Perm::TABS);
        }

        $raw = $u['permissions'] ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            return [];   // belum diberi hak apa pun
        }
        $list = json_decode((string) $raw, true);
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_intersect($list, array_keys(Perm::TABS)));
    }

    public static function can(string $tab): bool
    {
        if (in_array($tab, Perm::ADMIN_ONLY_TABS, true)) {
            return self::isAdmin();
        }
        return in_array($tab, self::allowedTabs(), true);
    }

    /** Hanya admin yang boleh menghapus data. */
    public static function canDelete(): bool
    {
        return self::isAdmin();
    }

    /** 'all' | 'only' (hanya gaji) | 'none' (tanpa gaji) */
    public static function salaryAccess(): string
    {
        $u = self::user();
        if ($u === null) {
            return 'none';
        }
        if (($u['role'] ?? '') === 'admin') {
            return 'all';
        }
        $a = (string) ($u['salary_access'] ?? 'all');
        return in_array($a, ['all', 'only', 'none'], true) ? $a : 'all';
    }

    /**
     * Masuk sebagai direksi: hanya kata sandi, tanpa username.
     *
     * Dipakai halaman berbagi tersendiri (direksi.php) supaya bisa dikirim ke
     * direksi tanpa membagikan akun operasional. Hak aksesnya tetap mengikuti
     * kolom permissions akun tersebut, jadi admin bisa mengaturnya dari menu
     * Pengguna seperti akun lain.
     */
    public static function attemptDireksi(string $password): bool
    {
        $row = Db::one(
            'SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1',
            [Perm::AKUN_DIREKSI]
        );
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        // Identitas pusat dibuang: sesi direksi hanya boleh berisi satu hal,
        // yaitu akses baca ke PT yang tautannya dibagikan. Kalau tidak,
        // membuka tautan direksi dari peramban yang sedang masuk sebagai
        // pemilik akan mewariskan tombol pindah PT ke sesi itu.
        unset($_SESSION[self::SESI_AKUN]);
        $_SESSION['uid'] = (int) $row['id'];
        self::$loaded = false;
        self::$cache = null;
        Db::q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
        return true;
    }

    /** Apakah sesi ini masuk lewat halaman direksi? */
    public static function isDireksi(): bool
    {
        return (self::user()['username'] ?? '') === Perm::AKUN_DIREKSI;
    }

    /** Batas jumlah PT yang ditelusuri saat mencari asal sebuah username. */
    public const LINTAS_MAKS = 25;

    /**
     * Cari di PT mana sebuah username + kata sandi berlaku.
     *
     * Akun yang dibuat lewat menu Pengguna hanya ada di database PT-nya
     * sendiri, sedangkan pengunjung baru selalu mendarat di PT pertama. Tanpa
     * penelusuran ini, orang tersebut akan ditolak terus tanpa tahu sebabnya -
     * satu-satunya jalan masuknya adalah tautan ?db= yang mungkin tidak pernah
     * dia terima.
     *
     * Yang dikembalikan adalah SEMUA PT yang cocok; pemanggilnya menolak bila
     * lebih dari satu, karena berarti username dan kata sandi itu tidak cukup
     * untuk menentukan orangnya.
     *
     * @return string[] kode PT yang cocok
     */
    public static function cariPerusahaan(string $username, string $password, string $kecuali = ''): array
    {
        // Akun direksi memakai kata sandi awal yang sama di semua PT, jadi
        // tidak boleh ikut ditelusuri. Pintunya memang hanya direksi.php.
        if ($username === Perm::AKUN_DIREKSI || $username === '' || $password === '') {
            return [];
        }

        $cocok = [];
        $n = 0;
        foreach (Tenant::all() as $kode => $t) {
            if ($kode === $kecuali || ++$n > self::LINTAS_MAKS) {
                continue;
            }
            try {
                $st = Pemasang::sambung((string) $t['db'])
                    ->prepare('SELECT password_hash FROM users WHERE username = ? AND is_active = 1');
                $st->execute([$username]);
                $row = $st->fetch();
            } catch (Throwable) {
                continue;   // database PT itu sedang tidak bisa dihubungi
            }
            if ($row !== false && password_verify($password, (string) $row['password_hash'])) {
                $cocok[] = $kode;
            }
        }
        return $cocok;
    }

    public static function attempt(string $username, string $password): bool
    {
        // Akun direksi sengaja tidak bisa dipakai di halaman masuk biasa -
        // pintunya hanya direksi.php.
        if ($username === Perm::AKUN_DIREKSI) {
            return false;
        }
        $row = Db::one('SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1', [$username]);
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];
        self::$loaded = false;
        self::$cache = null;
        Db::q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
        return true;
    }

    public static function logout(): void
    {
        // Perusahaan yang sedang dibuka dipertahankan supaya setelah keluar
        // pengguna mendarat di halaman masuk perusahaan yang sama.
        $tenant = $_SESSION[Tenant::SESSION_KEY] ?? null;
        $_SESSION = [];
        // Id sesi tetap diganti supaya sesi lama tidak bisa dipakai ulang.
        session_regenerate_id(true);
        if ($tenant !== null) {
            $_SESSION[Tenant::SESSION_KEY] = $tenant;
        }
        self::$loaded = false;
        self::$cache = null;
    }

    /** Token CSRF untuk form yang mengubah data. */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
