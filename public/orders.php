<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::require();

[$from, $to] = dateRange();
$platform = platformFilter();
$status = q('status');
$search = q('q');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;

$w = ['1=1'];
$args = [];
if ($from !== null)     { $w[] = 'o.order_date >= ?'; $args[] = $from; }
if ($to !== null)       { $w[] = 'o.order_date <= ?'; $args[] = $to; }
if ($platform !== null) { $w[] = 'o.platform = ?';    $args[] = $platform; }
if (in_array($status, ['selesai', 'batal', 'proses', 'retur'], true)) {
    $w[] = 'o.status_norm = ?';
    $args[] = $status;
}
if ($search !== null) {
    $w[] = '(o.order_id LIKE ? OR o.buyer_username LIKE ? OR o.recipient LIKE ? OR o.tracking_no LIKE ?)';
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like);
}
$where = implode(' AND ', $w);

$total = (int) Db::val("SELECT COUNT(*) FROM orders o WHERE {$where}", $args, 0);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = Db::all(
    "SELECT o.* FROM orders o WHERE {$where} ORDER BY o.order_date DESC, o.order_id DESC LIMIT {$per} OFFSET {$offset}",
    $args
);
$sum = Db::one(
    "SELECT COALESCE(SUM(o.items_subtotal_after),0) omzet, COALESCE(SUM(o.total_qty),0) qty
     FROM orders o WHERE {$where}",
    $args
) ?? [];

render_head('Pesanan', 'orders');
?>
<h1>Daftar Pesanan</h1>
<p class="sub"><?= num($total) ?> pesanan &middot; total nilai <?= rp($sum['omzet'] ?? 0, true) ?> &middot; <?= num($sum['qty'] ?? 0) ?> produk</p>

<form method="get" class="filters card" style="margin-bottom:18px">
  <div class="field"><label>Dari tanggal</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>Sampai tanggal</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="field">
    <label>Platform</label>
    <select name="platform">
      <option value="">Semua</option>
      <option value="tokopedia" <?= $platform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
      <option value="shopee" <?= $platform === 'shopee' ? 'selected' : '' ?>>Shopee</option>
    </select>
  </div>
  <div class="field">
    <label>Status</label>
    <select name="status">
      <option value="">Semua status</option>
      <?php foreach (['selesai' => 'Selesai', 'batal' => 'Batal', 'proses' => 'Proses', 'retur' => 'Retur'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Cari</label>
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="No. pesanan / pembeli / resi">
  </div>
  <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Terapkan</button></div>
  <div class="field"><label>&nbsp;</label><a class="btn ghost" href="?">Reset</a></div>
  <div class="field"><label>&nbsp;</label><a class="btn ghost" href="<?= e(exportLink('orders')) ?>">Ekspor CSV</a></div>
</form>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Tanggal</th><th>Platform</th><th>No. Pesanan</th><th>Status</th>
        <th>Pembeli</th><th>Kota</th><th class="num">Item</th><th class="num">Qty</th>
        <th class="num">Nilai produk</th><th class="num">Total bayar</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= shortDate($r['order_date']) ?></td>
          <td><?= platformBadge((string) $r['platform']) ?></td>
          <td class="nowrap"><a href="order.php?platform=<?= e($r['platform']) ?>&amp;id=<?= e($r['order_id']) ?>"><?= e($r['order_id']) ?></a></td>
          <td><span class="badge <?= badgeStatus((string) $r['status_norm']) ?>"><?= e($r['status_raw'] ?: $r['status_norm']) ?></span></td>
          <td class="trunc" style="max-width:150px"><?= e($r['buyer_username'] ?: $r['recipient'] ?: '-') ?></td>
          <td class="trunc" style="max-width:150px"><?= e($r['city'] ?: '-') ?></td>
          <td class="num"><?= num($r['line_count']) ?></td>
          <td class="num"><?= num($r['total_qty']) ?></td>
          <td class="num"><?= rp($r['items_subtotal_after']) ?></td>
          <td class="num"><?= rp($r['order_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="10" class="muted">Tidak ada pesanan yang cocok.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php
    $win = 2;
    $lo = max(1, $page - $win);
    $hi = min($pages, $page + $win);
    if ($page > 1): ?>
      <a href="?<?= e(buildQuery(['page' => 1])) ?>">&laquo; Awal</a>
      <a href="?<?= e(buildQuery(['page' => $page - 1])) ?>">Sebelumnya</a>
    <?php endif; ?>
    <?php for ($i = $lo; $i <= $hi; $i++): ?>
      <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
      <?php else: ?><a href="?<?= e(buildQuery(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
      <a href="?<?= e(buildQuery(['page' => $page + 1])) ?>">Berikutnya</a>
      <a href="?<?= e(buildQuery(['page' => $pages])) ?>">Akhir &raquo;</a>
    <?php endif; ?>
    <span class="muted" style="border:0;background:0">Halaman <?= $page ?> dari <?= $pages ?></span>
  </div>
  <?php endif; ?>
</div>
<?php render_foot(); ?>
