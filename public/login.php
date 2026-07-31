<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

if (Auth::user() !== null) {
    header('Location: index.php');
    exit;
}

$err = null;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $err = 'Sesi kedaluwarsa, silakan coba lagi.';
    } elseif (Auth::attempt(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $err = 'Username atau kata sandi salah.';
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
    <?php if (Tenant::banyak()): ?>
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
        <label>Username</label>
        <input type="text" name="username" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label>Kata sandi</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn" type="submit" style="width:100%">Masuk</button>
    </form>
  </div>
</div>
</body>
</html>
