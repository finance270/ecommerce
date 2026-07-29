<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('recon');

$range = Reports::dataRange();
[$from, $to] = dateRange();
$platform = platformFilter();
if ($from === null && $to === null && $range['order_to'] !== null) {
    $to = $range['order_to'];
    $from = date('Y-m-d', strtotime($to . ' -89 days'));
}

$summary = Reports::reconciliationSummary($from, $to, $platform);
$unsettled = Reports::unsettledOrders($from, $to, $platform, 300);
$orphan = Reports::orphanSettlements($from, $to, $platform, 300);

render_head('Rekonsiliasi', 'recon');
?>
<h1>Rekonsiliasi Pesanan &harr; Settlement</h1>
<p class="sub">
  Mencocokkan pesanan yang sudah selesai dengan catatan dana yang dilepaskan platform.
  Selisihnya adalah <b>piutang platform</b> &mdash; penjualan yang sudah terjadi tapi uangnya belum cair.
</p>

<div class="alert info">
  <b>Cara membaca halaman ini.</b> Perbedaan wajar terjadi karena periode berkas berbeda:
  laporan penghasilan biasanya hanya mencakup dana yang sudah dilepas, sedangkan berkas pesanan
  mencakup semua pesanan. Pastikan berkas laporan penghasilan untuk periode yang sama sudah diunggah
  sebelum menyimpulkan ada dana tertahan.
</div>

<?php render_filter($from, $to, $platform); ?>

<div class="card">
  <h2>Ringkasan per platform</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Platform</th><th class="num">Pesanan selesai</th><th class="num">Sudah settle</th>
        <th class="num">Belum settle</th><th class="num">% belum settle</th>
        <th class="num">Nilai belum settle</th><th class="num">Nilai total</th>
      </tr></thead>
      <tbody>
      <?php foreach ($summary as $s): ?>
        <tr>
          <td><?= platformBadge((string) $s['platform']) ?></td>
          <td class="num"><?= num($s['pesanan_selesai']) ?></td>
          <td class="num pos"><?= num($s['sudah_settle']) ?></td>
          <td class="num <?= (int) $s['belum_settle'] > 0 ? 'neg' : '' ?>"><?= num($s['belum_settle']) ?></td>
          <td class="num muted"><?= pct($s['belum_settle'], $s['pesanan_selesai']) ?></td>
          <td class="num"><?= rp($s['nilai_belum_settle']) ?></td>
          <td class="num"><?= rp($s['nilai_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($summary === []): ?><tr><td colspan="7" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>
    Pesanan selesai yang belum ada settlement (<?= count($unsettled) ?> teratas)
    <a class="btn ghost sm" href="<?= e(exportLink('unsettled')) ?>">Ekspor CSV</a>
  </h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tanggal</th><th>Platform</th><th>No. Pesanan</th><th>Status</th><th class="num">Nilai produk</th><th class="num">Total bayar</th></tr></thead>
      <tbody>
      <?php foreach ($unsettled as $u): ?>
        <tr>
          <td class="nowrap"><?= shortDate($u['order_date']) ?></td>
          <td><?= platformBadge((string) $u['platform']) ?></td>
          <td class="nowrap"><a href="order.php?platform=<?= e($u['platform']) ?>&amp;id=<?= e($u['order_id']) ?>"><?= e($u['order_id']) ?></a></td>
          <td><span class="badge ok"><?= e($u['status_raw']) ?></span></td>
          <td class="num"><?= rp($u['nilai_pesanan']) ?></td>
          <td class="num"><?= rp($u['order_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($unsettled === []): ?>
        <tr><td colspan="6" class="muted">Semua pesanan selesai sudah punya catatan settlement.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>Settlement tanpa data pesanan (<?= count($orphan) ?> teratas)</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Dana sudah cair tapi berkas pesanan untuk periode itu belum diunggah. Unggah berkas
    <i>Semua Pesanan</i> / <i>Order</i> periode terkait agar analisis produk ikut lengkap.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tgl dana dilepas</th><th>Platform</th><th>No. Pesanan</th><th>Jenis</th><th class="num">Kotor</th><th class="num">Biaya</th><th class="num">Bersih</th></tr></thead>
      <tbody>
      <?php foreach ($orphan as $o): ?>
        <tr>
          <td class="nowrap"><?= shortDate($o['settlement_date']) ?></td>
          <td><?= platformBadge((string) $o['platform']) ?></td>
          <td class="nowrap"><?= e($o['order_id']) ?></td>
          <td><span class="badge muted"><?= e($o['trx_type']) ?></span></td>
          <td class="num"><?= rp($o['gross_amount']) ?></td>
          <td class="num neg"><?= rp($o['total_fee']) ?></td>
          <td class="num pos"><?= rp($o['net_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($orphan === []): ?>
        <tr><td colspan="7" class="muted">Semua settlement sudah punya data pesanan.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_foot(); ?>
