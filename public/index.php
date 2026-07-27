<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::require();

$range = Reports::dataRange();
[$from, $to] = dateRange();
$platform = platformFilter();

// Bawaan: 90 hari terakhir dari data terbaru yang ada.
if ($from === null && $to === null && $range['order_to'] !== null) {
    $to = $range['order_to'];
    $from = date('Y-m-d', strtotime($to . ' -89 days'));
}

$sales = Reports::salesKpi($from, $to, $platform);
$settle = Reports::settlementKpi($from, $to, $platform);
$series = Reports::dailySeries($from, $to, $platform);
$channels = Reports::channelSplit($from, $to, $platform);
$weekly = array_slice(Reports::weekly($from, $to, $platform), 0, 8);
$prov = array_slice(Reports::byProvince($from, $to, $platform, 8), 0, 8);
$lastUploads = Db::all(
    'SELECT u.*, us.username FROM uploads u LEFT JOIN users us ON us.id = u.uploaded_by
     ORDER BY u.id DESC LIMIT 5'
);

$totalOrders = (int) Db::val('SELECT COUNT(*) FROM orders', [], 0);

$chartOmzet = array_map(
    static fn(array $r): array => ['l' => date('j/n', strtotime((string) $r['tanggal'])), 'v' => (float) $r['omzet']],
    $series
);

render_head('Dashboard', 'dashboard');
?>
<h1>Dashboard</h1>
<p class="sub">
  <?php if ($totalOrders === 0): ?>
    Belum ada data. Mulai dengan <a href="upload.php">mengunggah berkas ekspor</a>.
  <?php else: ?>
    Data pesanan <?= shortDate($range['order_from']) ?> &ndash; <?= shortDate($range['order_to']) ?>,
    data settlement <?= shortDate($range['settlement_from']) ?> &ndash; <?= shortDate($range['settlement_to']) ?>.
  <?php endif; ?>
</p>

<?php render_filter($from, $to, $platform); ?>

<div class="kpis">
  <div class="kpi">
    <div class="label">Omzet (pesanan selesai)</div>
    <div class="value"><?= rp($sales['omzet'] ?? 0, true) ?></div>
    <div class="hint">setelah diskon, sebelum biaya platform</div>
  </div>
  <div class="kpi">
    <div class="label">Pesanan selesai</div>
    <div class="value"><?= num($sales['pesanan_selesai'] ?? 0) ?></div>
    <div class="hint">dari <?= num($sales['total_pesanan'] ?? 0) ?> pesanan masuk</div>
  </div>
  <div class="kpi">
    <div class="label">Rata-rata per pesanan</div>
    <div class="value"><?= rp($sales['aov'] ?? 0, true) ?></div>
    <div class="hint"><?= num($sales['qty'] ?? 0) ?> produk terjual</div>
  </div>
  <div class="kpi <?= ($sales['cancel_rate'] ?? 0) > 10 ? 'bad' : '' ?>">
    <div class="label">Tingkat pembatalan</div>
    <div class="value"><?= number_format((float) ($sales['cancel_rate'] ?? 0), 1, ',', '.') ?>%</div>
    <div class="hint"><?= num($sales['pesanan_batal'] ?? 0) ?> pesanan dibatalkan</div>
  </div>
</div>

<div class="kpis">
  <div class="kpi">
    <div class="label">Pendapatan kotor (settlement)</div>
    <div class="value"><?= rp($settle['pendapatan_kotor'] ?? 0, true) ?></div>
    <div class="hint"><?= num($settle['total_trx'] ?? 0) ?> transaksi settle</div>
  </div>
  <div class="kpi bad">
    <div class="label">Total biaya platform</div>
    <div class="value"><?= rp($settle['total_biaya'] ?? 0, true) ?></div>
    <div class="hint"><?= pct(abs((float) ($settle['total_biaya'] ?? 0)), (float) ($settle['pendapatan_kotor'] ?? 0)) ?> dari pendapatan kotor</div>
  </div>
  <div class="kpi ok">
    <div class="label">Dana diterima bersih</div>
    <div class="value"><?= rp($settle['dana_diterima'] ?? 0, true) ?></div>
    <div class="hint">yang benar-benar masuk ke saldo</div>
  </div>
  <div class="kpi">
    <div class="label">Pengembalian dana</div>
    <div class="value"><?= rp($settle['pengembalian'] ?? 0, true) ?></div>
    <div class="hint">refund ke pembeli</div>
  </div>
</div>

<div class="card">
  <h2>Tren omzet harian</h2>
  <?php if ($chartOmzet === []): ?>
    <p class="muted">Belum ada data pada rentang ini.</p>
  <?php else: ?>
    <canvas class="chart" data-type="line" data-color="#1f6feb"
            data-series='<?= e(json_encode($chartOmzet)) ?>'></canvas>
    <div class="legend"><span><i style="background:#1f6feb"></i>Omzet pesanan selesai per hari</span></div>
  <?php endif; ?>
</div>

<div class="grid2">
  <div class="card">
    <h2>Kontribusi per platform &amp; channel</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Platform</th><th>Channel</th><th class="num">Pesanan</th><th class="num">Selesai</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php $totOmzet = array_sum(array_map(static fn($c) => (float) $c['omzet'], $channels)); ?>
        <?php foreach ($channels as $c): ?>
          <tr>
            <td><?= platformBadge((string) $c['platform']) ?></td>
            <td><?= e($c['channel']) ?></td>
            <td class="num"><?= num($c['pesanan']) ?></td>
            <td class="num"><?= num($c['selesai']) ?></td>
            <td class="num"><?= rp($c['omzet']) ?><br>
              <span class="muted" style="font-size:11px"><?= pct($c['omzet'], $totOmzet) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($channels === []): ?><tr><td colspan="5" class="muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Performa 8 minggu terakhir</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Minggu</th><th class="num">Pesanan</th><th class="num">Selesai</th><th class="num">Batal</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php foreach ($weekly as $w): ?>
          <tr>
            <td class="nowrap"><?= shortDate($w['mulai']) ?> &ndash; <?= shortDate($w['selesai_tgl']) ?></td>
            <td class="num"><?= num($w['pesanan']) ?></td>
            <td class="num"><?= num($w['pesanan_selesai']) ?></td>
            <td class="num <?= (int) $w['pesanan_batal'] > 0 ? 'neg' : '' ?>"><?= num($w['pesanan_batal']) ?></td>
            <td class="num"><?= rp($w['omzet']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($weekly === []): ?><tr><td colspan="5" class="muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid2">
  <div class="card">
    <h2>Provinsi teratas</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Provinsi</th><th class="num">Pesanan</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php $maxP = $prov !== [] ? max(array_map(static fn($p) => (float) $p['omzet'], $prov)) : 0; ?>
        <?php foreach ($prov as $p): ?>
          <tr>
            <td><?= e($p['provinsi']) ?>
              <div class="bar"><span style="width:<?= $maxP > 0 ? round((float) $p['omzet'] / $maxP * 100) : 0 ?>%"></span></div>
            </td>
            <td class="num"><?= num($p['pesanan']) ?></td>
            <td class="num"><?= rp($p['omzet']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($prov === []): ?><tr><td colspan="3" class="muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Upload terakhir</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Berkas</th><th>Jenis</th><th class="num">Baru</th><th class="num">Update</th><th>Waktu</th></tr></thead>
        <tbody>
        <?php foreach ($lastUploads as $u): ?>
          <tr>
            <td class="trunc" title="<?= e($u['original_name']) ?>"><?= e($u['original_name']) ?></td>
            <td><?= $u['platform'] !== null ? platformBadge((string) $u['platform']) : '-' ?></td>
            <td class="num"><?= num($u['rows_inserted']) ?></td>
            <td class="num"><?= num($u['rows_updated']) ?></td>
            <td class="nowrap muted"><?= e(date('d/m H:i', strtotime((string) $u['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($lastUploads === []): ?><tr><td colspan="5" class="muted">Belum ada upload.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p style="margin:12px 0 0"><a href="uploads.php">Lihat semua riwayat upload &rarr;</a></p>
  </div>
</div>
<?php render_foot(); ?>
