<?php
declare(strict_types=1);

/**
 * Kerangka halaman.
 * Dipakai: render_head('Judul'); ... isi ... render_foot();
 */

function render_head(string $title, string $active = ''): void
{
    $user = Auth::user();
    $app = e(Config::get('app_name'));
    // Saat melayani beberapa PT, nama PT-lah yang dipakai sebagai judul.
    // Tanpa ini semua PT tampak sama persis dan gampang tertukar - terutama
    // saat dua PT dibuka di dua tab peramban.
    $judul = Tenant::banyak() ? e(Tenant::aktif()['nama']) : $app;
    // Menu hanya menampilkan tab yang boleh dibuka. Ini semata untuk
    // kerapian - pengamanan sesungguhnya ada di Auth::requireTab() pada
    // masing-masing halaman.
    $nav = [];
    foreach (Perm::TABS as $key => [$label, $file, $_desc]) {
        if (Auth::can($key)) {
            $nav[$file] = [$label, $key];
        }
    }
    if (Auth::isAdmin()) {
        $nav['users.php'] = ['Pengguna', 'users'];
    }
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &middot; <?= $judul ?></title>
<link rel="stylesheet" href="<?= e(assetUrl('assets/app.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='13' font-size='13'>&#128200;</text></svg>">
</head>
<body>
<header class="topbar">
  <div class="brand" title="<?= $app ?>"><?= $judul ?></div>
  <nav>
    <?php foreach ($nav as $href => [$label, $key]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($user !== null): ?>
    <div class="user">
      <?php if (Auth::akun() !== null): ?>
        <a href="pilih-perusahaan.php" title="Buka PT lain">Ganti PT</a> &middot;
      <?php endif; ?>
      <?= e($user['full_name'] ?: $user['username']) ?> &middot; <a href="logout.php">Keluar</a>
    </div>
  <?php endif; ?>
</header>
<main class="wrap">
<?php
    $f = flash();
    if ($f !== null) {
        echo '<div class="alert ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function render_foot(): void
{
    ?>
</main>
<script src="<?= e(assetUrl('assets/app.js')) ?>"></script>
</body>
</html><?php
}

/**
 * Kepala laporan yang HANYA muncul di atas kertas.
 *
 * Di layar keterangan periode sudah terbaca dari kotak filter, tetapi kotak
 * itu tidak ikut dicetak - hasil cetaknya jadi tidak menyebutkan sama sekali
 * rentang tanggalnya, perusahaan mana, dan platform apa. Lembar yang beredar
 * tanpa keterangan itu tidak bisa dipertanggungjawabkan.
 */
function render_periode_cetak(?string $from, ?string $to, ?string $platform): void
{
    $bagian = [];
    $bagian[] = 'Periode <b>' . e(shortDate($from)) . ' &ndash; ' . e(shortDate($to)) . '</b>';
    $bagian[] = 'Platform: <b>' . ($platform === null ? 'Semua' : e(ucfirst($platform))) . '</b>';
    if (Tenant::banyak()) {
        $bagian[] = e(Tenant::aktif()['nama']);
    }
    $bagian[] = 'dicetak ' . e(date('d/m/Y H:i'));
    ?>
    <p class="print-only sub" style="margin-top:-10px"><?= implode(' &middot; ', $bagian) ?></p>
    <?php
}

/**
 * Baris filter standar (tanggal + platform) yang dipakai hampir semua laporan.
 */
function render_filter(?string $from, ?string $to, ?string $platform, array $extra = []): void
{
    ?>
  <form method="get" class="filters card" style="margin-bottom:18px">
    <?php foreach ($extra as $k => $v): ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
    <?php endforeach; ?>
    <div class="field">
      <label>Dari tanggal</label>
      <input type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
      <label>Sampai tanggal</label>
      <input type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div class="field">
      <label>Platform</label>
      <select name="platform">
        <option value="">Semua platform</option>
        <option value="tokopedia" <?= $platform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
        <option value="shopee" <?= $platform === 'shopee' ? 'selected' : '' ?>>Shopee</option>
      </select>
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <button class="btn" type="submit">Terapkan</button>
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <a class="btn ghost" href="?">Reset</a>
    </div>
  </form>
    <?php
}

function platformBadge(string $p): string
{
    $label = $p === 'tokopedia' ? 'Tokopedia' : ($p === 'shopee' ? 'Shopee' : $p);
    return '<span class="badge ' . e($p) . '">' . e($label) . '</span>';
}

/**
 * Tautan ajakan ke tab lain, hanya bila pengguna memang boleh membukanya.
 * Tanpa ini pengguna dengan hak terbatas akan disodori tautan yang berujung
 * di halaman "Akses ditolak".
 */
function tabLink(string $tab, string $href, string $text): string
{
    return Auth::can($tab) ? '<a href="' . e($href) . '">' . $text . '</a>' : '';
}

/** Link ekspor CSV untuk laporan yang sedang dibuka. */
function exportLink(string $report, array $params = []): string
{
    $keep = ['from', 'to', 'platform', 'sort', 'psort', 'q', 'status'];
    $q = array_merge(['report' => $report], array_intersect_key($_GET, array_flip($keep)), $params);
    return 'export.php?' . http_build_query($q);
}
