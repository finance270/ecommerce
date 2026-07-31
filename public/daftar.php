<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Mengaktifkan akun pusat pertama.
 *
 * Sekali jalan. Setelah ada satu akun, penambahan orang dilakukan dari halaman
 * Perusahaan oleh pemiliknya - bukan lewat pendaftaran terbuka.
 *
 * Pada pemasangan yang sudah berjalan, halaman ini minta masuk sebagai admin
 * lebih dulu. Alasannya sederhana: aplikasinya sudah berisi data, jadi yang
 * boleh menjadikan dirinya pemilik hanyalah orang yang memang sudah pegang
 * kendali - bukan siapa pun yang kebetulan menemukan alamat halaman ini.
 */

if (Pusat::tersedia() && Pusat::jumlahAkun() > 0) {
    header('Location: login.php');
    exit;
}

// Apakah aplikasi ini sudah dipakai (ada PT terpasang berisi pengguna)?
$sudahDipakai = false;
try {
    $sudahDipakai = Db::isInstalled() && (int) Db::val('SELECT COUNT(*) FROM users', [], 0) > 0;
} catch (Throwable) {
    $sudahDipakai = false;
}

if ($sudahDipakai && !Auth::isAdmin()) {
    if (Auth::user() === null) {
        header('Location: login.php');
        exit;
    }
    http_response_code(403);
    render_head('Akun pusat', '');
    echo '<div class="alert bad">Hanya admin yang bisa mengaktifkan akun pusat.</div>';
    render_foot();
    exit;
}

$err = null;
$hasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($sudahDipakai && !Auth::checkCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Sesi tidak valid, muat ulang halaman.');
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        $nama  = trim((string) ($_POST['nama'] ?? ''));
        $sandi = (string) ($_POST['password'] ?? '');

        Pusat::pasang();
        $akunId = Pusat::buatAkun($email, $sandi, $nama, true);

        // PT yang sudah ada ikut didaftarkan supaya datanya langsung terlihat
        // dari akun ini. Tanpa langkah ini, akun barunya akan masuk ke aplikasi
        // yang tampak kosong padahal datanya ada.
        $ikut = [];
        foreach (Tenant::all() as $t) {
            try {
                $pdo = Pemasang::sambung($t['db']);
                $ada = $pdo->query("SHOW TABLES LIKE 'users'");
                if ($ada === false || $ada->fetchColumn() === false) {
                    continue;   // database itu belum terpasang, lewati
                }
                Pusat::daftarkanPerusahaanYangAda($t['kode'], $t['nama'], $t['db'], $akunId);
                $ikut[] = $t['nama'];
            } catch (Throwable) {
                // PT yang databasenya bermasalah dilewati - bisa didaftarkan
                // ulang belakangan dari halaman Perusahaan.
            }
        }

        Tenant::lupakan();
        $hasil = ['email' => Pusat::normalEmail($email), 'pt' => $ikut];
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

render_head('Akun pusat', '');
?>
<h1>Aktifkan akun pusat</h1>
<p class="sub">
  Satu email untuk beberapa PT. Akun ini menjadi pemilik, dan dari akun ini
  PT baru bisa ditambahkan serta penggunanya diatur per PT.
</p>

<?php if ($err !== null): ?>
  <div class="alert bad"><b>Gagal.</b> <?= e($err) ?></div>
<?php endif; ?>

<?php if ($hasil !== null): ?>
  <div class="alert ok">
    <b>Berhasil.</b> Akun <code class="k"><?= e($hasil['email']) ?></code> sudah aktif.
    <?php if ($hasil['pt'] !== []): ?>
      <br>PT yang ikut terdaftar: <b><?= e(implode(', ', $hasil['pt'])) ?></b>.
    <?php endif; ?>
    <br><a href="login.php">Masuk dengan email tersebut</a>.
  </div>
<?php else: ?>
  <div class="card" style="max-width:520px">
    <h2>Data akun</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="field" style="margin-bottom:12px">
        <label>Nama lengkap</label>
        <input type="text" name="nama" placeholder="mis. Bagian Keuangan" style="width:100%">
      </div>
      <div class="field" style="margin-bottom:12px">
        <label>Email</label>
        <input type="email" name="email" required autofocus style="width:100%">
      </div>
      <div class="field" style="margin-bottom:16px">
        <label>Kata sandi (minimal 8 karakter)</label>
        <input type="password" name="password" required minlength="8" style="width:100%">
      </div>
      <button class="btn" type="submit">Aktifkan</button>
    </form>
    <p class="help" style="margin-top:12px;margin-bottom:0">
      Database pusat <code class="k"><?= e(Pusat::namaDb()) ?></code> dibuat otomatis.
      Isinya hanya daftar akun dan daftar PT &mdash; data penjualan tetap di
      database masing-masing PT.
    </p>
  </div>
<?php endif; ?>
<?php render_foot(); ?>
