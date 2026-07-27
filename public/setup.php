<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Pemasangan sekali jalan: membuat tabel dan akun admin pertama.
 * Setelah ada user, halaman ini hanya bisa diakses admin (untuk migrasi ulang).
 */

$err = null;
$done = false;
$dbOk = false;
$hasUsers = false;

try {
    Db::conn();
    $dbOk = true;
    $hasUsers = Db::isInstalled()
        && (int) Db::val('SELECT COUNT(*) FROM users', [], 0) > 0;
} catch (Throwable $e) {
    $err = 'Tidak bisa terhubung ke database: ' . $e->getMessage();
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
<p class="sub">Membuat tabel database dan akun administrator pertama.</p>

<?php if ($err !== null): ?>
  <div class="alert bad"><b>Gagal.</b> <?= e($err) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert ok">
    <b>Berhasil.</b> Struktur database sudah dibuat<?= $hasUsers ? '' : ' dan akun admin sudah aktif' ?>.
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
<?php render_foot(); ?>
