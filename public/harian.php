<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Penjualan harian - yang paling cepat terbarui.
 *
 * Bersumber dari berkas PESANAN, bukan berkas penghasilan. Berkas pesanan
 * bisa diunduh hari itu juga, sedangkan dana baru cair sekitar seminggu
 * kemudian; kalau menunggu settlement, halaman ini akan selalu tertinggal
 * seminggu dan kehilangan gunanya.
 *
 * Karena biaya platform baru diketahui saat dana cair, rantainya berhenti di
 * PENJUALAN BERSIH:
 *
 *     penjualan bruto - diskon - PPN - pajak e-commerce = penjualan bersih
 *
 * Laba harian sengaja tidak ditampilkan - angkanya belum bisa
 * dipertanggungjawabkan sebelum biaya platformnya masuk. Untuk itu ada
 * laporan Laba & Biaya.
 */

Auth::requireTab('harian');
@set_time_limit(300);

$platform = platformFilter();

// Default 30 hari terakhir dari data yang ada, bukan dari hari ini: data
// diunggah manual, jadi "hari ini" sering belum ada isinya sama sekali.
$range   = Reports::dataRange();
$akhirDb = (string) ($range['order_to'] ?? date('Y-m-d'));
$hari    = (int) (q('hari') ?? 30);
if (!in_array($hari, [7, 14, 30, 60, 90], true)) {
    $hari = 30;
}
$to   = $akhirDb;
$from = date('Y-m-d', strtotime($to . ' -' . ($hari - 1) . ' day') ?: time());

$baris = Reports::penjualanHarian($from, $to, $platform);

$ppnRasio = Tax::PPN_PERSEN / (100 + Tax::PPN_PERSEN);
/** Penjualan bersih satu baris harian. */
$bersih = static fn(array $r): float =>
    (float) $r['setelah_diskon'] - (float) $r['ppn'] - (float) $r['pph'];

$tot = ['pesanan' => 0, 'selesai' => 0, 'proses' => 0, 'batal' => 0, 'qty' => 0,
        'bruto' => 0.0, 'setelah_diskon' => 0.0, 'ppn' => 0.0, 'pph' => 0.0, 'bersih' => 0.0];
$perHari = [];
foreach ($baris as $r) {
    foreach (['pesanan', 'selesai', 'proses', 'batal', 'qty'] as $k) {
        $tot[$k] += (int) $r[$k];
    }
    foreach (['bruto', 'setelah_diskon', 'ppn', 'pph'] as $k) {
        $tot[$k] += (float) $r[$k];
    }
    $tot['bersih'] += $bersih($r);
    $perHari[(string) $r['tanggal']] = $r;
}

/** Ringkasan beberapa hari terakhir, dihitung mundur dari tanggal data terakhir. */
$rekap = static function (int $n) use ($perHari, $akhirDb, $bersih): array {
    $out = ['bruto' => 0.0, 'bersih' => 0.0, 'pesanan' => 0];
    for ($i = 0; $i < $n; $i++) {
        $t = date('Y-m-d', strtotime($akhirDb . ' -' . $i . ' day') ?: time());
        if (!isset($perHari[$t])) {
            continue;
        }
        $out['bruto']   += (float) $perHari[$t]['bruto'];
        $out['bersih']  += $bersih($perHari[$t]);
        $out['pesanan'] += (int) $perHari[$t]['pesanan'];
    }
    return $out;
};
$hariIni  = $rekap(1);
$tujuh    = $rekap(7);
$tigaPuluh = $rekap(30);

$umurData = (int) floor((time() - (strtotime($akhirDb) ?: time())) / 86400);

render_head('Penjualan Harian', 'harian');
?>
<h1>Penjualan Harian</h1>
<p class="sub">
  Diambil dari <b>berkas pesanan</b>, bukan berkas penghasilan &mdash; berkas pesanan bisa diunduh
  hari itu juga, sedangkan dana baru cair sekitar seminggu kemudian. Jadi halaman ini yang paling
  cepat terbarui: unggah berkas pesanan, angkanya langsung ikut.
</p>
<p class="sub" style="margin-top:-8px">
  Rantainya berhenti di <b>penjualan bersih</b> &mdash; <i>bruto &minus; diskon &minus; PPN
  &minus; pajak e-commerce</i> &mdash; karena <b>biaya platform baru diketahui saat dana cair</b>.
  Laba harian sengaja tidak ditampilkan supaya tidak menyesatkan; untuk itu ada
  <?= tabLink('pnl', 'pnl.php', 'Laba &amp; Biaya') ?>. Seperti laporan itu, hanya pesanan
  berstatus <b>selesai</b> yang dihitung sebagai penjualan.
</p>

<?php if ($umurData > 1): ?>
  <div class="alert <?= $umurData > 7 ? 'warn' : 'info' ?>">
    Data pesanan terakhir bertanggal <b><?= e(shortDate($akhirDb)) ?></b>
    &mdash; <?= num($umurData) ?> hari lalu.
    <?= tabLink('upload', 'upload.php', 'Unggah berkas pesanan terbaru &rarr;') ?>
  </div>
<?php endif; ?>

<form method="get" class="filters card no-print" style="margin-bottom:18px">
  <div class="field">
    <label>Rentang</label>
    <select name="hari" onchange="this.form.submit()">
      <?php foreach ([7 => '7 hari', 14 => '14 hari', 30 => '30 hari', 60 => '60 hari', 90 => '90 hari'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $hari === $k ? 'selected' : '' ?>><?= e($v) ?> terakhir</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Platform</label>
    <select name="platform" onchange="this.form.submit()">
      <option value="">Semua platform</option>
      <option value="tokopedia" <?= $platform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
      <option value="shopee"    <?= $platform === 'shopee'    ? 'selected' : '' ?>>Shopee</option>
    </select>
  </div>
  <noscript><button class="btn ghost sm" type="submit">Terapkan</button></noscript>
  <div class="field"><label>&nbsp;</label>
    <a class="btn ghost" href="<?= e('export.php?report=harian&hari=' . $hari . ($platform !== null ? '&platform=' . urlencode($platform) : '')) ?>">Ekspor CSV</a>
  </div>
</form>

<div class="kpis">
  <div class="kpi">
    <div class="label">Hari terakhir &mdash; <?= e(shortDate($akhirDb)) ?></div>
    <div class="value"><?= rp($hariIni['bersih'], true) ?></div>
    <div class="hint">penjualan bersih &middot; bruto <?= rp($hariIni['bruto'], true) ?> &middot;
      <?= num($hariIni['pesanan']) ?> pesanan</div>
  </div>
  <div class="kpi">
    <div class="label">7 hari terakhir</div>
    <div class="value"><?= rp($tujuh['bersih'], true) ?></div>
    <div class="hint">rata-rata <?= rp($tujuh['bersih'] / 7, true) ?> per hari</div>
  </div>
  <div class="kpi">
    <div class="label">30 hari terakhir</div>
    <div class="value"><?= rp($tigaPuluh['bersih'], true) ?></div>
    <div class="hint">rata-rata <?= rp($tigaPuluh['bersih'] / 30, true) ?> per hari</div>
  </div>
  <div class="kpi ok">
    <div class="label">Rentang terpilih</div>
    <div class="value"><?= rp($tot['bersih'], true) ?></div>
    <div class="hint">
      <?= num($hari) ?> hari &middot; <?= num($tot['pesanan']) ?> pesanan &middot;
      <?= num($tot['qty']) ?> produk
    </div>
  </div>
</div>

<div class="card">
  <h2>Per hari &mdash; <?= e(shortDate($from)) ?> s/d <?= e(shortDate($to)) ?></h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Tanggal</th>
        <th class="num">Pesanan</th><th class="num">Selesai</th>
        <th class="num">Belum selesai</th><th class="num">Batal/retur</th>
        <th class="num">Qty</th>
        <th class="num">Penjualan bruto</th><th class="num">Diskon</th>
        <th class="num">PPN</th><th class="num">Pajak e-commerce</th>
        <th class="num">Penjualan bersih</th>
      </tr></thead>
      <tbody>
      <?php foreach ($baris as $r):
          $diskon = (float) $r['bruto'] - (float) $r['setelah_diskon']; ?>
        <tr>
          <td class="nowrap"><b><?= e(shortDate($r['tanggal'])) ?></b>
            <div class="muted" style="font-size:11px"><?= e(hariIndo((string) $r['tanggal'])) ?></div>
          </td>
          <td class="num"><?= num($r['pesanan']) ?></td>
          <td class="num"><?= num($r['selesai']) ?></td>
          <td class="num <?= (int) $r['proses'] > 0 ? 'neg' : 'muted' ?>">
            <?= (int) $r['proses'] > 0 ? num($r['proses']) : '-' ?>
          </td>
          <td class="num <?= (int) $r['batal'] > 0 ? 'neg' : 'muted' ?>">
            <?= (int) $r['batal'] > 0 ? num($r['batal']) : '-' ?>
          </td>
          <td class="num"><?= num($r['qty']) ?></td>
          <td class="num"><?= rp($r['bruto']) ?></td>
          <td class="num <?= $diskon > 0 ? 'neg' : 'muted' ?>"><?= $diskon > 0 ? rp(-$diskon) : '-' ?></td>
          <td class="num neg"><?= rp(-(float) $r['ppn']) ?></td>
          <td class="num <?= (float) $r['pph'] > 0 ? 'neg' : 'muted' ?>">
            <?= (float) $r['pph'] > 0 ? rp(-(float) $r['pph']) : '-' ?>
          </td>
          <td class="num"><b><?= rp($bersih($r)) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($baris === []): ?>
        <tr><td colspan="11" class="muted">
          Belum ada pesanan pada rentang ini. Unggah berkas pesanan lebih dulu.
        </td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($baris !== []): ?>
      <tfoot><tr>
        <td>Total <?= num(count($baris)) ?> hari</td>
        <td class="num"><?= num($tot['pesanan']) ?></td>
        <td class="num"><?= num($tot['selesai']) ?></td>
        <td class="num"><?= num($tot['proses']) ?></td>
        <td class="num"><?= num($tot['batal']) ?></td>
        <td class="num"><?= num($tot['qty']) ?></td>
        <td class="num"><?= rp($tot['bruto']) ?></td>
        <td class="num neg"><?= rp(-($tot['bruto'] - $tot['setelah_diskon'])) ?></td>
        <td class="num neg"><?= rp(-$tot['ppn']) ?></td>
        <td class="num neg"><?= rp(-$tot['pph']) ?></td>
        <td class="num"><?= rp($tot['bersih']) ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    Pesanan <b>belum selesai</b> belum dihitung sebagai penjualan, jadi angka hari ini masih akan
    naik saat statusnya berubah. Pesanan <b>batal/retur</b> tidak akan pernah dihitung.
  </p>
</div>
<?php render_foot(); ?>
