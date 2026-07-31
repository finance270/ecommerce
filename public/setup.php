<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Pemasangan sekali jalan: membuat tabel dan akun admin pertama.
 * Setelah ada user, halaman ini hanya bisa diakses admin (untuk migrasi ulang).
 */

/**
 * Menambahkan kolom yang belum ada pada instalasi lama.
 * Aman dijalankan berulang: kolom yang sudah ada dilewati.
 *
 * @return string[] daftar perubahan yang benar-benar diterapkan
 */
function columnExists(PDO $pdo, string $dbName, string $table, string $column): bool
{
    return (int) ($pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = " . $pdo->quote($dbName) . "
           AND table_name = " . $pdo->quote($table) . "
           AND column_name = " . $pdo->quote($column)
    )->fetchColumn() ?: 0) > 0;
}

/**
 * Memindahkan kolom raw_json ke tabel arsip terpisah.
 *
 * Kolom JSON berukuran 2-3 KB per baris membuat tabel orders/settlements
 * membengkak sampai ratusan MB, sehingga setiap perhitungan laporan harus
 * membaca data yang sebenarnya tidak dipakai. Disalin bertahap agar tidak
 * membebani server kecil.
 */
function moveRawJson(PDO $pdo, string $dbName, string $table, string $rawTable, string $fkColumn): bool
{
    if (!columnExists($pdo, $dbName, $table, 'raw_json')) {
        return false;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$rawTable}` (
        `{$fkColumn}` BIGINT UNSIGNED NOT NULL,
        raw_json LONGTEXT NULL,
        PRIMARY KEY (`{$fkColumn}`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $lastId = 0;
    do {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO `{$rawTable}` (`{$fkColumn}`, raw_json)
             SELECT id, raw_json FROM `{$table}`
             WHERE id > ? AND raw_json IS NOT NULL
             ORDER BY id LIMIT 2000"
        );
        $stmt->execute([$lastId]);

        $next = (int) $pdo->query(
            "SELECT MAX(id) FROM (SELECT id FROM `{$table}` WHERE id > {$lastId} ORDER BY id LIMIT 2000) x"
        )->fetchColumn();
        if ($next <= $lastId) {
            break;
        }
        $lastId = $next;
    } while (true);

    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN raw_json");

    // MariaDB membuang kolom secara "instant": datanya masih menempati baris
    // sampai tabel dibangun ulang. Tanpa langkah ini ukuran tabel tidak turun
    // dan laporan tetap lambat.
    $pdo->exec("ALTER TABLE `{$table}` FORCE");
    return true;
}

/**
 * Mengisi order_items.cost_key untuk baris lama.
 *
 * Dihitung di PHP, bukan lewat SHA1() di SQL, supaya normalisasinya persis
 * sama dengan yang dipakai saat impor (huruf kecil, spasi ganda dirapikan).
 * Kombinasi nama+variasi jumlahnya sedikit, jadi cukup beberapa ratus UPDATE.
 */
function backfillCostKeys(PDO $pdo): int
{
    $combos = $pdo->query(
        'SELECT product_name, variation FROM order_items
         WHERE cost_key IS NULL GROUP BY product_name, variation'
    )->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare(
        'UPDATE order_items SET cost_key = ?
         WHERE cost_key IS NULL AND product_name <=> ? AND variation <=> ?'
    );
    foreach ($combos as $c) {
        $st->execute([
            Value::costKey($c['product_name'], $c['variation']),
            $c['product_name'],
            $c['variation'],
        ]);
    }
    return count($combos);
}

/**
 * Membuat akun direksi bila belum ada.
 *
 * Dipanggil SETELAH schema.sql, bukan di dalam runMigrations(): pada
 * pemasangan baru tabel users belum ada saat migrasi kolom dijalankan,
 * sehingga akunnya tidak akan pernah terbuat.
 */
function buatAkunDireksi(PDO $pdo): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([Perm::AKUN_DIREKSI]);
    if ((int) $st->fetchColumn() > 0) {
        return false;
    }
    $ins = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, permissions, salary_access, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $ins->execute([
        Perm::AKUN_DIREKSI,
        password_hash('123', PASSWORD_DEFAULT),
        'Direksi',
        'viewer',
        json_encode(['pnl']),
        'all',
    ]);
    return true;
}

function runMigrations(PDO $pdo, string $dbName): array
{
    $wanted = [
        ['settlements', 'total_potongan',
         "ALTER TABLE settlements ADD COLUMN total_potongan DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_seller"],
        ['order_items', 'cost_key',
         "ALTER TABLE order_items ADD COLUMN cost_key CHAR(40) NULL AFTER status_norm"],
        ['users', 'permissions',
         "ALTER TABLE users ADD COLUMN permissions TEXT NULL AFTER role"],
        ['users', 'salary_access',
         "ALTER TABLE users ADD COLUMN salary_access ENUM('all','only','none') NOT NULL DEFAULT 'all' AFTER permissions"],
    ];

    $done = [];
    foreach ($wanted as [$table, $column, $sql]) {
        // Tabel belum ada = pemasangan baru; kolomnya akan ikut terbuat
        // lewat CREATE TABLE pada schema.sql.
        $tableExists = (int) ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = " . $pdo->quote($dbName) . "
               AND table_name = " . $pdo->quote($table)
        )->fetchColumn() ?: 0);
        if ($tableExists === 0) {
            continue;
        }

        $exists = (int) ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = " . $pdo->quote($dbName) . "
               AND table_name = " . $pdo->quote($table) . "
               AND column_name = " . $pdo->quote($column)
        )->fetchColumn() ?: 0);
        if ($exists === 0) {
            $pdo->exec($sql);
            $done[] = "{$table}.{$column}";
        }
    }

    foreach ([['orders', 'order_raw', 'order_pk'], ['settlements', 'settlement_raw', 'settlement_id']] as [$t, $rt, $fk]) {
        $tableExists = (int) ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = " . $pdo->quote($dbName) . " AND table_name = " . $pdo->quote($t)
        )->fetchColumn() ?: 0);
        if ($tableExists > 0 && moveRawJson($pdo, $dbName, $t, $rt, $fk)) {
            $done[] = "{$t}.raw_json dipindah ke {$rt}";
        }
    }

    // Isi kunci pencocokan HPP untuk baris yang sudah ada.
    $tableExists = (int) ($pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = " . $pdo->quote($dbName) . " AND table_name = 'order_items'"
    )->fetchColumn() ?: 0);
    if ($tableExists > 0 && columnExists($pdo, $dbName, 'order_items', 'cost_key')) {
        $filled = backfillCostKeys($pdo);
        if ($filled > 0) {
            $done[] = "kunci HPP untuk {$filled} kombinasi produk";
        }
    }

    // Nilai 0 kini berarti "belum diisi" dan tidak pernah ikut dilaporkan.
    // Baris nol yang terlanjur tersimpan pada instalasi lama dibuang supaya
    // isi tabel sama dengan yang tampil di laporan - kalau dibiarkan, barisnya
    // tetap ada di database tetapi tidak pernah muncul di mana pun.
    foreach ([['operating_expense', 'amount'], ['product_cost', 'cost_per_unit']] as [$t, $col]) {
        $tableExists = (int) ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = " . $pdo->quote($dbName) . " AND table_name = " . $pdo->quote($t)
        )->fetchColumn() ?: 0);
        if ($tableExists === 0) {
            continue;
        }
        $n = $pdo->exec("DELETE FROM {$t} WHERE {$col} = 0");
        if ($n > 0) {
            $done[] = "{$n} baris bernilai 0 dihapus dari {$t}";
        }
    }

    // Index tambahan untuk mempercepat laporan pada instalasi lama.
    foreach ([
        ['settlements', 'idx_settlements_pf_date', 'ALTER TABLE settlements ADD INDEX idx_settlements_pf_date (platform, settlement_date)'],
        ['settlement_fees', 'idx_fee_date', 'ALTER TABLE settlement_fees ADD INDEX idx_fee_date (settlement_date, platform)'],
        ['settlements', 'idx_settlements_alloc',
         'ALTER TABLE settlements ADD INDEX idx_settlements_alloc (platform, order_id, settlement_date, gross_amount, total_potongan, refund_amount, total_fee, net_amount)'],
        ['orders', 'idx_orders_alloc', 'ALTER TABLE orders ADD INDEX idx_orders_alloc (platform, order_id, items_subtotal_before)'],
        // Dipakai halaman rincian produk, yang menyaring baris item per kunci HPP.
        ['order_items', 'idx_items_cost_key', 'ALTER TABLE order_items ADD INDEX idx_items_cost_key (cost_key)'],
        // Dipakai rincian biaya pada simulasi harga: tanpa ini seluruh tabel
        // biaya (ratusan ribu baris) harus dipindai untuk tiap produk.
        ['settlement_fees', 'idx_fee_order', 'ALTER TABLE settlement_fees ADD INDEX idx_fee_order (platform, order_id)'],
    ] as [$t, $idx, $sql]) {
        $has = (int) ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = " . $pdo->quote($dbName) . "
               AND table_name = " . $pdo->quote($t) . "
               AND index_name = " . $pdo->quote($idx)
        )->fetchColumn() ?: 0);
        $tableExists = (int) ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = " . $pdo->quote($dbName) . " AND table_name = " . $pdo->quote($t)
        )->fetchColumn() ?: 0);
        if ($tableExists > 0 && $has === 0) {
            $pdo->exec($sql);
            $done[] = "index {$idx}";
        }
    }

    return $done;
}

$err = null;
$done = false;
$dbOk = false;
$hasUsers = false;
$migrated = [];

$dbBaruDibuat = false;

try {
    Db::conn();
    $dbOk = true;
    $hasUsers = Db::isInstalled()
        && (int) Db::val('SELECT COUNT(*) FROM users', [], 0) > 0;
} catch (Throwable $e) {
    // Database perusahaan ini belum ada: coba buat dulu. Pada pemasangan
    // banyak perusahaan, tiap perusahaan punya database sendiri dan tidak
    // masuk akal menyuruh admin membuatnya manual satu per satu.
    $namaDb = Tenant::aktif()['db'];
    try {
        if (!Tenant::namaDbAman($namaDb)) {
            throw new RuntimeException('Nama database tidak valid: ' . $namaDb);
        }
        $root = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('db_host'), Config::get('db_port')),
            (string) Config::get('db_user'),
            (string) Config::get('db_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $root->exec(
            'CREATE DATABASE IF NOT EXISTS `' . $namaDb . '` '
            . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        Db::reset();
        Db::conn();
        $dbOk = true;
        $dbBaruDibuat = true;
        $hasUsers = Db::isInstalled()
            && (int) Db::val('SELECT COUNT(*) FROM users', [], 0) > 0;
    } catch (Throwable $e2) {
        $err = 'Tidak bisa terhubung ke database: ' . $e->getMessage()
             . ' — dan pembuatan otomatis juga gagal: ' . $e2->getMessage();
    }
}

if ($hasUsers && Auth::user() === null) {
    http_response_code(403);
    $err = 'Aplikasi sudah terpasang. Silakan masuk terlebih dahulu untuk menjalankan ulang migrasi.';
    $dbOk = false;
}

if ($dbOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($hasUsers && !Auth::checkCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Sesi tidak valid, muat ulang halaman.');
        }

        $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Berkas database/schema.sql tidak ditemukan.');
        }

        $pdo = Db::conn();

        // Migrasi kolom dijalankan LEBIH DULU: schema.sql memuat CREATE OR
        // REPLACE VIEW yang sudah memakai kolom baru, jadi kolomnya harus ada
        // sebelum view dibuat ulang.
        // Nama database diambil dari perusahaan yang sedang aktif, bukan dari
        // DB_NAME: pada pemasangan banyak perusahaan keduanya berbeda dan
        // migrasi akan memeriksa database yang salah.
        $migrated = runMigrations($pdo, Tenant::aktif()['db']);

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

        // Setelah tabel users pasti ada.
        if (buatAkunDireksi($pdo)) {
            $migrated[] = 'akun direksi (kata sandi awal 123, hanya tab Laba & Biaya)';
        }

        if (!$hasUsers) {
            $user = trim((string) ($_POST['username'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            $name = trim((string) ($_POST['full_name'] ?? ''));
            if ($user === '' || strlen($pass) < 8) {
                throw new RuntimeException('Username wajib diisi dan kata sandi minimal 8 karakter.');
            }
            Db::q(
                'INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)',
                [$user, password_hash($pass, PASSWORD_DEFAULT), $name !== '' ? $name : $user, 'admin']
            );
        }
        $done = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

render_head('Pemasangan', '');
?>
<h1>Pemasangan Aplikasi</h1>
<p class="sub">
  Membuat tabel database dan akun administrator pertama.
  <?php if (Tenant::banyak()): ?>
    <br>Perusahaan: <b><?= e(Tenant::aktif()['nama']) ?></b>
    (database <code class="k"><?= e(Tenant::aktif()['db']) ?></code>)
  <?php endif; ?>
</p>

<?php if (Tenant::banyak()): ?>
  <form method="get" class="filters card" style="margin-bottom:16px">
    <div class="field">
      <label>Pasang / migrasi untuk perusahaan</label>
      <select name="db" onchange="this.form.submit()">
        <?php foreach (Tenant::all() as $t): ?>
          <option value="<?= e($t['kode']) ?>" <?= $t['kode'] === Tenant::kodeAktif() ? 'selected' : '' ?>>
            <?= e($t['nama']) ?> &mdash; <?= e($t['db']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <p class="help" style="margin-top:-8px;margin-bottom:16px">
    Tiap perusahaan berdiri sendiri: jalankan pemasangan <b>sekali untuk masing-masing</b>.
    Databasenya dibuat otomatis kalau belum ada.
  </p>
<?php endif; ?>

<?php if ($dbBaruDibuat): ?>
  <div class="alert ok">Database <code class="k"><?= e(Tenant::aktif()['db']) ?></code> baru dibuat.</div>
<?php endif; ?>

<?php if ($err !== null): ?>
  <div class="alert bad"><b>Gagal.</b> <?= e($err) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert ok">
    <b>Berhasil.</b> Struktur database sudah dibuat<?= $hasUsers ? '' : ' dan akun admin sudah aktif' ?>.
    <?php if ($migrated !== []): ?>
      <br>Kolom baru ditambahkan: <code class="k"><?= e(implode(', ', $migrated)) ?></code>.
      <?php
      // Kolom hak akses terisi lewat halaman Pengguna, bukan lewat unggahan.
      // Hanya kolom data penjualan yang butuh unggah ulang.
      $perluUnggahUlang = array_values(array_filter(
          $migrated,
          static fn(string $c): bool => !str_starts_with($c, 'users.')
      ));
      ?>
      <?php if ($perluUnggahUlang !== []): ?>
        Silakan <b>unggah ulang berkas laporan penghasilan</b> agar kolom tersebut terisi.
      <?php endif; ?>
      <?php if (count($perluUnggahUlang) !== count($migrated)): ?>
        Hak akses tiap pengguna diatur di menu <a href="users.php">Pengguna</a>.
      <?php endif; ?>
    <?php endif; ?>
    <a href="login.php">Lanjut ke halaman masuk</a>.
  </div>
<?php elseif ($dbOk): ?>
  <div class="card" style="max-width:520px">
    <h2><?= $hasUsers ? 'Jalankan ulang migrasi' : 'Buat akun administrator' ?></h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <?php if (!$hasUsers): ?>
        <div class="field" style="margin-bottom:12px">
          <label>Nama lengkap</label>
          <input type="text" name="full_name" placeholder="mis. Bagian Keuangan" style="width:100%">
        </div>
        <div class="field" style="margin-bottom:12px">
          <label>Username</label>
          <input type="text" name="username" required autofocus style="width:100%">
        </div>
        <div class="field" style="margin-bottom:16px">
          <label>Kata sandi (minimal 8 karakter)</label>
          <input type="password" name="password" required minlength="8" style="width:100%">
        </div>
      <?php else: ?>
        <p class="help">Menjalankan ulang <code class="k">database/schema.sql</code>. Perintah bersifat
          <code class="k">CREATE TABLE IF NOT EXISTS</code> sehingga data yang sudah ada tidak terhapus.</p>
      <?php endif; ?>
      <button class="btn" type="submit">Jalankan pemasangan</button>
    </form>
  </div>
<?php else: ?>
  <div class="card" style="max-width:640px">
    <h2>Periksa koneksi database</h2>
    <p class="help">Atur variabel lingkungan berikut pada container aplikasi (atau isi berkas <code class="k">.env</code>):</p>
    <ul class="help">
      <li><code class="k">DB_HOST</code> - nama/IP container MariaDB (default <code class="k">mariadb</code>)</li>
      <li><code class="k">DB_PORT</code> - default <code class="k">3306</code></li>
      <li><code class="k">DB_NAME</code>, <code class="k">DB_USER</code>, <code class="k">DB_PASS</code></li>
    </ul>
  </div>
<?php endif; ?>

<div class="card" style="max-width:640px;margin-top:16px">
  <h2>Pembaruan dari GitHub</h2>
  <p class="help" style="margin-top:-4px">
    Supaya aplikasi bisa ditarik langsung dari GitHub - tanpa unduh zip lalu unggah lagi -
    server perlu memenuhi beberapa syarat. Halaman berikut memeriksanya tanpa mengubah apa pun.
  </p>
  <p style="margin-bottom:0"><a class="btn ghost" href="cek-lingkungan.php">Cek lingkungan server</a></p>
</div>
<?php render_foot(); ?>
