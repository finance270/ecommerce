<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Pintu masuk khusus direksi.
 *
 * Tautan ini dibagikan terpisah dan hanya meminta KATA SANDI, tanpa username,
 * supaya tidak perlu membagikan akun operasional. Hak aksesnya mengikuti akun
 * __direksi__ yang bisa diatur admin di menu Pengguna - bawaannya hanya tab
 * Laba & Biaya.
 */

$err = null;

if (Auth::user() !== null) {
    // Sudah masuk: langsung ke tab pertama yang boleh dibuka.
    $tabs = Auth::allowedTabs();
    header('Location: ' . ($tabs !== [] ? Perm::TABS[$tabs[0]][1] : 'login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $err = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (!Auth::attemptDireksi((string) ($_POST['password'] ?? ''))) {
        $err = 'Kata sandi salah.';
        // Jeda kecil supaya tebak-menebak kata sandi tidak bisa dilakukan cepat.
        usleep(400000);
    } else {
        $tabs = Auth::allowedTabs();
        header('Location: ' . ($tabs !== [] ? Perm::TABS[$tabs[0]][1] : 'login.php'));
        exit;
    }
}

$app = e(Config::get('app_name'));
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Direksi &middot; <?= $app ?></title>
<link rel="stylesheet" href="<?= e(assetUrl('assets/app.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='13' font-size='13'>&#128200;</text></svg>">
</head>
<body>
<main class="login-wrap">
  <div class="card">
    <h1><?= $app ?></h1>
    <p class="sub" style="margin-bottom:18px">Akses direksi &mdash; cukup masukkan kata sandi.</p>

    <?php if ($err !== null): ?>
      <div class="alert bad"><?= e($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="field">
        <label for="password">Kata sandi</label>
        <input type="password" name="password" id="password" required autofocus autocomplete="current-password">
      </div>
      <button class="btn" type="submit" style="width:100%;margin-top:6px">Masuk</button>
    </form>

    <p class="help" style="margin-top:14px">
      Punya akun sendiri? <a href="login.php">Masuk lewat halaman biasa</a>.
    </p>
  </div>
</main>
</body>
</html>
