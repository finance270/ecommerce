<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('settlements');

[$from, $to] = dateRange();
$platform = platformFilter();
$search = q('q');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;

$w = ['1=1'];
$args = [];
if ($from !== null)     { $w[] = 's.settlement_date >= ?'; $args[] = $from; }
if ($to !== null)       { $w[] = 's.settlement_date <= ?'; $args[] = $to; }
if ($platform !== null) { $w[] = 's.platform = ?';         $args[] = $platform; }
if ($search !== null)   { $w[] = 's.order_id LIKE ?';      $args[] = '%' . $search . '%'; }
$where = implode(' AND ', $w);

$total = (int) Db::val("SELECT COUNT(*) FROM settlements s WHERE {$where}", $args, 0);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = Db::all(
    "SELECT s.* FROM settlements s WHERE {$where}
     ORDER BY s.settlement_date DESC, s.id DESC LIMIT {$per} OFFSET {$offset}",
    $args
);
$sum = Db::one(
    "SELECT COALESCE(SUM(gross_amount),0) kotor, COALESCE(SUM(total_fee),0) biaya,
            COALESCE(SUM(net_amount),0) bersih
     FROM settlements s WHERE {$where}",
    $args
) ?? [];

render_head('Settlement', 'settlements');
?>
<h1>Settlement / Penghasilan</h1>
<p class="sub">
  <?= num($total) ?> transaksi &middot; kotor <?= rp($sum['kotor'] ?? 0, true) ?>
  &middot; biaya <?= rp($sum['biaya'] ?? 0, true) ?> &middot; bersih <?= rp($sum['bersih'] ?? 0, true) ?>
</p>

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
  <div class="field"><label>Cari no. pesanan</label><input type="text" name="q" value="<?= e($search) ?>"></div>
  <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Terapkan</button></div>
  <div class="field"><label>&nbsp;</label><a class="btn ghost" href="?">Reset</a></div>
  <div class="field"><label>&nbsp;</label><a class="btn ghost" href="<?= e(exportLink('settlements')) ?>">Ekspor CSV</a></div>
</form>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Tgl dana dilepas</th><th>Platform</th><th>No. Pesanan</th><th>Jenis</th>
        <th class="num">Kotor</th><th class="num">Komisi</th><th class="num">Layanan</th>
        <th class="num">Admin</th><th class="num">Total biaya</th><th class="num">Bersih</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= shortDate($r['settlement_date']) ?></td>
          <td><?= platformBadge((string) $r['platform']) ?></td>
          <td class="nowrap"><a href="order.php?platform=<?= e($r['platform']) ?>&amp;id=<?= e($r['order_id']) ?>"><?= e($r['order_id']) ?></a></td>
          <td><span class="badge muted"><?= e($r['trx_type']) ?></span></td>
          <td class="num"><?= rp($r['gross_amount']) ?></td>
          <td class="num neg"><?= rp($r['fee_komisi']) ?></td>
          <td class="num neg"><?= rp($r['fee_layanan']) ?></td>
          <td class="num neg"><?= rp($r['fee_administrasi']) ?></td>
          <td class="num neg"><?= rp($r['total_fee']) ?></td>
          <td class="num pos"><?= rp($r['net_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="10" class="muted">Tidak ada data settlement.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?>
      <a href="?<?= e(buildQuery(['page' => 1])) ?>">&laquo; Awal</a>
      <a href="?<?= e(buildQuery(['page' => $page - 1])) ?>">Sebelumnya</a>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
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
