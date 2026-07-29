<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$user = Auth::require();
@set_time_limit(300);

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
                $results[] = ['name' => $name, 'res' => (new CostImporter())->importExpense($tmp, $name, (int) $user['id'])];
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        if ($results === [] && $errors === []) {
            $errors[] = 'Tidak ada berkas yang dipilih.';
        }
    }
}

$ym = q('ym');
if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
    $ym = null;
}

$perBulan   = Reports::expenseByMonth(null, null);
$perKategori = Reports::expenseByCategory(null, null);
$list       = Reports::expenseList($ym, 500);
$totalAll   = Reports::expenseTotal(null, null);
$monthsAvail = array_column($perBulan, 'period_ym');

render_head('Beban Operasional', 'expenses');
?>
<h1>Beban Operasional</h1>
<p class="sub">
  Biaya di luar platform dan di luar HPP &mdash; gaji, sewa, listrik, packaging, iklan, dan
  lainnya. Dicatat <b>per bulan</b>, lalu dipotongkan dari laba kotor menjadi <b>laba usaha</b>
  di halaman Laba &amp; Biaya.
</p>

<?php foreach ($errors as $er): ?>
  <div class="alert bad"><?= e($er) ?></div>
<?php endforeach; ?>

<?php foreach ($results as $r): $t = $r['res']['totals']; ?>
  <div class="alert <?= $t['inserted'] > 0 || $t['updated'] > 0 ? 'ok' : 'info' ?>">
    <b><?= e($r['name']) ?></b> &mdash; <?= num($t['inserted']) ?> beban baru,
    <?= num($t['updated']) ?> diperbarui, <?= num($t['unchanged']) ?> sudah sama,
    <?= num($t['skipped']) ?> dilewati.
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
      Template berisi kategori beban yang umum dipakai. Tambah, ubah, atau hapus barisnya
      sesuai kebutuhan &mdash; kategori bebas Anda tentukan sendiri.
    </p>
    <form method="get" class="filters" style="margin-top:12px" action="template.php">
      <input type="hidden" name="type" value="beban">
      <div class="field">
        <label>Bulan pada template</label>
        <input type="month" name="ym" value="<?= e($ym ?? date('Y-m')) ?>">
      </div>
      <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Unduh Excel</button></div>
      <div class="field"><label>&nbsp;</label><button class="btn ghost" type="submit" name="format" value="csv">Unduh CSV</button></div>
    </form>
  </div>

  <div class="card">
    <h2>2. Unggah kembali setelah diisi</h2>
    <form method="post" enctype="multipart/form-data" id="uploadform">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="drop" id="drop">
        <div class="big">Klik atau seret berkas beban ke sini</div>
        <div class="muted">Format .xlsx atau .csv</div>
        <input type="file" name="files[]" id="files" multiple accept=".xlsx,.csv">
      </div>
      <div class="filelist" id="filelist"></div>
      <button class="btn" type="submit" id="submitbtn" style="margin-top:12px">Unggah &amp; Proses</button>
    </form>
    <p class="help" style="margin-top:10px">
      Baris dikunci per <b>bulan + kategori + keterangan</b>. Kalau ingin mencatat dua pos
      berbeda dalam kategori sama, bedakan kolom <i>Keterangan</i>-nya.
    </p>
  </div>
</div>

<div class="kpis">
  <div class="kpi">
    <div class="label">Total beban tercatat</div>
    <div class="value"><?= rp($totalAll, true) ?></div>
    <div class="hint"><?= count($perBulan) ?> bulan</div>
  </div>
  <?php foreach (array_slice($perKategori, 0, 3) as $k): ?>
    <div class="kpi">
      <div class="label"><?= e($k['category']) ?></div>
      <div class="value"><?= rp($k['total'], true) ?></div>
      <div class="hint"><?= pct($k['total'], $totalAll) ?> dari total beban</div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid2">
  <div class="card">
    <h2>Beban per bulan</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Bulan</th><th class="num">Pos</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($perBulan as $b): ?>
          <tr>
            <td class="nowrap"><b><?= e($b['period_ym']) ?></b></td>
            <td class="num"><?= num($b['baris']) ?></td>
            <td class="num"><?= rp($b['total']) ?></td>
            <td><a class="btn ghost sm" href="?ym=<?= e($b['period_ym']) ?>">Lihat</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($perBulan === []): ?><tr><td colspan="4" class="muted">Belum ada beban tercatat.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Beban per kategori</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Kategori</th><th class="num">Total</th><th class="num">Porsi</th><th style="width:100px"></th></tr></thead>
        <tbody>
        <?php $maxK = $perKategori !== [] ? max(array_map(static fn($k) => (float) $k['total'], $perKategori)) : 0; ?>
        <?php foreach ($perKategori as $k): ?>
          <tr>
            <td><?= e($k['category']) ?></td>
            <td class="num"><?= rp($k['total']) ?></td>
            <td class="num muted"><?= pct($k['total'], $totalAll) ?></td>
            <td><div class="bar"><span style="width:<?= $maxK > 0 ? round((float) $k['total'] / $maxK * 100) : 0 ?>%"></span></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($perKategori === []): ?><tr><td colspan="4" class="muted">-</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <h2>
    Rincian beban<?= $ym !== null ? ' &mdash; ' . e($ym) : '' ?>
    <a class="btn ghost sm" href="<?= e('export.php?report=expenses' . ($ym !== null ? '&ym=' . urlencode($ym) : '')) ?>">Ekspor CSV</a>
  </h2>
  <form method="get" class="filters" style="margin-bottom:12px">
    <div class="field">
      <label>Bulan</label>
      <select name="ym" onchange="this.form.submit()">
        <option value="">Semua bulan</option>
        <?php foreach ($monthsAvail as $m): ?>
          <option value="<?= e($m) ?>" <?= $ym === $m ? 'selected' : '' ?>><?= e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bulan</th><th>Kategori</th><th>Keterangan</th><th class="num">Jumlah</th></tr></thead>
      <tbody>
      <?php $tot = 0.0; foreach ($list as $x): $tot += (float) $x['amount']; ?>
        <tr>
          <td class="nowrap"><?= e($x['period_ym']) ?></td>
          <td><?= e($x['category']) ?></td>
          <td><?= e($x['description'] ?: '-') ?></td>
          <td class="num"><?= rp($x['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($list === []): ?><tr><td colspan="4" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($list !== []): ?>
      <tfoot><tr><td colspan="3">Total</td><td class="num"><?= rp($tot) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php render_foot(); ?>
