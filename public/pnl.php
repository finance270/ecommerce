<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::require();

$range = Reports::dataRange();
[$from, $to] = dateRange();
$platform = platformFilter();
if ($from === null && $to === null && $range['settlement_to'] !== null) {
    $to = $range['settlement_to'];
    $from = date('Y-m-01', strtotime($to . ' -2 months'));
}

$pnl     = Reports::pnl($from, $to, $platform);
$ring    = $pnl['ringkasan'];
$kategori = $pnl['kategori'];
$detail  = Reports::feeDetail($from, $to, $platform);
$bridge  = Reports::bridge($from, $to, $platform);
$bulanan = Reports::monthlySettlement($from, $to, $platform);
$tarik   = Reports::withdrawals($from, $to, $platform);

$kotor = (float) ($ring['pendapatan_kotor'] ?? 0);
$biaya = (float) ($ring['total_biaya'] ?? 0);
$bersih = (float) ($ring['dana_diterima'] ?? 0);

// Kategori yang benar-benar biaya (nilai negatif = beban).
$feeCats = array_values(array_filter(
    $kategori,
    static fn(array $k): bool => in_array($k['fee_category'], Profiles::FEE_CATEGORIES, true)
));
$totalFeeCat = array_sum(array_map(static fn($k) => (float) $k['total'], $feeCats));

render_head('Laba & Biaya', 'pnl');
?>
<h1>Laporan Laba &amp; Biaya Platform</h1>
<p class="sub">
  Berbasis <b>tanggal dana dilepaskan</b> (settlement), bukan tanggal pesanan &mdash; inilah dasar
  pencatatan akuntansi karena mencerminkan kas yang benar-benar diterima.
</p>

<?php render_filter($from, $to, $platform); ?>

<div class="kpis">
  <div class="kpi">
    <div class="label">Pendapatan kotor</div>
    <div class="value"><?= rp($kotor, true) ?></div>
    <div class="hint"><?= num($ring['trx'] ?? 0) ?> transaksi settlement</div>
  </div>
  <div class="kpi bad">
    <div class="label">Total biaya platform</div>
    <div class="value"><?= rp($biaya, true) ?></div>
    <div class="hint"><?= pct(abs($biaya), $kotor) ?> dari pendapatan kotor</div>
  </div>
  <div class="kpi">
    <div class="label">Pengembalian dana</div>
    <div class="value"><?= rp($ring['pengembalian'] ?? 0, true) ?></div>
    <div class="hint">refund ke pembeli</div>
  </div>
  <div class="kpi ok">
    <div class="label">Dana diterima bersih</div>
    <div class="value"><?= rp($bersih, true) ?></div>
    <div class="hint">masuk ke saldo penjual</div>
  </div>
</div>

<div class="card">
  <h2>Jembatan angka: dari pendapatan kotor ke dana yang diterima</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Setiap baris diambil dari kolom resmi berkas ekspor, sehingga angkanya bisa dicocokkan langsung
    dengan laporan platform saat audit. <b>Pendapatan kotor</b> adalah nilai penjualan sebelum diskon
    apa pun &mdash; <i>Subtotal sebelum diskon</i> untuk Tokopedia dan <i>Harga Asli Produk</i> untuk
    Shopee &mdash; supaya kedua platform setara dan diskon yang Anda tanggung terlihat jelas.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Platform</th><th class="num">Pendapatan kotor</th>
        <th class="num">Diskon &amp; voucher penjual</th><th class="num">Pengembalian dana</th>
        <th class="num">Biaya platform</th><th class="num">Penyesuaian</th>
        <th class="num">Selisih pencatatan</th><th class="num">Dana diterima bersih</th>
      </tr></thead>
      <tbody>
      <?php
      $tb = ['kotor' => 0.0, 'potongan' => 0.0, 'refund' => 0.0, 'biaya' => 0.0,
             'penyesuaian' => 0.0, 'selisih' => 0.0, 'bersih' => 0.0];
      foreach ($bridge as $b):
          foreach ($tb as $k => $_) {
              $tb[$k] += (float) $b[$k];
          } ?>
        <tr>
          <td><?= platformBadge((string) $b['platform']) ?></td>
          <td class="num"><?= rp($b['kotor']) ?></td>
          <td class="num <?= $b['potongan'] < 0 ? 'neg' : 'muted' ?>"><?= rp($b['potongan']) ?></td>
          <td class="num <?= $b['refund'] < 0 ? 'neg' : 'muted' ?>"><?= rp($b['refund']) ?></td>
          <td class="num neg"><?= rp($b['biaya']) ?></td>
          <td class="num <?= abs($b['penyesuaian']) > 0 ? '' : 'muted' ?>"><?= rp($b['penyesuaian']) ?></td>
          <td class="num <?= abs($b['selisih']) > 0 ? 'warn' : 'muted' ?>"><?= rp($b['selisih']) ?></td>
          <td class="num pos"><b><?= rp($b['bersih']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bridge === []): ?><tr><td colspan="8" class="muted">Belum ada data settlement pada rentang ini.</td></tr><?php endif; ?>
      </tbody>
      <?php if (count($bridge) > 1): ?>
      <tfoot><tr>
        <td>Gabungan</td>
        <td class="num"><?= rp($tb['kotor']) ?></td>
        <td class="num neg"><?= rp($tb['potongan']) ?></td>
        <td class="num neg"><?= rp($tb['refund']) ?></td>
        <td class="num neg"><?= rp($tb['biaya']) ?></td>
        <td class="num"><?= rp($tb['penyesuaian']) ?></td>
        <td class="num"><?= rp($tb['selisih']) ?></td>
        <td class="num pos"><?= rp($tb['bersih']) ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    <b>Selisih pencatatan</b> muncul bila total per kolom pada berkas platform tidak persis sama dengan
    kolom penghasilan per pesanan. Aplikasi menampilkannya apa adanya, tidak menyembunyikannya, sehingga
    <i>Dana diterima bersih</i> selalu sama dengan angka resmi platform.
  </p>
</div>

<div class="grid2">
  <div class="card">
    <h2>
      Struktur biaya per kategori
      <a class="btn ghost sm" href="<?= e(exportLink('fee_category')) ?>">Ekspor CSV</a>
    </h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Kategori akuntansi</th><th class="num">Jumlah</th><th class="num">% pendapatan</th><th style="width:110px"></th></tr></thead>
        <tbody>
        <?php $maxF = $feeCats !== [] ? max(array_map(static fn($k) => abs((float) $k['total']), $feeCats)) : 0; ?>
        <?php foreach ($feeCats as $k):
            $v = (float) $k['total']; ?>
          <tr>
            <td><?= e(Profiles::LABELS[$k['fee_category']] ?? $k['fee_category']) ?></td>
            <td class="num <?= $v < 0 ? 'neg' : 'pos' ?>"><?= rp($v) ?></td>
            <td class="num muted"><?= pct(abs($v), $kotor) ?></td>
            <td><div class="bar"><span style="width:<?= $maxF > 0 ? round(abs($v) / $maxF * 100) : 0 ?>%;background:<?= $v < 0 ? '#c0392b' : '#128a5b' ?>"></span></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($feeCats === []): ?><tr><td colspan="4" class="muted">Belum ada data settlement pada rentang ini.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($feeCats !== []): ?>
        <tfoot><tr>
          <td>Total biaya (dari rincian)</td>
          <td class="num neg"><?= rp($totalFeeCat) ?></td>
          <td class="num"><?= pct(abs($totalFeeCat), $kotor) ?></td><td></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <p class="help" style="margin-top:10px">
      Nilai negatif = beban yang memotong penghasilan. Nilai positif = subsidi/penggantian dari platform.
      Kolom yang sifatnya subtotal (mis. kolom <i>Ongkir</i> milik Tokopedia yang merupakan jumlah dari
      baris-baris ongkir di bawahnya) sudah dikeluarkan agar tidak terhitung dua kali, sehingga total
      di tabel ini sama dengan kolom <i>Biaya platform</i> pada jembatan di atas.
    </p>
  </div>

  <div class="card">
    <h2>Rekap per bulan</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Bulan</th><th>Platform</th><th class="num">Kotor</th><th class="num">Biaya</th><th class="num">Bersih</th><th class="num">% biaya</th></tr></thead>
        <tbody>
        <?php foreach ($bulanan as $b): ?>
          <tr>
            <td class="nowrap"><?= e($b['bulan']) ?></td>
            <td><?= platformBadge((string) $b['platform']) ?></td>
            <td class="num"><?= rp($b['pendapatan_kotor']) ?></td>
            <td class="num neg"><?= rp($b['total_biaya']) ?></td>
            <td class="num pos"><?= rp($b['dana_diterima']) ?></td>
            <td class="num muted"><?= pct(abs((float) $b['total_biaya']), (float) $b['pendapatan_kotor']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($bulanan === []): ?><tr><td colspan="6" class="muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <h2>
    Rincian setiap komponen biaya
    <a class="btn ghost sm" href="<?= e(exportLink('fee_detail')) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Nama komponen persis seperti pada berkas ekspor platform, sehingga angkanya bisa ditelusuri balik
    ke laporan asli saat audit.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Platform</th><th>Komponen biaya (nama asli platform)</th><th>Kategori</th>
        <th class="num">Jumlah transaksi</th><th class="num">Total</th>
      </tr></thead>
      <tbody>
      <?php foreach ($detail as $d): $v = (float) $d['total']; ?>
        <tr>
          <td><?= platformBadge((string) $d['platform']) ?></td>
          <td><?= e($d['fee_label']) ?></td>
          <td><span class="badge muted"><?= e(Profiles::LABELS[$d['fee_category']] ?? $d['fee_category']) ?></span></td>
          <td class="num"><?= num($d['jumlah_transaksi']) ?></td>
          <td class="num <?= $v < 0 ? 'neg' : 'pos' ?>"><?= rp($v) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($detail === []): ?><tr><td colspan="5" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($tarik !== []): ?>
<div class="card">
  <h2>Penarikan dana ke rekening bank</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Dipakai untuk mencocokkan saldo platform dengan mutasi rekening bank.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tanggal</th><th>Platform</th><th>Status</th><th class="num">Jumlah transaksi</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php $totTarik = 0.0; foreach ($tarik as $t): $totTarik += (float) $t['total']; ?>
        <tr>
          <td class="nowrap"><?= shortDate($t['withdraw_date']) ?></td>
          <td><?= platformBadge((string) $t['platform']) ?></td>
          <td><span class="badge <?= $t['status'] === 'Transferred' ? 'ok' : 'info' ?>"><?= e($t['status']) ?></span></td>
          <td class="num"><?= num($t['jumlah']) ?></td>
          <td class="num"><?= rp($t['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total penarikan</td><td class="num"><?= rp($totTarik) ?></td></tr></tfoot>
    </table>
  </div>
</div>
<?php endif; ?>
<?php render_foot(); ?>
