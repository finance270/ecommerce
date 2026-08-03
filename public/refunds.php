<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Laporan pengembalian dana, berdiri sendiri.
 *
 * Pengembalian tidak lagi muncul sebagai baris pengurang di laporan laba rugi
 * karena pendapatan kotor di sana sudah bersih dari refund. Supaya angkanya
 * tetap bisa dipantau, seluruh rinciannya dikumpulkan di halaman ini.
 */

Auth::requireTab('refunds');
@set_time_limit(300);

[$from, $to] = dateRange();
$platform = platformFilter();

$ring   = Reports::refundSummary($from, $to, $platform);
$bulan  = Reports::refundByMonth($from, $to, $platform);
$produk = Reports::refundByProduct($from, $to, $platform, 100);
$daftar = Reports::refundList($from, $to, $platform, 200);

$refund = (float) ($ring['refund'] ?? 0);
$rasio  = $ring['rasio'] ?? null;

render_head('Pengembalian', 'refunds');
?>
<h1>Pengembalian Dana</h1>
<p class="sub">
  Berbasis <b>tanggal pesanan</b>, sama seperti laporan Laba &amp; Biaya &mdash; pengembalian
  melekat pada pesanan yang dikembalikan, bukan pada hari uangnya bergerak. Bedanya, di sini
  pesanan <b>batal dan retur ikut ditampilkan</b>, karena justru itulah yang dilaporkan.
  Nilainya <b>sudah dipotong</b> dari pendapatan kotor di Laba &amp; Biaya: barang yang
  dikembalikan berarti penjualannya tidak jadi, jadi tidak dihitung sebagai omzet lalu
  dikurangi lagi.
</p>

<?php render_filter($from, $to, $platform); ?>

<?php if ($refund == 0.0): ?>
  <div class="alert ok">
    <b>Tidak ada pengembalian dana</b> pada rentang ini.
  </div>
<?php else: ?>

<div class="kpis">
  <div class="kpi <?= $rasio !== null && $rasio > 5 ? 'bad' : '' ?>">
    <div class="label">Total pengembalian</div>
    <div class="value"><?= rp($refund, true) ?></div>
    <div class="hint"><?= num($ring['trx_refund'] ?? 0) ?> transaksi</div>
  </div>
  <div class="kpi">
    <div class="label">Terhadap kotor</div>
    <div class="value"><?= $rasio === null ? '-' : num($rasio, 2) . '%' ?></div>
    <div class="hint">dari <?= rp($ring['kotor_sebelum'] ?? 0, true) ?> sebelum refund</div>
  </div>
  <div class="kpi">
    <div class="label">Pesanan kena refund</div>
    <div class="value"><?= num($ring['pesanan_refund'] ?? 0) ?></div>
    <div class="hint">
      <?= $ring['rasio_pesanan'] === null ? '-' : num($ring['rasio_pesanan'], 2) . '%' ?>
      dari <?= num($ring['pesanan_total'] ?? 0) ?> pesanan
    </div>
  </div>
</div>

<div class="card">
  <h2>Per bulan</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th><th>Platform</th><th class="num">Pengembalian</th>
        <th class="num">Kotor sebelum refund</th><th class="num">Rasio</th>
        <th class="num">Transaksi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($bulan as $b): $k = (float) $b['kotor_sebelum']; ?>
        <tr>
          <td class="nowrap"><?= e($b['bulan']) ?></td>
          <td><?= platformBadge((string) $b['platform']) ?></td>
          <td class="num neg"><?= rp($b['refund']) ?></td>
          <td class="num muted"><?= rp($k) ?></td>
          <td class="num <?= $k > 0 && (float) $b['refund'] / $k * 100 > 5 ? 'neg' : '' ?>">
            <?= $k > 0 ? num((float) $b['refund'] / $k * 100, 2) . '%' : '-' ?>
          </td>
          <td class="num"><?= num($b['trx_refund']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bulan === []): ?><tr><td colspan="6" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>
    Produk yang paling banyak dikembalikan
    <a class="btn ghost sm" href="<?= e(exportLink('refund_product')) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Nilai refund dibagi ke produk memakai porsi subtotal sebelum diskon, cara yang sama dengan
    laporan laba per produk. Produk dengan refund besar layak dicek mutu atau deskripsinya.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Produk</th><th>Variasi</th><th class="num">Pesanan</th>
        <th class="num">Qty retur</th><th class="num">Nilai pengembalian</th><th class="num">Porsi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($produk as $p): ?>
        <tr>
          <td class="trunc" title="<?= e($p['produk']) ?>">
            <?php if (Auth::can('costs')): ?>
              <a href="product.php?key=<?= e($p['cost_key']) ?>"><?= e($p['produk']) ?></a>
            <?php else: ?>
              <?= e($p['produk']) ?>
            <?php endif; ?>
          </td>
          <td><?= e($p['variasi'] !== '' ? $p['variasi'] : '-') ?></td>
          <td class="num"><?= num($p['pesanan']) ?></td>
          <td class="num"><?= num($p['qty_retur']) ?></td>
          <td class="num neg"><?= rp($p['refund']) ?></td>
          <td class="num muted"><?= pct($p['refund'], $refund) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($produk === []): ?>
        <tr><td colspan="6" class="muted">
          Belum bisa dipecah ke produk &mdash; berkas pesanan untuk periode ini belum diunggah.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>
    Transaksi pengembalian terbesar
    <a class="btn ghost sm" href="<?= e(exportLink('refunds')) ?>">Ekspor CSV</a>
  </h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Tgl dana dilepas</th><th>Platform</th><th>No. Pesanan</th><th>Jenis</th>
        <th class="num">Kotor sebelum refund</th><th class="num">Pengembalian</th>
        <th class="num">Dana diterima</th>
      </tr></thead>
      <tbody>
      <?php foreach ($daftar as $r): ?>
        <tr>
          <td class="nowrap"><?= shortDate($r['settlement_date']) ?></td>
          <td><?= platformBadge((string) $r['platform']) ?></td>
          <td class="nowrap">
            <?php if (Auth::can('orders')): ?>
              <a href="order.php?platform=<?= e($r['platform']) ?>&amp;id=<?= urlencode((string) $r['order_id']) ?>"><?= e($r['order_id']) ?></a>
            <?php else: ?>
              <code class="k"><?= e($r['order_id']) ?></code>
            <?php endif; ?>
          </td>
          <td><span class="badge muted"><?= e($r['trx_type']) ?></span></td>
          <td class="num muted"><?= rp($r['gross_amount']) ?></td>
          <td class="num neg"><?= rp($r['refund']) ?></td>
          <td class="num"><?= rp($r['net_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($daftar === []): ?><tr><td colspan="7" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($daftar) >= 200): ?>
    <p class="help" style="margin-top:10px">
      Menampilkan 200 transaksi terbesar. Gunakan Ekspor CSV untuk daftar lengkap.
    </p>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php render_foot(); ?>
