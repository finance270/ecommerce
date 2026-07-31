<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Halaman masuk.
 *
 * Dua cara masuk yang berdampingan:
 *
 *   email    - akun pusat, bisa memegang beberapa PT sekaligus. Setelah masuk,
 *              PT-nya dipilih di halaman berikutnya (atau langsung dibuka bila
 *              hanya punya satu).
 *   username - akun lama yang tersimpan di database satu PT. Tetap dilayani
 *              supaya pemasangan yang sudah berjalan tidak perlu diubah.
 */

if (Auth::user() !== null) {
    header('Location: index.php');
    exit;
}

$err = null;
$adaAkunPusat = Pusat::tersedia() && Pusat::jumlahAkun() > 0;

// Akun pusat pertama dibuat lewat halaman daftar. Selama belum ada satu pun
// akun dan database PT juga belum terpasang, arahkan ke pemasangan seperti
// sebelumnya - jalur lama tetap jadi jalan masuk yang paling sederhana.
if (!$adaAkunPusat) {
    $needSetup = false;
    try {
        $needSetup = !Db::isInstalled() || (int) Db::val('SELECT COUNT(*) FROM users', [], 0) === 0;
    } catch (Throwable $e) {
        $needSetup = true;
    }
    if ($needSetup) {
        header('Location: setup.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $err = 'Sesi kedaluwarsa, silakan coba lagi.';
    } elseif (str_contains($id, '@') && Auth::attemptAkun($id, $pass)) {
        $daftar = Pusat::perusahaanAkun((int) ($_SESSION[Auth::SESI_AKUN] ?? 0));
        if (count($daftar) === 1 && Auth::masukPerusahaan((string) $daftar[0]['kode'])) {
            header('Location: index.php');
        } else {
            // Nol PT pun diarahkan ke sini: di sana tersedia tombol untuk
            // mendaftarkan PT pertama.
            header('Location: pilih-perusahaan.php');
        }
        exit;
    } elseif (!str_contains($id, '@') && Auth::attempt($id, $pass)) {
        header('Location: index.php');
        exit;
    } else {
        $err = 'Email/username atau kata sandi salah.';
        usleep(400000);
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk &middot; <?= e(Config::get('app_name')) ?></title>
<link rel="stylesheet" href="<?= e(assetUrl('assets/app.css')) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="card">
    <h1><?= e(Config::get('app_name')) ?></h1>
    <p class="sub">Analisis penjualan Tokopedia &amp; Shopee</p>
    <?php if ($err !== null): ?><div class="alert bad"><?= e($err) ?></div><?php endif; ?>

    <?php if (!$adaAkunPusat && Tenant::banyak()): ?>
      <form method="get" class="field" style="margin-bottom:14px">
        <label for="db">Perusahaan</label>
        <select name="db" id="db" onchange="this.form.submit()" style="width:100%">
          <?php foreach (Tenant::all() as $t): ?>
            <option value="<?= e($t['kode']) ?>" <?= $t['kode'] === Tenant::kodeAktif() ? 'selected' : '' ?>>
              <?= e($t['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn ghost sm" type="submit" style="margin-top:6px">Pilih</button></noscript>
      </form>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="field">
        <label><?= $adaAkunPusat ? 'Email atau username' : 'Username' ?></label>
        <input type="text" name="username" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label>Kata sandi</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn" type="submit" style="width:100%">Masuk</button>
    </form>

    <?php if ($adaAkunPusat): ?>
      <p class="help" style="margin-top:12px;margin-bottom:0">
        Masuk dengan <b>email</b> bila akun Anda memegang lebih dari satu PT &mdash;
        PT-nya dipilih setelah ini.
      </p>
    <?php elseif (Pusat::tersedia()): ?>
      <p class="help" style="margin-top:12px;margin-bottom:0">
        Belum ada akun pusat. <a href="daftar.php">Daftarkan email pertama</a> untuk
        bisa mengelola beberapa PT dalam satu akun.
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
