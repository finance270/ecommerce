<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$user = Auth::requireTab('costs');
@ini_set('memory_limit', '512M');
@set_time_limit(600);

$results = [];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesi kedaluwarsa. Muat ulang halaman lalu unggah lagi.';
    } else {
        $files = $_FILES['files'] ?? null;
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            $name = (string) $files['name'][$i];
            $tmp  = (string) $files['tmp_name'][$i];
            if ((int) $files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $errors[] = $name . ': berkas gagal diunggah.';
                continue;
            }
            if (preg_match('/\.(xlsx|csv)$/i', $name) !== 1) {
                $errors[] = $name . ': hanya berkas .xlsx atau .csv yang didukung.';
                continue;
            }
            try {
                $results[] = ['name' => $name, 'res' => (new CostImporter())->importCost($tmp, $name, (int) $user['id'])];
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        if ($results === [] && $errors === []) {
            $errors[] = 'Tidak ada berkas yang dipilih.';
        }
    }
}

// Menghapus hanya boleh oleh admin. Pengguna lain tetap bisa memperbaiki data
// dengan mengunggah ulang berkasnya (menimpa baris yang sama).
$notesOk = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'hapus') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesi kedaluwarsa.';
    } elseif (!Auth::canDelete()) {
        $errors[] = 'Hanya admin yang boleh menghapus data.';
    } else {
        $hid = (int) ($_POST['id'] ?? 0);
        $hym = (string) ($_POST['ym'] ?? '');
        $hym = preg_match('/^\d{4}-\d{2}$/', $hym) === 1 ? $hym : null;
        $n = Reports::deleteCost($hid > 0 ? $hid : null, $hid > 0 ? null : $hym);
        $notesOk[] = $n . ' baris HPP dihapus.';
    }
}

$months  = Reports::availableMonths();
$ym      = q('ym');
if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
    $ym = null;
}
$search   = q('q');
$coverage = Reports::costCoverageByMonth();
$list     = Reports::costList($ym, $search, 500);

// Ambang kewajaran marjin, bisa disesuaikan dari halaman.
$minPct = (float) (q('min') ?? Reports::MARJIN_MIN);
$maxPct = (float) (q('max') ?? Reports::MARJIN_MAX);
$badPct = (float) (q('bad') ?? 100);
$minPct = max(0.0, min(100.0, $minPct));
$maxPct = max($minPct, min(100.0, $maxPct));
$badPct = max($maxPct, min(200.0, $badPct));

// Dua bagian yang harus menelusuri seluruh baris produk diambil lewat
// permintaan terpisah supaya halaman langsung tampil.
$qs = static fn(array $p): string => http_build_query(
    array_filter($p, static fn($v) => $v !== null && $v !== '')
);
$lazyKurang = 'costs_section.php?' . $qs(['section' => 'kurang', 'ym' => $ym]);
$lazyCek    = 'costs_section.php?' . $qs([
    'section' => 'cek', 'ym' => $ym, 'min' => $minPct, 'max' => $maxPct, 'bad' => $badPct,
    'platform' => platformFilter(),
]);

render_head('HPP Produk', 'costs');
?>
<h1>HPP (Harga Pokok Penjualan)</h1>
<p class="sub">
  HPP diisi <b>per produk per bulan</b>, lalu dipakai menghitung laba. Pencocokan memakai
  <b>nama produk + variasi</b> &mdash; bukan SKU &mdash; karena pada ekspor Tokopedia dan Shopee
  kolom SKU penjual sebagian besar kosong.
</p>

<?php foreach ($errors as $er): ?>
  <div class="alert bad"><?= e($er) ?></div>
<?php endforeach; ?>
<?php foreach ($notesOk as $n): ?>
  <div class="alert ok"><?= e($n) ?></div>
<?php endforeach; ?>

<?php foreach ($results as $r): $t = $r['res']['totals']; ?>
  <div class="alert <?= $t['inserted'] > 0 || $t['updated'] > 0 ? 'ok' : 'info' ?>">
    <b><?= e($r['name']) ?></b> &mdash; <?= num($t['inserted']) ?> HPP baru,
    <?= num($t['updated']) ?> diperbarui, <?= num($t['unchanged']) ?> sudah sama,
    <?= num($t['skipped']) ?> dilewati (kosong/tidak valid).
    <?php if ($r['res']['problems'] !== []): ?>
      <details style="margin-top:6px">
        <summary>Lihat <?= count($r['res']['problems']) ?> catatan</summary>
        <ul class="help" style="margin:6px 0 0 16px">
          <?php foreach (array_slice($r['res']['problems'], 0, 30) as $p): ?>
            <li><?= e($p) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="grid2">
  <div class="card">
    <h2>1. Unduh template</h2>
    <p class="help" style="margin-top:-4px">
      Template sudah <b>terisi daftar produk</b> yang benar-benar terjual, lengkap dengan HPP
      yang sudah pernah diisi untuk bulan itu. Anda tinggal mengisi kolom <i>HPP per Unit</i>.
    </p>
    <form method="get" class="filters" style="margin-top:12px">
      <input type="hidden" name="type" value="hpp">
      <div class="field">
        <label>Untuk bulan</label>
        <select name="ym">
          <option value="">Semua produk yang pernah terjual</option>
          <?php foreach ($months as $m): ?>
            <option value="<?= e($m) ?>" <?= $ym === $m ? 'selected' : '' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <button class="btn" type="submit" formaction="template.php">Unduh Excel</button>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <button class="btn ghost" type="submit" formaction="template.php" name="format" value="csv">Unduh CSV</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>2. Unggah kembali setelah diisi</h2>
    <form method="post" enctype="multipart/form-data" id="uploadform">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="drop" id="drop">
        <div class="big">Klik atau seret berkas HPP ke sini</div>
        <div class="muted">Format .xlsx atau .csv</div>
        <input type="file" name="files[]" id="files" multiple accept=".xlsx,.csv">
      </div>
      <div class="filelist" id="filelist"></div>
      <button class="btn" type="submit" id="submitbtn" style="margin-top:12px">Unggah &amp; Proses</button>
    </form>
    <p class="help" style="margin-top:10px">
      Aman diunggah berulang: baris dikunci per <b>bulan + produk</b>, jadi isi yang sama
      dilewati dan isi yang berubah menimpa yang lama.
    </p>
  </div>
</div>

<div class="card">
  <h2>Kelengkapan HPP per bulan</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Dihitung dari produk yang benar-benar terjual pada bulan tersebut (berdasarkan tanggal dana
    dilepaskan, sama seperti halaman Laba &amp; Biaya).
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th><th class="num">Produk terjual</th><th class="num">Belum ada HPP</th>
        <th class="num">Kelengkapan</th><th class="num">Qty tanpa HPP</th>
        <th class="num">Total HPP tercatat</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($coverage as $c):
          $prod = (int) $c['produk'];
          $kurang = (int) $c['produk_tanpa_hpp'];
          $pctOk = $prod > 0 ? ($prod - $kurang) / $prod * 100 : 0; ?>
        <tr>
          <td class="nowrap"><b><?= e($c['period_ym']) ?></b></td>
          <td class="num"><?= num($prod) ?></td>
          <td class="num <?= $kurang > 0 ? 'neg' : 'pos' ?>"><?= num($kurang) ?></td>
          <td class="num">
            <?= number_format($pctOk, 0, ',', '.') ?>%
            <div class="bar"><span style="width:<?= round($pctOk) ?>%;background:<?= $pctOk >= 100 ? '#128a5b' : ($pctOk >= 50 ? '#a86b00' : '#c0392b') ?>"></span></div>
          </td>
          <td class="num <?= (int) $c['qty_tanpa_hpp'] > 0 ? 'neg' : 'muted' ?>"><?= num($c['qty_tanpa_hpp']) ?></td>
          <td class="num"><?= rp($c['hpp']) ?></td>
          <td class="nowrap">
            <a class="btn ghost sm" href="?ym=<?= e($c['period_ym']) ?>">Lihat</a>
            <a class="btn ghost sm" href="template.php?type=hpp&amp;ym=<?= e($c['period_ym']) ?>">Template</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($coverage === []): ?>
        <tr><td colspan="7" class="muted">Belum ada data penjualan yang bisa dihitung.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>
    Produk yang belum ada HPP<?= $ym !== null ? ' &mdash; ' . e($ym) : '' ?>
    <a class="btn ghost sm" href="<?= e('export.php?report=missing_cost' . ($ym !== null ? '&ym=' . urlencode($ym) : '')) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Diurutkan dari nilai penjualan terbesar, jadi yang paling berpengaruh ke laba bisa
    dikerjakan lebih dulu. Selama HPP belum diisi, produk ini dihitung <b>HPP = 0</b> sehingga
    labanya tampak lebih besar dari kenyataan.
  </p>
  <form method="get" class="filters" style="margin-bottom:12px">
    <div class="field">
      <label>Bulan</label>
      <select name="ym" onchange="this.form.submit()">
        <option value="">Semua bulan</option>
        <?php foreach ($months as $m): ?>
          <option value="<?= e($m) ?>" <?= $ym === $m ? 'selected' : '' ?>><?= e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <div data-lazy="<?= e($lazyKurang) ?>">
    <p class="loading">Menelusuri produk yang terjual&hellip;</p>
    <noscript><a href="<?= e($lazyKurang) ?>">Buka daftar produk tanpa HPP</a></noscript>
  </div>
</div>

<div class="card">
  <h2>
    Uji kewajaran HPP yang sudah diisi
    <a class="btn ghost sm no-print" href="<?= e('export.php?report=cost_check' . ($ym !== null ? '&ym=' . urlencode($ym) : '') . '&min=' . $minPct . '&max=' . $maxPct . '&bad=' . $badPct) ?>">Ekspor CSV</a>
    <a class="btn ghost sm no-print" target="_blank" rel="noopener"
       href="<?= e('costs_section.php?' . http_build_query(array_filter([
           'section' => 'cek', 'ym' => $ym, 'min' => $minPct, 'max' => $maxPct,
           'bad' => $badPct, 'platform' => platformFilter(), 'cetak' => '1',
       ], static fn($v) => $v !== null && $v !== ''))) ?>">Cetak / simpan PDF</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Marjin laba = laba setelah pajak &divide; penjualan bersih. Marjin yang <b>wajar</b> berada di
    rentang <b><?= number_format($minPct, 0, ',', '.') ?>%&ndash;<?= number_format($maxPct, 0, ',', '.') ?>%</b>
    &mdash; nilai awal untuk produk kopi bubuk/biji. Di <b>bawah</b> rentang berarti marjinnya terlalu
    tipis (harga jual kerendahan atau HPP kemahalan); di <b>atas</b> rentang berarti HPP kemungkinan
    terlalu kecil atau belum lengkap. Mendekati
    <b><?= number_format($badPct, 0, ',', '.') ?>%</b> hampir pasti salah isi, dan marjin negatif
    berarti HPP melebihi pendapatan (jual rugi).
    <b>Klik nama produk</b> untuk melihat rinciannya &mdash; pecahan per platform, per bulan,
    dan daftar pesanannya.
  </p>
  <div data-lazy="<?= e($lazyCek) ?>">
    <p class="loading">Menghitung marjin tiap produk&hellip;</p>
    <noscript><a href="<?= e($lazyCek) ?>">Buka hasil uji kewajaran HPP</a></noscript>
  </div>
</div>

<div class="card">
  <h2>HPP tersimpan<?= $ym !== null ? ' &mdash; ' . e($ym) : '' ?>
    <?php if (Auth::canDelete() && $ym !== null): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Hapus SELURUH HPP bulan <?= e($ym) ?>? Tindakan ini tidak bisa dibatalkan.')">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="act" value="hapus">
        <input type="hidden" name="ym" value="<?= e($ym) ?>">
        <button class="btn ghost sm" type="submit">Hapus sebulan</button>
      </form>
    <?php endif; ?>
  </h2>
  <form method="get" class="filters" style="margin-bottom:12px">
    <?php if ($ym !== null): ?><input type="hidden" name="ym" value="<?= e($ym) ?>"><?php endif; ?>
    <div class="field">
      <label>Cari produk</label>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="nama produk / variasi / SKU">
    </div>
    <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Cari</button></div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bulan</th><th>Produk</th><th>Variasi</th><th>SKU</th><th class="num">HPP per unit</th><th>Catatan</th>
        <?php if (Auth::canDelete()): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($list as $c): ?>
        <tr>
          <td class="nowrap"><?= e($c['period_ym']) ?></td>
          <td class="trunc" title="<?= e($c['product_name']) ?>"><?= e($c['product_name']) ?></td>
          <td><?= e($c['variation'] ?: '-') ?></td>
          <td><?= e($c['sku'] ?: '-') ?></td>
          <td class="num"><?= rp($c['cost_per_unit']) ?></td>
          <td class="muted"><?= e($c['note'] ?: '') ?></td>
          <?php if (Auth::canDelete()): ?>
            <td class="nowrap">
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus HPP baris ini?')">
                <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                <input type="hidden" name="act" value="hapus">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn ghost sm" type="submit">Hapus</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($list === []): ?>
        <tr><td colspan="7" class="muted">Belum ada HPP tersimpan. Mulai dari langkah 1 di atas.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_foot(); ?>
