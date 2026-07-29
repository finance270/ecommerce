<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('monitoring');
@set_time_limit(300);

$ym = q('ym');
if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
    $ym = null;
}

$status    = Reports::uploadStatus();
$bulan     = Reports::dataMonitor();
$unmatched = Reports::unmatchedSettlements($ym, 300);

/** Nama berkas yang perlu diunggah untuk tiap jenis data. */
$jenisBerkas = [
    'tokopedia|order'      => 'Tokopedia &mdash; Semua Pesanan',
    'tokopedia|settlement' => 'Tokopedia &mdash; Transaksi/Penghasilan',
    'shopee|order'         => 'Shopee &mdash; Order',
    'shopee|settlement'    => 'Shopee &mdash; Laporan Penghasilan',
    '|hpp'                 => 'HPP per produk (template)',
    '|beban'               => 'Beban operasional (template)',
];
$statusMap = [];
foreach ($status as $s) {
    $statusMap[($s['platform'] ?? '') . '|' . $s['dataset']] = $s;
}

render_head('Monitoring Data', 'monitoring');
?>
<h1>Monitoring Kelengkapan Data</h1>
<p class="sub">
  Melihat periode mana yang datanya belum diperbarui, supaya laporan tidak dibaca sebagai
  angka final padahal berkasnya belum lengkap.
</p>

<div class="card">
  <h2>Berkas terakhir diunggah</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Jenis berkas</th><th class="num">Kali diunggah</th>
        <th>Terakhir diunggah</th><th>Data sampai</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($jenisBerkas as $key => $label):
          $s = $statusMap[$key] ?? null;
          $umur = $s !== null && $s['terakhir'] !== null
              ? (int) floor((time() - strtotime((string) $s['terakhir'])) / 86400)
              : null; ?>
        <tr>
          <td><?= $label ?></td>
          <td class="num"><?= $s !== null ? num($s['jumlah']) : '-' ?></td>
          <td class="nowrap">
            <?php if ($s !== null): ?>
              <?= e(date('d/m/Y H:i', strtotime((string) $s['terakhir']))) ?>
              <div class="muted" style="font-size:11px"><?= $umur === 0 ? 'hari ini' : $umur . ' hari lalu' ?></div>
            <?php else: ?>
              <span class="muted">belum pernah</span>
            <?php endif; ?>
          </td>
          <td class="nowrap muted"><?= $s !== null && $s['data_sampai'] !== null ? shortDate($s['data_sampai']) : '-' ?></td>
          <td>
            <?php if ($s === null): ?>
              <span class="badge bad">belum ada</span>
            <?php elseif ($umur !== null && $umur > 14): ?>
              <span class="badge warn">lebih dari 2 minggu</span>
            <?php elseif ($umur !== null && $umur > 7): ?>
              <span class="badge info">lebih dari seminggu</span>
            <?php else: ?>
              <span class="badge ok">terbaru</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    Rutinitas yang disarankan: unggah keempat berkas platform setiap minggu, lalu lengkapi HPP
    setiap awal bulan.
  </p>
</div>

<div class="card">
  <h2>
    Kelengkapan per bulan
    <a class="btn ghost sm" href="export.php?report=monitoring">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Kolom <b>Alokasi produk</b> menunjukkan berapa persen pesanan yang sudah settle dan isi
    produknya diketahui. Kurang dari 100% berarti ada settlement yang <b>berkas pesanannya belum
    diunggah</b> untuk periode itu, sehingga laba per produk belum mencakup semuanya.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th>
        <th class="num">Pesanan</th><th class="num">Settlement</th>
        <th class="num">Alokasi produk</th><th class="num">Belum ada pesanan</th>
        <th class="num">HPP</th><th class="num">Beban operasional</th>
        <th>Terakhir diperbarui</th>
      </tr></thead>
      <tbody>
      <?php foreach ($bulan as $b):
          // Dihitung dari JUMLAH pesanan, bukan nilainya: nilai settlement bisa
          // negatif (pembalikan/refund) sehingga persentase berbasis nilai
          // dapat melampaui 100% dan membingungkan.
          $alokasi = $b['settle_pesanan'] > 0
              ? ($b['settle_pesanan'] - $b['settle_tanpa_order']) / $b['settle_pesanan'] * 100
              : 100.0;
          $hppOk = $b['produk'] > 0 ? ($b['produk'] - $b['produk_tanpa_hpp']) / $b['produk'] * 100 : null;
          $upd = max((string) $b['pesanan_update'], (string) $b['settlement_update']); ?>
        <tr>
          <td class="nowrap"><b><?= e($b['ym']) ?></b></td>
          <td class="num"><?= $b['pesanan'] > 0 ? num($b['pesanan']) : '<span class="badge bad">kosong</span>' ?></td>
          <td class="num"><?= $b['settlement'] > 0 ? num($b['settlement']) : '<span class="badge muted">belum cair</span>' ?></td>
          <td class="num">
            <?php if ($b['settlement'] > 0): ?>
              <span class="<?= $alokasi >= 99.95 ? 'pos' : 'neg' ?>"><?= number_format($alokasi, 1, ',', '.') ?>%</span>
              <div class="bar"><span style="width:<?= round($alokasi) ?>%;background:<?= $alokasi >= 99.95 ? '#128a5b' : '#c0392b' ?>"></span></div>
            <?php else: ?><span class="muted">-</span><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($b['settle_tanpa_order'] > 0): ?>
              <a href="?ym=<?= e($b['ym']) ?>#belum"><?= num($b['settle_tanpa_order']) ?> dari <?= num($b['settle_pesanan']) ?></a>
              <div class="muted" style="font-size:11px">nilai <?= rp($b['nilai_tanpa_order']) ?></div>
            <?php else: ?><span class="muted">-</span><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($hppOk === null): ?><span class="muted">-</span>
            <?php elseif ($hppOk >= 100): ?><span class="badge ok">lengkap</span>
            <?php else: ?>
              <?php if (Auth::can('costs')): ?>
                <a href="costs.php?ym=<?= e($b['ym']) ?>" class="neg"><?= number_format($hppOk, 0, ',', '.') ?>%</a>
              <?php else: ?>
                <span class="neg"><?= number_format($hppOk, 0, ',', '.') ?>%</span>
              <?php endif; ?>
              <div class="muted" style="font-size:11px"><?= num($b['produk_tanpa_hpp']) ?> produk kurang</div>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($b['beban_baris'] > 0): ?><?= rp($b['beban']) ?>
            <?php elseif (Auth::can('expenses')): ?><a href="expenses.php" class="muted">belum diisi</a>
            <?php else: ?><span class="muted">belum diisi</span><?php endif; ?>
          </td>
          <td class="nowrap muted"><?= $upd !== '' ? e(date('d/m/Y H:i', strtotime($upd))) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bulan === []): ?><tr><td colspan="8" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="belum">
  <h2>
    Settlement yang belum ada data pesanannya<?= $ym !== null ? ' &mdash; ' . e($ym) : '' ?>
    <a class="btn ghost sm" href="<?= e('export.php?report=unmatched' . ($ym !== null ? '&ym=' . urlencode($ym) : '')) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Dananya sudah cair tapi berkas pesanan untuk periode itu belum diunggah, sehingga isi
    produknya belum diketahui. Inilah yang membuat <i>alokasi produk</i> tidak mencapai 100%.
    Unggah berkas <b>Semua Pesanan</b> (Tokopedia) atau <b>Order</b> (Shopee) yang mencakup
    tanggal pesanan tersebut.
  </p>
  <form method="get" class="filters" style="margin-bottom:12px">
    <div class="field">
      <label>Bulan</label>
      <select name="ym" onchange="this.form.submit()">
        <option value="">Semua bulan</option>
        <?php foreach ($bulan as $b): ?>
          <option value="<?= e($b['ym']) ?>" <?= $ym === $b['ym'] ? 'selected' : '' ?>><?= e($b['ym']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Bulan settle</th><th>Platform</th><th>No. Pesanan</th><th class="num">Dana bersih</th></tr></thead>
      <tbody>
      <?php $tot = 0.0; foreach ($unmatched as $u): $tot += (float) $u['net_amount']; ?>
        <tr>
          <td class="nowrap"><?= e($u['period_ym']) ?></td>
          <td><?= platformBadge((string) $u['platform']) ?></td>
          <td class="nowrap"><?= e($u['order_id']) ?></td>
          <td class="num"><?= rp($u['net_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($unmatched === []): ?>
        <tr><td colspan="4" class="pos">Semua settlement sudah punya data pesanan.</td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($unmatched !== []): ?>
      <tfoot><tr><td colspan="3">Total <?= count($unmatched) ?> pesanan ditampilkan</td>
        <td class="num"><?= rp($tot) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php render_foot(); ?>
