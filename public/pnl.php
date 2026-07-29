<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('pnl');

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
$bridge  = Reports::bridge($from, $to, $platform);
$bulanan = Reports::monthlySettlement($from, $to, $platform);
$tarik   = Reports::withdrawals($from, $to, $platform);
$tot     = Reports::bridgeTotals($bridge);
$costSum = Reports::costSummary($from, $to, $platform);
$beban   = Reports::expenseTotal($from, $to);
$bebanCat = Reports::expenseByCategory($from, $to);
$bebanTersembunyi = Reports::expenseHidden($from, $to);

$prodSort = q('psort', 'bersih');

// Dua bagian terberat (alokasi per produk & rincian tiap komponen biaya)
// diambil lewat permintaan terpisah supaya halaman langsung tampil dan
// tidak menggantung saat data sudah menumpuk.
$lazyParams = ['from' => $from, 'to' => $to, 'platform' => $platform];
$lazyProduk = 'pnl_section.php?' . http_build_query(
    array_filter($lazyParams + ['section' => 'produk', 'psort' => $prodSort], static fn($v) => $v !== null && $v !== '')
);
$lazyBiaya = 'pnl_section.php?' . http_build_query(
    array_filter($lazyParams + ['section' => 'biaya'], static fn($v) => $v !== null && $v !== '')
);

$kotor  = $tot['kotor'];
$biaya  = $tot['biaya'];
$bersih = $tot['bersih'];
$hpp        = $costSum['hpp'];
$labaKotor  = $bersih - $hpp;
$labaUsaha  = $labaKotor - $beban;

// Semua baris pengurang dari pendapatan kotor sampai dana diterima bersih.
$langkah = [
    ['Pendapatan kotor', $tot['kotor'], 'Nilai penjualan sebelum diskon apa pun', 'head'],
    ['Diskon &amp; voucher ditanggung penjual', $tot['potongan'], 'Potongan harga yang Anda tanggung sendiri', ''],
    ['Pengembalian dana ke pembeli', $tot['refund'], 'Refund atas pesanan yang dikembalikan', ''],
    ['Biaya platform', $tot['biaya'], 'Komisi, layanan, administrasi, dan biaya lain', ''],
    ['Penyesuaian', $tot['penyesuaian'], 'Kompensasi & koreksi dari platform', ''],
    ['Selisih pencatatan', $tot['selisih'], 'Selisih arsip platform, ditampilkan apa adanya', ''],
    ['Dana diterima bersih', $tot['bersih'], 'Yang benar-benar masuk ke saldo penjual', 'sub'],
    ['Harga pokok penjualan (HPP)', -$hpp, 'Modal barang yang terjual', ''],
    ['Laba kotor', $labaKotor, 'Dana diterima bersih dikurangi HPP', 'sub'],
    ['Beban operasional', -$beban, 'Gaji, sewa, listrik, packaging, dan lainnya', ''],
    ['Laba usaha', $labaUsaha, 'Laba akhir setelah seluruh biaya', 'foot'],
];

// Kategori yang benar-benar biaya (nilai negatif = beban).
$feeCats = array_values(array_filter(
    $kategori,
    static fn(array $k): bool => in_array($k['fee_category'], Profiles::FEE_CATEGORIES, true)
));
$totalFeeCat = array_sum(array_map(static fn($k) => (float) $k['total'], $feeCats));

render_head('Laba & Biaya', 'pnl');
?>
<h1>Laporan Laba &amp; Biaya</h1>
<p class="sub">
  Berbasis <b>tanggal dana dilepaskan</b> (settlement), bukan tanggal pesanan &mdash; inilah dasar
  pencatatan akuntansi karena mencerminkan kas yang benar-benar diterima.
  HPP dan beban operasional juga dicocokkan pada bulan settlement yang sama.
</p>

<?php render_filter($from, $to, $platform); ?>

<?php if ($costSum['qty_tanpa_hpp'] > 0): ?>
  <div class="alert warn">
    <b>Laba belum lengkap.</b>
    <?= num($costSum['produk_tanpa_hpp']) ?> produk (<?= num($costSum['qty_tanpa_hpp']) ?> unit terjual)
    belum punya HPP pada bulan yang bersangkutan, jadi dihitung <b>HPP = 0</b> dan laba di bawah
    tampak lebih besar dari kenyataan.
    <?= tabLink('costs', 'costs.php', 'Lengkapi HPP &rarr;') ?>
  </div>
<?php endif; ?>
<?php if ($bebanTersembunyi['baris'] > 0): ?>
  <div class="alert info">
    Akun Anda diatur <b><?= e(Perm::accessLabel(Auth::salaryAccess())) ?></b>, sehingga
    <?= num($bebanTersembunyi['baris']) ?> pos beban tidak ikut dihitung di sini.
    <b>Laba usaha di bawah belum final</b> &mdash; hubungi admin bila Anda perlu angka utuhnya.
  </div>
<?php endif; ?>
<?php if ($beban == 0.0): ?>
  <div class="alert info">
    Belum ada <b>beban operasional</b> tercatat untuk periode ini, jadi laba usaha masih sama
    dengan laba kotor. <?= tabLink('expenses', 'expenses.php', 'Catat beban operasional &rarr;') ?>
  </div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi">
    <div class="label">Pendapatan kotor</div>
    <div class="value"><?= rp($kotor, true) ?></div>
    <div class="hint"><?= num($ring['trx'] ?? 0) ?> transaksi settlement</div>
  </div>
  <div class="kpi ok">
    <div class="label">Dana diterima bersih</div>
    <div class="value"><?= rp($bersih, true) ?></div>
    <div class="hint"><?= pct($bersih, $kotor) ?> dari pendapatan kotor</div>
  </div>
  <div class="kpi">
    <div class="label">Laba kotor (setelah HPP)</div>
    <div class="value"><?= rp($labaKotor, true) ?></div>
    <div class="hint">HPP <?= rp($hpp, true) ?></div>
  </div>
  <div class="kpi <?= $labaUsaha < 0 ? 'bad' : 'ok' ?>">
    <div class="label">Laba usaha</div>
    <div class="value"><?= rp($labaUsaha, true) ?></div>
    <div class="hint"><?= $kotor > 0 ? number_format($labaUsaha / $kotor * 100, 1, ',', '.') . '% dari kotor' : 'setelah beban operasional' ?></div>
  </div>
</div>

<div class="card">
  <h2>Ringkasan: dari pendapatan kotor sampai laba usaha</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Seluruh pengurang ditampilkan berurutan sampai angka akhir, jadi tidak ada potongan yang
    tersembunyi di dalam angka lain.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Komponen</th><th>Keterangan</th>
        <th class="num">Jumlah</th><th class="num">% dari kotor</th><th style="width:130px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($langkah as [$nama, $nilai, $ket, $jenis]):
          if ($jenis === '' && (float) $nilai === 0.0) {
              continue;   // pengurang yang nihil tidak perlu ditampilkan
          } ?>
        <tr<?= in_array($jenis, ['foot', 'sub'], true) ? ' style="font-weight:700;background:#f7f9fc"' : '' ?>>
          <td><?= $nama ?></td>
          <td class="muted" style="font-weight:400"><?= $ket ?></td>
          <td class="num <?= (float) $nilai < 0 ? 'neg' : (in_array($jenis, ['foot', 'sub'], true) ? 'pos' : '') ?>"><?= rp($nilai) ?></td>
          <td class="num muted" style="font-weight:400"><?= $kotor > 0 ? pct(abs((float) $nilai), $kotor) : '-' ?></td>
          <td>
            <?php if ($jenis !== 'head'): ?>
              <div class="bar"><span style="width:<?= $kotor > 0 ? min(100, round(abs((float) $nilai) / $kotor * 100)) : 0 ?>%;background:<?= in_array($jenis, ['foot', 'sub'], true) ? '#128a5b' : '#c0392b' ?>"></span></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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

<div class="card">
    <h2>
      Struktur biaya platform per kategori
      <a class="btn ghost sm" href="<?= e(exportLink('fee_category')) ?>">Ekspor CSV</a>
    </h2>
    <p class="help" style="margin-top:-4px;margin-bottom:12px">
      Rincian baris <b>Biaya platform</b> di atas. Diskon penjual dan pengembalian dana tidak masuk
      ke sini karena keduanya pengurang pendapatan, bukan biaya yang ditagihkan platform.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Kategori akuntansi</th><th class="num">Jumlah</th><th class="num">% pendapatan kotor</th><th class="num">% total biaya</th><th style="width:110px"></th></tr></thead>
        <tbody>
        <?php $maxF = $feeCats !== [] ? max(array_map(static fn($k) => abs((float) $k['total']), $feeCats)) : 0; ?>
        <?php foreach ($feeCats as $k):
            $v = (float) $k['total']; ?>
          <tr>
            <td><?= e(Profiles::LABELS[$k['fee_category']] ?? $k['fee_category']) ?></td>
            <td class="num <?= $v < 0 ? 'neg' : 'pos' ?>"><?= rp($v) ?></td>
            <td class="num muted"><?= pct(abs($v), $kotor) ?></td>
            <td class="num muted"><?= pct(abs($v), abs($totalFeeCat)) ?></td>
            <td><div class="bar"><span style="width:<?= $maxF > 0 ? round(abs($v) / $maxF * 100) : 0 ?>%;background:<?= $v < 0 ? '#c0392b' : '#128a5b' ?>"></span></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($feeCats === []): ?><tr><td colspan="5" class="muted">Belum ada data settlement pada rentang ini.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($feeCats !== []): ?>
        <tfoot><tr>
          <td>Total biaya platform</td>
          <td class="num neg"><?= rp($totalFeeCat) ?></td>
          <td class="num"><?= pct(abs($totalFeeCat), $kotor) ?></td>
          <td class="num">100%</td><td></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <p class="help" style="margin-top:10px">
      Nilai negatif = beban yang memotong penghasilan. Nilai positif = subsidi/penggantian dari platform.
      Kolom yang sifatnya subtotal (mis. kolom <i>Ongkir</i> milik Tokopedia yang merupakan jumlah dari
      baris-baris ongkir di bawahnya) sudah dikeluarkan agar tidak terhitung dua kali, sehingga total
      di tabel ini sama dengan baris <i>Biaya platform</i> pada ringkasan di atas.
    </p>
</div>

<div class="card">
  <h2>
    Rekap per bulan
    <a class="btn ghost sm" href="<?= e(exportLink('monthly_settlement')) ?>">Ekspor CSV</a>
  </h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th><th>Platform</th><th class="num">Pendapatan kotor</th>
        <th class="num">Diskon &amp; voucher</th><th class="num">Pengembalian</th>
        <th class="num">Biaya platform</th><th class="num">Penyesuaian</th><th class="num">Selisih</th>
        <th class="num">Dana diterima bersih</th><th class="num">Marjin</th>
      </tr></thead>
      <tbody>
      <?php foreach ($bulanan as $b):
          $bk = (float) $b['pendapatan_kotor'];
          $bb = (float) $b['dana_diterima']; ?>
        <tr>
          <td class="nowrap"><?= e($b['bulan']) ?></td>
          <td><?= platformBadge((string) $b['platform']) ?></td>
          <td class="num"><?= rp($bk) ?></td>
          <td class="num <?= (float) $b['potongan'] < 0 ? 'neg' : 'muted' ?>"><?= rp($b['potongan']) ?></td>
          <td class="num <?= (float) $b['pengembalian'] < 0 ? 'neg' : 'muted' ?>"><?= rp($b['pengembalian']) ?></td>
          <td class="num neg"><?= rp($b['total_biaya']) ?></td>
          <td class="num <?= abs((float) $b['penyesuaian']) > 0 ? '' : 'muted' ?>"><?= rp($b['penyesuaian']) ?></td>
          <td class="num <?= abs((float) $b['selisih']) > 0 ? 'warn' : 'muted' ?>"><?= rp($b['selisih']) ?></td>
          <td class="num pos"><b><?= rp($bb) ?></b></td>
          <td class="num muted"><?= $bk > 0 ? number_format($bb / $bk * 100, 1, ',', '.') . '%' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bulanan === []): ?><tr><td colspan="10" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>
    Laba bersih per produk
    <a class="btn ghost sm" href="<?= e(exportLink('product_profit', ['psort' => $prodSort])) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Platform memberi angka settlement <b>per pesanan</b>, bukan per produk. Karena itu nilai
    settlement dibagi ke tiap produk sesuai porsinya dalam pesanan
    (nilai produk sebelum diskon &divide; total nilai pesanan sebelum diskon).
    Jadi angka di bawah adalah <b>alokasi</b>, bukan angka resmi platform per produk &mdash; tapi
    totalnya tetap sama dengan total settlement pesanan yang ikut terhitung.
  </p>
  <div data-lazy="<?= e($lazyProduk) ?>">
    <p class="loading">Menghitung alokasi per produk&hellip; pada data besar ini bisa memakan waktu.</p>
    <noscript><a href="<?= e($lazyProduk) ?>">Buka tabel laba bersih per produk</a></noscript>
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
  <div data-lazy="<?= e($lazyBiaya) ?>">
    <p class="loading">Memuat rincian komponen biaya&hellip;</p>
    <noscript><a href="<?= e($lazyBiaya) ?>">Buka rincian komponen biaya</a></noscript>
  </div>
</div>

<?php if ($bebanCat !== []): ?>
<div class="card">
  <h2>
    Beban operasional per kategori
    <?php if (Auth::can('expenses')): ?>
      <a class="btn ghost sm" href="expenses.php">Kelola beban</a>
    <?php endif; ?>
  </h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Kategori</th><th class="num">Jumlah</th><th class="num">% dari kotor</th><th style="width:110px"></th></tr></thead>
      <tbody>
      <?php $maxB = max(array_map(static fn($b) => (float) $b['total'], $bebanCat)); ?>
      <?php foreach ($bebanCat as $b): ?>
        <tr>
          <td><?= e($b['category']) ?></td>
          <td class="num neg"><?= rp(-(float) $b['total']) ?></td>
          <td class="num muted"><?= pct($b['total'], $kotor) ?></td>
          <td><div class="bar"><span style="width:<?= $maxB > 0 ? round((float) $b['total'] / $maxB * 100) : 0 ?>%;background:#c0392b"></span></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total beban operasional</td><td class="num neg"><?= rp(-$beban) ?></td>
        <td class="num"><?= pct($beban, $kotor) ?></td><td></td></tr></tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

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
