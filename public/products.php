<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('products');

$range = Reports::dataRange();
[$from, $to] = dateRange();
$platform = platformFilter();
$sort = q('sort', 'omzet') === 'qty' ? 'qty' : 'omzet';
$detail = q('produk');

if ($from === null && $to === null && $range['order_to'] !== null) {
    $to = $range['order_to'];
    $from = date('Y-m-d', strtotime($to . ' -89 days'));
}

$rows = Reports::topProducts($from, $to, $platform, 100, $sort);
$variants = $detail !== null ? Reports::topVariants($from, $to, $platform, $detail) : [];
$totOmzet = array_sum(array_map(static fn($r) => (float) $r['omzet'], $rows));
$maxV = $rows !== [] ? max(array_map(static fn($r) => (float) $r[$sort === 'qty' ? 'qty_terjual' : 'omzet'], $rows)) : 0;

render_head('Produk', 'products');
?>
<h1>Performa Produk</h1>
<p class="sub">Hanya menghitung pesanan berstatus <b>selesai</b>, berdasarkan tanggal pesanan.</p>

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
    <label>Urutkan</label>
    <select name="sort">
      <option value="omzet" <?= $sort === 'omzet' ? 'selected' : '' ?>>Omzet tertinggi</option>
      <option value="qty" <?= $sort === 'qty' ? 'selected' : '' ?>>Jumlah terjual</option>
    </select>
  </div>
  <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Terapkan</button></div>
  <div class="field"><label>&nbsp;</label><a class="btn ghost" href="<?= e(exportLink('products')) ?>">Ekspor CSV</a></div>
</form>

<?php if ($detail !== null): ?>
  <div class="card">
    <h2>Varian: <?= e($detail) ?> <a class="btn ghost sm" href="?<?= e(buildQuery(['produk' => null])) ?>">Tutup</a></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Varian</th><th>SKU</th><th class="num">Qty terjual</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php foreach ($variants as $v): ?>
          <tr>
            <td><?= e($v['varian']) ?></td>
            <td><?= e($v['sku']) ?></td>
            <td class="num"><?= num($v['qty_terjual']) ?></td>
            <td class="num"><?= rp($v['omzet']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($variants === []): ?><tr><td colspan="4" class="muted">Tidak ada data varian.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Produk terlaris (<?= count($rows) ?> produk teratas)</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>Produk</th><th>Platform</th>
        <th class="num">Pesanan</th><th class="num">Qty terjual</th><th class="num">Qty retur</th>
        <th class="num">Omzet</th><th class="num">Kontribusi</th><th style="width:110px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r):
          $val = (float) $r[$sort === 'qty' ? 'qty_terjual' : 'omzet']; ?>
        <tr>
          <td class="muted"><?= $i + 1 ?></td>
          <td class="trunc" title="<?= e($r['produk']) ?>">
            <a href="?<?= e(buildQuery(['produk' => $r['produk']])) ?>"><?= e($r['produk']) ?></a>
          </td>
          <td><?= platformBadge((string) $r['platform']) ?></td>
          <td class="num"><?= num($r['pesanan']) ?></td>
          <td class="num"><?= num($r['qty_terjual']) ?></td>
          <td class="num <?= (int) $r['qty_retur'] > 0 ? 'neg' : 'muted' ?>"><?= num($r['qty_retur']) ?></td>
          <td class="num"><?= rp($r['omzet']) ?></td>
          <td class="num muted"><?= pct($r['omzet'], $totOmzet) ?></td>
          <td><div class="bar"><span style="width:<?= $maxV > 0 ? round($val / $maxV * 100) : 0 ?>%"></span></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="9" class="muted">Belum ada data pada rentang ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_foot(); ?>
