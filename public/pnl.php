<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('pnl');

$range = Reports::dataRange();
[$from, $to] = dateRange();
$platform = platformFilter();

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

// Rantai nilai baku - urutan dan dasar hitungnya sama persis dengan simulasi
// harga (lihat Reports::rantaiLaba), supaya kedua halaman tidak pernah beda.
$c = Reports::rantaiLaba([
    'kotor'    => $tot['kotor'],
    'potongan' => $tot['potongan'],
    'biaya'    => $tot['biaya'],
    // Penyesuaian dan selisih pencatatan menambah dana yang diterima, jadi
    // tandanya dibalik supaya "dana diterima" bertemu dengan angka platform.
    'lain'     => -((float) $tot['penyesuaian'] + (float) $tot['selisih']),
    'hpp'      => $hpp,
    'beban'    => $beban,
    // Nominal PPh dihitung per baris di SQL karena pemungutannya baru mulai
    // 1 Agustus 2026. Periode sebelum itu menghasilkan 0, dan periode yang
    // melintasi tanggal tersebut terkena hanya pada bagian setelahnya.
    'pph_nominal' => $pnl['ringkasan']['pph_nominal'] ?? 0,
]);

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
  Berbasis <b>tanggal pesanan</b>, dan hanya pesanan berstatus <b>selesai</b> yang dihitung &mdash;
  penjualan diakui pada saat transaksinya terjadi, bukan saat dananya cair.
  Pesanan batal dan retur tidak ikut, meski uangnya sempat bergerak.
  HPP dan beban operasional dicocokkan pada bulan pesanan yang sama.
</p>

<?php render_filter($from, $to, $platform); ?>

<?php
// Uang yang bergerak pada periode ini tetapi tidak masuk laporan. Ditampilkan
// terbuka supaya penurunan angka tidak disangka kesalahan hitung - sebagian
// besar biasanya hanya berkas pesanan yang belum diunggah.
$cakupan = Reports::cakupanLabaRugi($from, $to, $platform);
$luar = $cakupan['tanpa_pesanan'] + $cakupan['tidak_selesai'] + $cakupan['tanpa_produk'];
?>
<?php if ($luar > 0): ?>
  <div class="alert info">
    <b>Yang tidak masuk laporan ini.</b>
    Dari <?= num($cakupan['baris']) ?> baris settlement pada periode ini,
    <?php if ($cakupan['tanpa_pesanan'] > 0): ?>
      <b><?= num($cakupan['tanpa_pesanan']) ?></b> baris (<?= rp($cakupan['bersih_tanpa_pesanan'], true) ?>)
      belum diketahui pesanannya &mdash; berkas <i>Semua Pesanan</i>/<i>Order</i> periode terkait
      belum diunggah, jadi tanggal dan statusnya belum ada.
      <?= tabLink('monitoring', 'monitoring.php', 'Lihat periode mana &rarr;') ?>
    <?php endif; ?>
    <?php if ($cakupan['tidak_selesai'] > 0): ?>
      <?= $cakupan['tanpa_pesanan'] > 0 ? '<br>' : '' ?>
      <b><?= num($cakupan['tidak_selesai']) ?></b> baris
      (<?= rp($cakupan['bersih_tidak_selesai'], true) ?>) berasal dari pesanan
      <b>batal atau retur</b>, jadi memang tidak diakui sebagai penjualan.
    <?php endif; ?>
    <?php if ($cakupan['tanpa_produk'] > 0): ?>
      <br>
      <b><?= num($cakupan['tanpa_produk']) ?></b> baris berupa biaya yang dibebankan pada
      pesanan <b>tanpa nilai produk</b> &mdash; biaya platform
      <?= rp($cakupan['biaya_tanpa_produk'], true) ?>. Tidak ada produk yang bisa
      menanggungnya, jadi tidak ikut dihitung di sini agar ringkasan ini sama persis
      dengan tabel laba per produk. Angka pada laporan platform akan lebih besar
      sebesar itu.
    <?php endif; ?>
  </div>
<?php endif; ?>

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
    <div class="label">Penjualan bersih</div>
    <div class="value"><?= rp($c['penjualan_bersih'], true) ?></div>
    <div class="hint">setelah PPN &amp; pajak e-commerce</div>
  </div>
  <div class="kpi <?= $c['laba_usaha'] < 0 ? 'bad' : 'ok' ?>">
    <div class="label">Laba usaha</div>
    <div class="value"><?= rp($c['laba_usaha'], true) ?></div>
    <div class="hint">
      <?= $c['marjin_usaha'] === null ? 'setelah beban operasional'
          : number_format($c['marjin_usaha'], 1, ',', '.') . '% dari penjualan bersih' ?>
    </div>
  </div>
</div>

<div class="card">
  <h2>
    Ringkasan: dari pendapatan kotor sampai laba usaha
    <button class="btn ghost sm no-print" type="button" onclick="window.print()"
            style="float:right">Cetak / simpan PDF</button>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    <span class="no-print">Urutan dan dasar hitungnya sama persis dengan <b>simulasi harga</b> pada
    menu HPP, jadi kedua halaman tidak akan menunjukkan angka berbeda. </span>
    Biaya platform dan beban operasional bisa dibuka rinciannya; yang sedang terbuka ikut tercetak.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Komponen</th><th class="num">Jumlah (Rp)</th><th class="num">Persentase</th>
        <th>Catatan / acuan</th><th style="width:120px"></th>
      </tr></thead>
      <tbody>
        <?php
        $H = (float) $c['harga'];
        $pH = static fn(float $v): string => $H > 0 ? num($v / $H * 100, 2) . '%' : '-';
        $pJ = static fn(float $v): string => $c['penjualan_bersih'] > 0
            ? num($v / $c['penjualan_bersih'] * 100, 2) . '%' : '-';

        /** Baris ringkas + rincian yang bisa dibuka-tutup. */
        $blokPnl = static function (string $id, string $judul, float $total, array $items) use ($pH, $H): void { ?>
          <tr>
            <td><?= e($judul) ?></td>
            <td class="num neg"><?= rp(-$total) ?></td>
            <td class="num neg"><?= $pH($total) ?></td>
            <td class="muted" style="font-size:11.5px">% dari pendapatan kotor</td>
            <td>
              <?php if ($items !== []): ?>
                <button class="btn ghost sm no-print" type="button"
                        data-toggle="<?= e($id) ?>" aria-expanded="false">Lihat rincian</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php foreach ($items as $it): ?>
            <tr class="rinci <?= e($id) ?>" hidden>
              <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
              <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"><?= rp($it['nilai']) ?></td>
              <td class="num muted"><?= $pH(abs($it['nilai'])) ?></td>
              <td class="muted" style="font-size:11.5px"><?= e($it['ket']) ?></td>
              <td></td>
            </tr>
          <?php endforeach;
        };

        $rinciBiaya = array_map(static fn(array $k): array => [
            'label' => Profiles::LABELS[$k['fee_category']] ?? $k['fee_category'],
            'nilai' => (float) $k['total'],
            'ket'   => 'kategori biaya platform',
        ], $feeCats);
        $rinciBeban = array_map(static fn(array $k): array => [
            'label' => $k['category'],
            'nilai' => -abs((float) $k['total']),
            'ket'   => num($k['baris']) . ' pos tercatat',
        ], $bebanCat);
        ?>

        <tr>
          <td><b>Pendapatan kotor</b></td>
          <td class="num"><b><?= rp($c['harga']) ?></b></td>
          <td class="num muted">100,00%</td>
          <td class="muted" style="font-size:11.5px">sudah dikurangi pengembalian</td>
          <td></td>
        </tr>

        <?php $blokPnl('rPotongan', 'Potongan & diskon ditanggung penjual', (float) $c['potongan'], []); ?>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Pendapatan setelah dikurang diskon</b></td>
          <td class="num"><b><?= rp($c['setelah_diskon']) ?></b></td>
          <td class="num"><b><?= $pH((float) $c['setelah_diskon']) ?></b></td>
          <td class="muted" style="font-size:11.5px">pendapatan kotor &minus; diskon</td>
          <td></td>
        </tr>

        <?php $blokPnl('rBiaya', 'Biaya platform', (float) $c['biaya'], $rinciBiaya); ?>

        <?php if (abs((float) $c['lain']) >= 1): ?>
        <tr>
          <td>Penyesuaian &amp; selisih pencatatan</td>
          <td class="num <?= $c['lain'] > 0 ? 'neg' : 'pos' ?>"><?= rp(-(float) $c['lain']) ?></td>
          <td class="num muted"><?= $pH(abs((float) $c['lain'])) ?></td>
          <td class="muted" style="font-size:11.5px">kompensasi &amp; koreksi platform</td>
          <td></td>
        </tr>
        <?php endif; ?>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Dana diterima bersih</b></td>
          <td class="num"><b><?= rp($c['dana_diterima']) ?></b></td>
          <td class="num"><b><?= $pH((float) $c['dana_diterima']) ?></b></td>
          <td class="muted" style="font-size:11.5px">setelah diskon &minus; biaya platform</td>
          <td></td>
        </tr>

        <tr>
          <td>PPN <?= num((float) $c['ppn_persen'], 0) ?>%</td>
          <td class="num neg"><?= rp(-(float) $c['ppn']) ?></td>
          <td class="num neg"><?= $pH((float) $c['ppn']) ?></td>
          <td class="muted" style="font-size:11.5px">
            <?= num((float) $c['ppn_persen'], 0) ?>% dari pendapatan setelah dikurang diskon
          </td>
          <td></td>
        </tr>
        <tr>
          <td>Pajak e-commerce <?= num(Tax::PPH_PERSEN, 1) ?>%</td>
          <td class="num neg"><?= rp(-(float) $c['pph']) ?></td>
          <td class="num neg"><?= $pH((float) $c['pph']) ?></td>
          <td class="muted" style="font-size:11.5px">
            <?php if ((float) $c['pph'] == 0.0): ?>
              belum berlaku pada periode ini &mdash; dipungut sejak
              <?= e(date('d/m/Y', strtotime(Tax::PPH_MULAI))) ?>
            <?php else: ?>
              <?= num(Tax::PPH_PERSEN, 1) ?>% dari pendapatan setelah dikurang diskon,
              hanya untuk pesanan sejak <?= e(date('d/m/Y', strtotime(Tax::PPH_MULAI))) ?>
            <?php endif; ?>
          </td>
          <td></td>
        </tr>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Penjualan bersih</b></td>
          <td class="num"><b><?= rp($c['penjualan_bersih']) ?></b></td>
          <td class="num"><b><?= $pH((float) $c['penjualan_bersih']) ?></b></td>
          <td class="muted" style="font-size:11.5px">dana diterima &minus; PPN &minus; pajak e-commerce</td>
          <td></td>
        </tr>

        <tr>
          <td>Harga pokok penjualan (HPP)</td>
          <td class="num neg"><?= rp(-(float) $c['hpp']) ?></td>
          <td class="num neg"><?= $pJ((float) $c['hpp']) ?></td>
          <td class="muted" style="font-size:11.5px">% dari penjualan bersih</td>
          <td></td>
        </tr>
        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Laba kotor</b></td>
          <td class="num <?= $c['laba'] < 0 ? 'neg' : 'pos' ?>"><b><?= rp($c['laba']) ?></b></td>
          <td class="num"><b><?= $pJ((float) $c['laba']) ?></b></td>
          <td class="muted" style="font-size:11.5px">penjualan bersih &minus; HPP</td>
          <td></td>
        </tr>

        <?php $blokPnl('rBeban', 'Beban operasional', (float) $c['beban'], $rinciBeban); ?>

        <tr style="background:rgba(0,0,0,.02);font-weight:700">
          <td><b>Laba usaha</b></td>
          <td class="num <?= $c['laba_usaha'] < 0 ? 'neg' : 'pos' ?>"><b><?= rp($c['laba_usaha']) ?></b></td>
          <td class="num"><b><?= $pJ((float) $c['laba_usaha']) ?></b></td>
          <td class="muted" style="font-size:11.5px;font-weight:400">% dari penjualan bersih</td>
          <td></td>
        </tr>
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
        <th class="num">Diskon &amp; voucher penjual</th>
        <th class="num">Biaya platform</th><th class="num">Penyesuaian</th>
        <th class="num">Selisih pencatatan</th><th class="num">Dana diterima bersih</th>
      </tr></thead>
      <tbody>
      <?php
      $tb = ['kotor' => 0.0, 'potongan' => 0.0, 'biaya' => 0.0,
             'penyesuaian' => 0.0, 'selisih' => 0.0, 'bersih' => 0.0];
      foreach ($bridge as $b):
          foreach ($tb as $k => $_) {
              $tb[$k] += (float) $b[$k];
          } ?>
        <tr>
          <td><?= platformBadge((string) $b['platform']) ?></td>
          <td class="num"><?= rp($b['kotor']) ?></td>
          <td class="num <?= $b['potongan'] < 0 ? 'neg' : 'muted' ?>"><?= rp($b['potongan']) ?></td>
          <td class="num neg"><?= rp($b['biaya']) ?></td>
          <td class="num <?= abs($b['penyesuaian']) > 0 ? '' : 'muted' ?>"><?= rp($b['penyesuaian']) ?></td>
          <td class="num <?= abs($b['selisih']) > 0 ? 'warn' : 'muted' ?>"><?= rp($b['selisih']) ?></td>
          <td class="num pos"><b><?= rp($b['bersih']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bridge === []): ?><tr><td colspan="7" class="muted">Belum ada data settlement pada rentang ini.</td></tr><?php endif; ?>
      </tbody>
      <?php if (count($bridge) > 1): ?>
      <tfoot><tr>
        <td>Gabungan</td>
        <td class="num"><?= rp($tb['kotor']) ?></td>
        <td class="num neg"><?= rp($tb['potongan']) ?></td>
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
        <th class="num">Diskon &amp; voucher</th>
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
          <td class="num neg"><?= rp($b['total_biaya']) ?></td>
          <td class="num <?= abs((float) $b['penyesuaian']) > 0 ? '' : 'muted' ?>"><?= rp($b['penyesuaian']) ?></td>
          <td class="num <?= abs((float) $b['selisih']) > 0 ? 'warn' : 'muted' ?>"><?= rp($b['selisih']) ?></td>
          <td class="num pos"><b><?= rp($bb) ?></b></td>
          <td class="num muted"><?= $bk > 0 ? number_format($bb / $bk * 100, 1, ',', '.') . '%' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bulanan === []): ?><tr><td colspan="9" class="muted">Belum ada data.</td></tr><?php endif; ?>
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
    Hanya <b>dana keluar</b> yang ditampilkan &mdash; baris bernilai positif pada berkas
    platform adalah dana masuk dari penjualan, bukan penarikan.
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
<script>
// Buka-tutup baris rincian. Baris yang tertutup memakai [hidden] sehingga
// otomatis tidak ikut tercetak - hasil cetak selalu sama dengan tampilan.
document.querySelectorAll('[data-toggle]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var kelas = btn.getAttribute('data-toggle');
    var buka  = btn.getAttribute('aria-expanded') !== 'true';
    document.querySelectorAll('tr.rinci.' + kelas).forEach(function (tr) { tr.hidden = !buka; });
    btn.setAttribute('aria-expanded', buka ? 'true' : 'false');
    btn.textContent = buka ? 'Tutup rincian' : 'Lihat rincian';
  });
});
</script>

<?php render_foot(); ?>
