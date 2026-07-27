<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::require();

$range = Reports::dataRange();
[$from, $to] = dateRange();
$platform = platformFilter();
if ($from === null && $to === null && $range['order_to'] !== null) {
    $to = $range['order_to'];
    $from = date('Y-m-d', strtotime($to . ' -179 days'));
}

$weekly  = Reports::weekly($from, $to, $platform);
$monthly = Reports::monthlySales($from, $to, $platform);
$payment = Reports::byPayment($from, $to, $platform);
$courier = Reports::byCourier($from, $to, $platform);
$prov    = Reports::byProvince($from, $to, $platform, 25);

// Grafik mingguan (urut naik).
$chart = array_map(
    static fn(array $w): array => ['l' => date('j/n', strtotime((string) $w['mulai'])), 'v' => (float) $w['omzet']],
    array_reverse($weekly)
);

render_head('Performa', 'performance');
?>
<h1>Performa Penjualan</h1>
<p class="sub">Berdasarkan <b>tanggal pesanan</b>. Cocok untuk melihat tren dan hasil kampanye tiap minggu.</p>

<?php render_filter($from, $to, $platform); ?>

<div class="card">
  <h2>Omzet per minggu</h2>
  <?php if ($chart === []): ?>
    <p class="muted">Belum ada data pada rentang ini.</p>
  <?php else: ?>
    <canvas class="chart" data-type="bar" data-color="#1f6feb" data-series='<?= e(json_encode($chart)) ?>'></canvas>
  <?php endif; ?>
</div>

<div class="card">
  <h2>
    Rekap mingguan
    <a class="btn ghost sm" href="<?= e(exportLink('weekly')) ?>">Ekspor CSV</a>
  </h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Periode minggu</th><th class="num">Pesanan</th><th class="num">Selesai</th>
        <th class="num">Batal</th><th class="num">% batal</th><th class="num">Qty</th>
        <th class="num">Omzet</th><th class="num">Rata-rata/pesanan</th><th class="num">Pertumbuhan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($weekly as $i => $w):
          $om = (float) $w['omzet'];
          $sel = (int) $w['pesanan_selesai'];
          $prev = $weekly[$i + 1] ?? null;   // baris berikutnya = minggu sebelumnya
          $growth = $prev !== null && (float) $prev['omzet'] > 0
              ? ($om - (float) $prev['omzet']) / (float) $prev['omzet'] * 100
              : null; ?>
        <tr>
          <td class="nowrap"><?= shortDate($w['mulai']) ?> &ndash; <?= shortDate($w['selesai_tgl']) ?></td>
          <td class="num"><?= num($w['pesanan']) ?></td>
          <td class="num"><?= num($sel) ?></td>
          <td class="num <?= (int) $w['pesanan_batal'] > 0 ? 'neg' : '' ?>"><?= num($w['pesanan_batal']) ?></td>
          <td class="num muted"><?= pct($w['pesanan_batal'], $w['pesanan']) ?></td>
          <td class="num"><?= num($w['qty']) ?></td>
          <td class="num"><?= rp($om) ?></td>
          <td class="num"><?= rp($sel > 0 ? $om / $sel : 0) ?></td>
          <td class="num <?= $growth === null ? 'muted' : ($growth >= 0 ? 'pos' : 'neg') ?>">
            <?= $growth === null ? '-' : (($growth >= 0 ? '+' : '') . number_format($growth, 1, ',', '.') . '%') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($weekly === []): ?><tr><td colspan="9" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>Rekap bulanan</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th><th>Platform</th><th class="num">Pesanan</th><th class="num">Selesai</th>
        <th class="num">Batal</th><th class="num">Qty</th><th class="num">Omzet</th>
      </tr></thead>
      <tbody>
      <?php foreach ($monthly as $m): ?>
        <tr>
          <td class="nowrap"><?= e($m['bulan']) ?></td>
          <td><?= platformBadge((string) $m['platform']) ?></td>
          <td class="num"><?= num($m['pesanan']) ?></td>
          <td class="num"><?= num($m['pesanan_selesai']) ?></td>
          <td class="num <?= (int) $m['pesanan_batal'] > 0 ? 'neg' : '' ?>"><?= num($m['pesanan_batal']) ?></td>
          <td class="num"><?= num($m['qty']) ?></td>
          <td class="num"><?= rp($m['omzet']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($monthly === []): ?><tr><td colspan="7" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid3">
  <div class="card">
    <h2>Metode pembayaran</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Metode</th><th class="num">Pesanan</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($payment, 0, 15) as $p): ?>
          <tr><td><?= e($p['metode']) ?></td><td class="num"><?= num($p['pesanan']) ?></td><td class="num"><?= rp($p['omzet']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($payment === []): ?><tr><td colspan="3" class="muted">-</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Kurir</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Kurir</th><th class="num">Pesanan</th><th class="num">Ongkir</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($courier, 0, 15) as $c): ?>
          <tr><td class="trunc" style="max-width:170px"><?= e($c['kurir']) ?></td><td class="num"><?= num($c['pesanan']) ?></td><td class="num"><?= rp($c['ongkir']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($courier === []): ?><tr><td colspan="3" class="muted">-</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Provinsi</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Provinsi</th><th class="num">Pesanan</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php foreach ($prov as $p): ?>
          <tr><td class="trunc" style="max-width:170px"><?= e($p['provinsi']) ?></td><td class="num"><?= num($p['pesanan']) ?></td><td class="num"><?= rp($p['omzet']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($prov === []): ?><tr><td colspan="3" class="muted">-</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php render_foot(); ?>
