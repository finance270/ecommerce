<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Penjualan yang tidak jadi - dua peristiwa berbeda dalam satu halaman.
 *
 *   BATAL & RETUR   pesanan yang berhenti sebelum tuntas. Platform tidak
 *                   pernah membayarkannya, jadi tidak ada uang yang bergerak;
 *                   nilainya diambil dari tabel pesanan.
 *   PENGEMBALIAN    uang yang sudah cair lalu ditarik kembali. Nilainya ada
 *                   di berkas penghasilan, dan sudah dipotong dari pendapatan
 *                   kotor di Laba & Biaya.
 *
 * Keduanya sengaja disandingkan karena sering tertukar: jumlah pesanan batal
 * hampir selalu jauh lebih besar daripada jumlah transaksi pengembalian.
 */

Auth::requireTab('refunds');
@set_time_limit(300);

[$from, $to] = dateRange();
$platform = platformFilter();

$batal       = Reports::batalSummary($from, $to, $platform);
$batalBulan  = Reports::batalByMonth($from, $to, $platform);
$batalAlasan = Reports::batalByReason($from, $to, $platform, 30);
$batalDaftar = Reports::batalList($from, $to, $platform, 200);

$ring   = Reports::refundSummary($from, $to, $platform);
$bulan  = Reports::refundByMonth($from, $to, $platform);
$produk = Reports::refundByProduct($from, $to, $platform, 100);
$daftar = Reports::refundList($from, $to, $platform, 200);

$refund = (float) ($ring['refund'] ?? 0);
$rasio  = $ring['rasio'] ?? null;

render_head('Batal & Retur', 'refunds');
?>
<h1>Pembatalan &amp; Pengembalian Dana</h1>
<p class="sub">
  Dua hal yang sering tertukar, dan keduanya sama-sama <b>tidak masuk omzet</b> di Laba &amp; Biaya.
  Seluruhnya berbasis <b>tanggal pesanan</b>, sama seperti laporan Laba &amp; Biaya.
</p>
<div class="table-wrap" style="margin-bottom:18px">
  <table>
    <thead><tr><th style="width:150px">Yang terjadi</th><th>Uangnya</th><th>Angkanya dari</th></tr></thead>
    <tbody>
      <tr>
        <td><b>Batal / retur</b></td>
        <td>Platform <b>tidak pernah membayarkannya</b>, jadi tidak ada yang dikembalikan
            &mdash; nilainya sekadar penjualan yang tidak jadi.</td>
        <td class="muted">nilai produk pada berkas <b>pesanan</b></td>
      </tr>
      <tr>
        <td><b>Pengembalian dana</b></td>
        <td>Dana sudah <b>cair lalu ditarik kembali</b> oleh platform. Sudah dipotong dari
            pendapatan kotor di Laba &amp; Biaya, jadi tidak dikurangi dua kali.</td>
        <td class="muted">kolom pengembalian pada berkas <b>penghasilan</b></td>
      </tr>
    </tbody>
  </table>
</div>
<p class="sub" style="margin-top:-8px">
  Karena itu jumlah pesanan batal hampir selalu <b>jauh lebih besar</b> daripada jumlah transaksi
  pengembalian &mdash; keduanya memang mengukur hal yang berbeda.
  <?php if ($platform === 'shopee' || $platform === null): ?>
    Khusus Shopee, pesanan yang returnya disetujui tetap ditulis berstatus <b>Selesai</b>, jadi
    pesanan itu masuk ke bagian <b>pengembalian dana</b>, bukan ke bagian batal.
  <?php endif; ?>
</p>

<?php render_filter($from, $to, $platform); ?>

<h2 style="margin:22px 0 10px">Pesanan batal &amp; retur</h2>

<?php if ($batal['pesanan'] === 0): ?>
  <div class="alert ok"><b>Tidak ada pesanan batal maupun retur</b> pada rentang ini.</div>
<?php else: ?>
<div class="kpis">
  <div class="kpi <?= $batal['rasio'] !== null && $batal['rasio'] > 10 ? 'bad' : '' ?>">
    <div class="label">Nilai pesanan batal</div>
    <div class="value"><?= rp($batal['nilai'], true) ?></div>
    <div class="hint">penjualan yang tidak jadi &mdash; uangnya tidak pernah masuk</div>
  </div>
  <div class="kpi">
    <div class="label">Terhadap nilai seluruh pesanan</div>
    <div class="value"><?= $batal['rasio'] === null ? '-' : num($batal['rasio'], 2) . '%' ?></div>
    <div class="hint">dari <?= rp($batal['nilai_total'], true) ?> seluruh pesanan</div>
  </div>
  <div class="kpi">
    <div class="label">Pesanan batal / retur</div>
    <div class="value"><?= num($batal['pesanan']) ?></div>
    <div class="hint">
      <?= num($batal['batal']) ?> batal &middot; <?= num($batal['retur']) ?> retur &mdash;
      <?= $batal['rasio_pesanan'] === null ? '-' : num($batal['rasio_pesanan'], 2) . '%' ?>
      dari <?= num($batal['pesanan_total']) ?> pesanan
    </div>
  </div>
</div>

<div class="card">
  <h2>
    Pembatalan per bulan
    <a class="btn ghost sm" href="<?= e(exportLink('batal')) ?>">Ekspor CSV</a>
  </h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th><th>Platform</th><th class="num">Batal</th><th class="num">Retur</th>
        <th class="num">Nilai tidak jadi</th><th class="num">Nilai seluruh pesanan</th>
        <th class="num">Rasio</th>
      </tr></thead>
      <tbody>
      <?php foreach ($batalBulan as $b): $semua = (float) $b['nilai_semua']; ?>
        <tr>
          <td class="nowrap"><?= e($b['bulan']) ?></td>
          <td><?= platformBadge((string) $b['platform']) ?></td>
          <td class="num"><?= num($b['batal']) ?></td>
          <td class="num"><?= num($b['retur']) ?></td>
          <td class="num neg"><?= rp($b['nilai']) ?></td>
          <td class="num muted"><?= rp($semua) ?></td>
          <td class="num <?= $semua > 0 && (float) $b['nilai'] / $semua * 100 > 10 ? 'neg' : '' ?>">
            <?= $semua > 0 ? num((float) $b['nilai'] / $semua * 100, 2) . '%' : '-' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($batalBulan === []): ?><tr><td colspan="7" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($batalAlasan !== [] && !(count($batalAlasan) === 1 && $batalAlasan[0]['alasan'] === '(tidak disebutkan)')): ?>
<div class="card">
  <h2>Alasan pembatalan</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Diambil apa adanya dari kolom alasan pada berkas pesanan. Alasan yang nilainya besar layak
    ditindaklanjuti &mdash; stok kosong dan keterlambatan kirim ada di tangan Anda, berbeda
    dengan pembeli yang berubah pikiran.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Alasan</th><th class="num">Pesanan</th><th class="num">Nilai</th><th class="num">Porsi</th></tr></thead>
      <tbody>
      <?php foreach ($batalAlasan as $r): ?>
        <tr>
          <td class="trunc" title="<?= e($r['alasan']) ?>"><?= e($r['alasan']) ?></td>
          <td class="num"><?= num($r['pesanan']) ?></td>
          <td class="num neg"><?= rp($r['nilai']) ?></td>
          <td class="num muted"><?= pct((float) $r['nilai'], $batal['nilai']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Pesanan batal terbesar</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Tanggal pesanan</th><th>Platform</th><th>No. Pesanan</th><th>Status</th>
        <th class="num">Qty</th><th class="num">Nilai</th><th>Alasan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($batalDaftar as $r): ?>
        <tr>
          <td class="nowrap"><?= shortDate($r['order_date']) ?></td>
          <td><?= platformBadge((string) $r['platform']) ?></td>
          <td class="nowrap">
            <?php if (Auth::can('orders')): ?>
              <a href="order.php?platform=<?= e($r['platform']) ?>&amp;id=<?= urlencode((string) $r['order_id']) ?>"><?= e($r['order_id']) ?></a>
            <?php else: ?>
              <code class="k"><?= e($r['order_id']) ?></code>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= $r['status_norm'] === 'retur' ? 'warn' : 'bad' ?>"><?= e($r['status_raw'] ?: $r['status_norm']) ?></span></td>
          <td class="num"><?= num($r['total_qty']) ?></td>
          <td class="num neg"><?= rp($r['nilai']) ?></td>
          <td class="trunc muted" style="font-size:12px" title="<?= e((string) $r['cancel_reason']) ?>">
            <?= e($r['cancel_reason'] !== null && $r['cancel_reason'] !== '' ? $r['cancel_reason'] : '-') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($batalDaftar === []): ?><tr><td colspan="7" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($batalDaftar) >= 200): ?>
    <p class="help" style="margin-top:10px">
      Menampilkan 200 pesanan terbesar. Gunakan Ekspor CSV di atas untuk daftar lengkap.
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2 style="margin:26px 0 10px">Pengembalian dana</h2>

<?php if ($refund == 0.0): ?>
  <div class="alert ok">
    <b>Tidak ada pengembalian dana</b> pada rentang ini &mdash; tidak ada uang yang sudah cair
    lalu ditarik kembali.
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
