<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::require();

$platform = (string) (q('platform') ?? '');
$orderId  = (string) (q('id') ?? '');

$order = Db::one('SELECT * FROM orders WHERE platform = ? AND order_id = ?', [$platform, $orderId]);
if ($order === null) {
    render_head('Pesanan tidak ditemukan', 'orders');
    echo '<div class="alert bad">Pesanan tidak ditemukan.</div><p><a href="orders.php">&larr; Kembali</a></p>';
    render_foot();
    exit;
}

$items = Db::all('SELECT * FROM order_items WHERE order_pk = ? ORDER BY line_no', [$order['id']]);
$settlements = Db::all(
    'SELECT * FROM settlements WHERE platform = ? AND order_id = ? ORDER BY settlement_date',
    [$platform, $orderId]
);
$fees = [];
if ($settlements !== []) {
    $ids = array_column($settlements, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (Db::all(
        "SELECT * FROM settlement_fees WHERE settlement_id IN ({$ph}) ORDER BY fee_category, ABS(amount) DESC",
        $ids
    ) as $f) {
        $fees[(int) $f['settlement_id']][] = $f;
    }
}

render_head('Pesanan ' . $orderId, 'orders');
?>
<h1><?= e($orderId) ?> <?= platformBadge($platform) ?></h1>
<p class="sub">
  <a href="orders.php">&larr; Kembali ke daftar pesanan</a> &middot;
  Dibuat <?= shortDate($order['created_at_pf']) ?> &middot;
  <span class="badge <?= badgeStatus((string) $order['status_norm']) ?>"><?= e($order['status_raw']) ?></span>
</p>

<div class="grid3">
  <div class="card">
    <h2>Ringkasan nilai</h2>
    <table>
      <tr><td>Subtotal sebelum diskon</td><td class="num"><?= rp($order['items_subtotal_before']) ?></td></tr>
      <tr><td>Diskon penjual</td><td class="num neg"><?= rp(-abs((float) $order['discount_seller'])) ?></td></tr>
      <tr><td>Diskon platform</td><td class="num"><?= rp($order['discount_platform']) ?></td></tr>
      <tr><td><b>Nilai produk bersih</b></td><td class="num"><b><?= rp($order['items_subtotal_after']) ?></b></td></tr>
      <tr><td>Ongkir (asli)</td><td class="num"><?= rp($order['shipping_fee_original']) ?></td></tr>
      <tr><td>Ongkir dibayar pembeli</td><td class="num"><?= rp($order['shipping_paid_buyer']) ?></td></tr>
      <tr><td>Total dibayar pembeli</td><td class="num"><?= rp($order['order_amount']) ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h2>Pengiriman</h2>
    <table>
      <tr><td>Kurir</td><td><?= e($order['shipping_provider'] ?: '-') ?></td></tr>
      <tr><td>Layanan</td><td><?= e($order['shipping_option'] ?: '-') ?></td></tr>
      <tr><td>No. resi</td><td><?= e($order['tracking_no'] ?: '-') ?></td></tr>
      <tr><td>Berat</td><td><?= num($order['weight_gram']) ?> gram</td></tr>
      <tr><td>Provinsi</td><td><?= e($order['province'] ?: '-') ?></td></tr>
      <tr><td>Kota</td><td><?= e($order['city'] ?: '-') ?></td></tr>
      <tr><td>Metode bayar</td><td><?= e($order['payment_method'] ?: '-') ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h2>Waktu</h2>
    <table>
      <tr><td>Pesanan dibuat</td><td><?= e($order['created_at_pf'] ?: '-') ?></td></tr>
      <tr><td>Dibayar</td><td><?= e($order['paid_at'] ?: '-') ?></td></tr>
      <tr><td>Dikirim</td><td><?= e($order['shipped_at'] ?: '-') ?></td></tr>
      <tr><td>Diterima</td><td><?= e($order['delivered_at'] ?: '-') ?></td></tr>
      <tr><td>Selesai</td><td><?= e($order['completed_at'] ?: '-') ?></td></tr>
      <tr><td>Dibatalkan</td><td><?= e($order['cancelled_at'] ?: '-') ?></td></tr>
      <?php if ($order['cancel_reason']): ?>
        <tr><td>Alasan batal</td><td><?= e($order['cancel_reason']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <h2>Rincian produk (<?= count($items) ?> baris)</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>Produk</th><th>Varian</th><th>SKU</th>
        <th class="num">Qty</th><th class="num">Harga satuan</th>
        <th class="num">Subtotal</th><th class="num">Diskon penjual</th><th class="num">Bersih</th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= num($it['line_no']) ?></td>
          <td class="trunc" title="<?= e($it['product_name']) ?>"><?= e($it['product_name']) ?></td>
          <td><?= e($it['variation'] ?: '-') ?></td>
          <td><?= e($it['seller_sku'] ?: $it['sku_id'] ?: '-') ?></td>
          <td class="num"><?= num($it['qty']) ?></td>
          <td class="num"><?= rp($it['unit_price']) ?></td>
          <td class="num"><?= rp($it['subtotal_before_disc']) ?></td>
          <td class="num neg"><?= rp(-abs((float) $it['discount_seller'])) ?></td>
          <td class="num"><?= rp($it['subtotal_after_disc']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>Settlement / penghasilan</h2>
  <?php if ($settlements === []): ?>
    <p class="muted">Belum ada data settlement untuk pesanan ini. Kemungkinan dana belum dilepaskan platform,
      atau berkas laporan penghasilan periode terkait belum diunggah.</p>
  <?php else: ?>
    <?php foreach ($settlements as $s): ?>
      <div style="margin-bottom:18px">
        <h3 style="font-size:14px;margin:0 0 8px">
          <?= e($s['trx_type']) ?> &middot; dana dilepas <?= shortDate($s['settlement_date']) ?>
        </h3>
        <div class="table-wrap">
          <table>
            <tr>
              <td>Pendapatan kotor</td><td class="num"><?= rp($s['gross_amount']) ?></td>
              <td>Total biaya</td><td class="num neg"><?= rp($s['total_fee']) ?></td>
              <td><b>Diterima bersih</b></td><td class="num"><b><?= rp($s['net_amount']) ?></b></td>
            </tr>
          </table>
        </div>
        <?php if (!empty($fees[(int) $s['id']])): ?>
          <div class="table-wrap" style="margin-top:8px">
            <table>
              <thead><tr><th>Komponen</th><th>Kategori</th><th class="num">Jumlah</th></tr></thead>
              <tbody>
              <?php foreach ($fees[(int) $s['id']] as $f): $v = (float) $f['amount']; ?>
                <tr>
                  <td><?= e($f['fee_label']) ?></td>
                  <td><span class="badge muted"><?= e(Profiles::LABELS[$f['fee_category']] ?? $f['fee_category']) ?></span></td>
                  <td class="num <?= $v < 0 ? 'neg' : 'pos' ?>"><?= rp($v) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php render_foot(); ?>
