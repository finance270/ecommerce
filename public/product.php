<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Rincian satu produk: dari pendapatan kotor sampai laba bersih, dipecah per
 * platform dan per bulan, plus daftar pesanan yang memuat produk tersebut.
 * Dibuka dengan mengklik nama produk pada uji kewajaran HPP.
 */

Auth::requireTab('costs');
@set_time_limit(300);

$key = (string) (q('key') ?? '');
if (preg_match('/^[0-9a-f]{40}$/', $key) !== 1) {
    render_head('Produk tidak dikenal', 'costs');
    echo '<div class="alert bad">Kunci produk tidak dikenal.</div>'
       . '<p><a class="btn ghost" href="costs.php">&larr; Kembali ke HPP</a></p>';
    render_foot();
    exit;
}

$ym = q('ym');
if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
    $ym = null;
}
$platform = platformFilter();
$view = q('view') === 'pesanan' ? 'pesanan' : 'ringkas';

// Daftar pesanan memuat nomor pesanan dan nama pembeli - itu isi tab Pesanan,
// jadi ikut hak akses tab tersebut, bukan hak akses HPP.
$bolehPesanan = Auth::can('orders');
if ($view === 'pesanan' && !$bolehPesanan) {
    Auth::requireTab('orders');
}

$ident = Reports::productIdentity($key);
if ($ident === null) {
    render_head('Produk tidak ditemukan', 'costs');
    echo '<div class="alert bad">Produk ini tidak ada pada data penjualan.</div>'
       . '<p><a class="btn ghost" href="costs.php">&larr; Kembali ke HPP</a></p>';
    render_foot();
    exit;
}

$perPlatform = Reports::productBreakdown($key, $ym, $platform, 'platform');
$total       = Reports::productTotal($perPlatform);

$judul = (string) $ident['produk'];
render_head($judul, 'costs');

// buildQuery() menggabungkan dengan $_GET yang sedang berlaku, jadi setiap
// parameter yang ingin dibuang harus disebut null secara eksplisit - kalau
// tidak, tombol "Semua" justru mengembalikan tautan yang masih tersaring.
$linkView = static fn(array $o): string => 'product.php?' . buildQuery(
    $o + ['ym' => null, 'platform' => null, 'view' => null, 'page' => null]
);
?>
<h1 class="trunc" title="<?= e($judul) ?>"><?= e($judul) ?></h1>
<p class="sub">
  <?php if ($ident['variasi'] !== ''): ?>Variasi <b><?= e($ident['variasi']) ?></b> &middot; <?php endif; ?>
  <?php if ($ident['sku'] !== ''): ?>SKU <code class="k"><?= e($ident['sku']) ?></code> &middot; <?php endif; ?>
  <?= $ym !== null ? 'Bulan settlement <b>' . e($ym) . '</b>' : 'Semua bulan' ?>
  <?= $platform !== null ? ' &middot; ' . platformBadge($platform) : '' ?>
  &middot; <a href="costs.php<?= $ym !== null ? '?ym=' . e($ym) : '' ?>">&larr; Kembali ke HPP</a>
</p>

<?php if ($total['pesanan'] === 0): ?>
  <div class="alert warn">
    Produk ini belum punya settlement pada rentang yang dipilih, jadi tidak ada nilai yang bisa
    dirinci. Coba lebarkan rentangnya.
  </div>
  <?php if ($ym !== null || $platform !== null): ?>
    <p><a class="btn" href="<?= e($linkView(['key' => $key, 'ym' => null, 'platform' => null, 'view' => null, 'page' => null])) ?>">
      Lihat semua bulan &amp; platform
    </a></p>
  <?php endif; ?>
  <?php render_foot(); exit; ?>
<?php endif; ?>

<form method="get" class="filters card" style="margin-bottom:18px">
  <input type="hidden" name="key" value="<?= e($key) ?>">
  <?php if ($view === 'pesanan'): ?><input type="hidden" name="view" value="pesanan"><?php endif; ?>
  <div class="field">
    <label>Bulan settlement</label>
    <input type="month" name="ym" value="<?= e($ym) ?>">
  </div>
  <div class="field">
    <label>Platform</label>
    <select name="platform">
      <option value="">Semua</option>
      <option value="tokopedia" <?= $platform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
      <option value="shopee" <?= $platform === 'shopee' ? 'selected' : '' ?>>Shopee</option>
    </select>
  </div>
  <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Terapkan</button></div>
  <?php if ($ym !== null || $platform !== null): ?>
    <div class="field"><label>&nbsp;</label>
      <a class="btn ghost" href="<?= e($linkView(['key' => $key, 'view' => $view === 'pesanan' ? 'pesanan' : null])) ?>">Semua</a>
    </div>
  <?php endif; ?>
</form>

<?php
$marjin = $total['marjin'];
$kelasMarjin = $marjin === null ? '' : ($marjin < 0 ? 'bad' : ($marjin > 70 ? 'warn' : 'ok'));
?>
<div class="kpis">
  <div class="kpi">
    <div class="label">Pesanan</div>
    <div class="value"><?= num($total['pesanan']) ?></div>
    <div class="hint"><?= num($total['qty']) ?> unit terjual</div>
  </div>
  <div class="kpi">
    <div class="label">Pendapatan kotor</div>
    <div class="value"><?= rp($total['kotor'], true) ?></div>
    <div class="hint">sebelum potongan &amp; biaya</div>
  </div>
  <div class="kpi">
    <div class="label">Dana diterima bersih</div>
    <div class="value"><?= rp($total['bersih'], true) ?></div>
    <div class="hint"><?= pct($total['bersih'], $total['kotor']) ?> dari kotor</div>
  </div>
  <div class="kpi">
    <div class="label">HPP</div>
    <div class="value"><?= rp($total['hpp'], true) ?></div>
    <div class="hint"><?= pct($total['hpp'], $total['bersih']) ?> dari dana bersih</div>
  </div>
  <div class="kpi <?= $kelasMarjin ?>">
    <div class="label">Laba bersih</div>
    <div class="value"><?= rp($total['laba'], true) ?></div>
    <div class="hint">marjin <?= $marjin === null ? '-' : num($marjin, 1) . '%' ?></div>
  </div>
</div>

<?php if ($total['qty_tanpa_hpp'] > 0): ?>
  <div class="alert warn">
    <b>HPP belum lengkap.</b> <?= num($total['qty_tanpa_hpp']) ?> dari <?= num($total['qty']) ?> unit
    (<?= pct($total['qty_tanpa_hpp'], $total['qty']) ?>) belum punya HPP pada bulannya, jadi bagian itu
    dihitung <b>HPP = 0</b> dan laba di atas tampak lebih besar dari kenyataan.
  </div>
<?php endif; ?>

<?php
// Rantai nilai: tiap baris diukur terhadap pendapatan kotor supaya terlihat
// berapa persen yang benar-benar tersisa jadi laba.
$kotor = (float) $total['kotor'];
// Rantai nilai baku - sama persis dengan Laba & Biaya dan simulasi harga.
$c = Reports::rantaiLaba($total);
$rantai = [
    ['Pendapatan kotor',           $c['harga'],            false, '% dari pendapatan kotor'],
    ['Potongan & diskon',          -$c['potongan'],        true,  '% dari pendapatan kotor'],
    ['Pendapatan setelah diskon',  $c['setelah_diskon'],   false, 'pendapatan kotor &minus; diskon'],
    ['Biaya platform',             -$c['biaya'],           true,  '% dari pendapatan kotor'],
    ['Dana diterima bersih',       $c['dana_diterima'],    false, 'setelah diskon &minus; biaya platform'],
    ['PPN ' . num($c['ppn_persen'], 0) . '%', -$c['ppn'],  true,
     num($c['ppn_persen'], 0) . '% dari pendapatan setelah dikurang diskon'],
    ['Pajak e-commerce ' . num($c['pph_persen'], 1) . '%', -$c['pph'], true,
     num($c['pph_persen'], 1) . '% dari pendapatan setelah dikurang diskon'],
    ['Penjualan bersih',           $c['penjualan_bersih'], false, 'dana diterima &minus; PPN &minus; pajak'],
    ['HPP',                        -$c['hpp'],             true,  '% dari penjualan bersih'],
    ['Laba bersih',                $c['laba'],             false, '% dari penjualan bersih'],
];
// Baris HPP dan laba diukur terhadap penjualan bersih, sisanya terhadap kotor.
$basisJual = ['HPP', 'Laba bersih'];
?>
<div class="card">
  <h2>Rincian nilai <span class="muted" style="font-weight:400;font-size:13px">&mdash; persen dihitung terhadap pendapatan kotor</span></h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Komponen</th><th class="num">Nilai (Rp)</th><th class="num">Persentase</th>
        <th class="num">Per unit</th><th>Catatan / acuan</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rantai as [$nama, $nilai, $pengurang, $ket]):
          $tebal = in_array($nama, ['Dana diterima bersih', 'Penjualan bersih', 'Laba bersih'], true);
          $qty = (int) $total['qty'];
          // Baris HPP dan laba diukur terhadap penjualan bersih, sisanya kotor.
          $basis = in_array($nama, $basisJual, true) ? $c['penjualan_bersih'] : $c['harga']; ?>
        <tr<?= $tebal ? ' style="background:rgba(0,0,0,.02)"' : '' ?>>
          <td><?= $tebal ? '<b>' . e($nama) . '</b>' : e($nama) ?></td>
          <td class="num <?= $pengurang || $nilai < 0 ? 'neg' : '' ?>">
            <?= $tebal ? '<b>' . rp($nilai) . '</b>' : rp($nilai) ?>
          </td>
          <td class="num <?= $pengurang || $nilai < 0 ? 'neg' : 'muted' ?>">
            <?php
            // Baris pengurang sudah bertanda minus di kolom nilai, jadi
            // persennya ditampilkan positif. Baris hasil mempertahankan
            // tandanya supaya rugi terbaca sebagai marjin negatif.
            $angka = $pengurang ? abs($nilai) : $nilai;
            echo $basis > 0 ? num($angka / $basis * 100, 2) . '%' : '-';
            ?>
          </td>
          <td class="num muted"><?= $qty > 0 ? rp($nilai / $qty) : '-' ?></td>
          <td class="muted"><?= $ket ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    Marjin laba = laba bersih &divide; penjualan bersih =
    <b><?= $c['marjin'] === null ? '-' : num($c['marjin'], 2) . '%' ?></b>.
    Urutan dan dasar hitungnya sama dengan Laba &amp; Biaya serta simulasi harga.
  </p>
</div>

<?php
/**
 * Apakah kolom ini boleh ditampilkan porsinya?
 *
 * "Persen dari total" cuma punya arti kalau seluruh baris pada kolom itu
 * searah. Kolom laba bisa bercampur - ada bulan untung, ada bulan rugi - dan
 * totalnya adalah selisihnya. Kalau porsinya dipaksakan, bulan yang untung
 * tampak menyumbang minus dan bulan yang paling rugi tampak menyumbang 225%.
 * Dalam keadaan begitu porsinya dikosongkan; nilai rupiahnya tetap terbaca.
 */
$kolomSearah = static function (array $rows, string $col): bool {
    $positif = false;
    $negatif = false;
    foreach ($rows as $r) {
        $v = (float) ($r[$col] ?? 0);
        $positif = $positif || $v > 0;
        $negatif = $negatif || $v < 0;
    }
    return !($positif && $negatif);
};

/** Tabel pecahan per dimensi, tiap kolom disertai porsinya terhadap total. */
$tabelPecahan = static function (array $rows, array $total, string $judulKolom, bool $badgePlatform) use ($kolomSearah): void {
    // Porsi dihitung dari nilai mutlak supaya kolom yang seluruhnya negatif
    // (potongan, biaya, HPP) tetap terbaca sebagai porsi positif.
    $bagian = static function (string $col) use ($rows, $total, $kolomSearah): callable {
        $boleh = $kolomSearah($rows, $col);
        return static function (array $r) use ($col, $total, $boleh): string {
            if (!$boleh) {
                return '-';
            }
            return pct(abs((float) ($r[$col] ?? 0)), abs((float) ($total[$col] ?? 0)));
        };
    };
    $pPesanan = $bagian('pesanan');
    $pQty     = $bagian('qty');
    $pKotor   = $bagian('kotor');
    $pPot     = $bagian('potongan');
    $pBersih  = $bagian('bersih');
    $pHpp     = $bagian('hpp');
    $pLaba    = $bagian('laba');
    ?>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th><?= e($judulKolom) ?></th>
          <th class="num">Pesanan</th><th class="num">Qty</th>
          <th class="num">Kotor</th><th class="num">Potongan</th>
          <th class="num">Dana bersih</th><th class="num">HPP</th>
          <th class="num">Laba</th><th class="num">Marjin</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap">
              <?= $badgePlatform ? platformBadge((string) $r['label']) : e((string) $r['label']) ?>
            </td>
            <td class="num"><?= num($r['pesanan']) ?><br><span class="muted" style="font-size:11px"><?= $pPesanan($r) ?></span></td>
            <td class="num"><?= num($r['qty']) ?><br><span class="muted" style="font-size:11px"><?= $pQty($r) ?></span></td>
            <td class="num"><?= rp($r['kotor']) ?><br><span class="muted" style="font-size:11px"><?= $pKotor($r) ?></span></td>
            <td class="num neg"><?= rp(-abs((float) $r['potongan'])) ?><br><span class="muted" style="font-size:11px"><?= $pPot($r) ?></span></td>
            <td class="num"><?= rp($r['bersih']) ?><br><span class="muted" style="font-size:11px"><?= $pBersih($r) ?></span></td>
            <td class="num neg">
              <?= rp(-abs((float) $r['hpp'])) ?>
              <br><span class="muted" style="font-size:11px">
                <?php if ($r['qty_tanpa_hpp'] > 0): ?>
                  <?= num($r['qty_tanpa_hpp']) ?> unit tanpa HPP
                <?php else: ?>
                  <?= $pHpp($r) ?>
                <?php endif; ?>
              </span>
            </td>
            <td class="num <?= $r['laba'] < 0 ? 'neg' : 'pos' ?>"><?= rp($r['laba']) ?><br><span class="muted" style="font-size:11px"><?= $pLaba($r) ?></span></td>
            <td class="num <?= $r['marjin'] !== null && $r['marjin'] < 0 ? 'neg' : '' ?>">
              <?= $r['marjin'] === null ? '-' : num($r['marjin'], 1) . '%' ?>
              <?php if ($r['qty_tanpa_hpp'] > 0): ?>
                <br><span class="muted" style="font-size:11px"
                      title="Sebagian unit belum punya HPP, jadi marjinnya tampak lebih besar">semu</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td><b>Total</b></td>
          <td class="num"><b><?= num($total['pesanan']) ?></b></td>
          <td class="num"><b><?= num($total['qty']) ?></b></td>
          <td class="num"><b><?= rp($total['kotor']) ?></b></td>
          <td class="num neg"><b><?= rp(-abs((float) $total['potongan'])) ?></b></td>
          <td class="num"><b><?= rp($total['bersih']) ?></b></td>
          <td class="num neg"><b><?= rp(-abs((float) $total['hpp'])) ?></b></td>
          <td class="num <?= $total['laba'] < 0 ? 'neg' : 'pos' ?>"><b><?= rp($total['laba']) ?></b></td>
          <td class="num"><b><?= $total['marjin'] === null ? '-' : num($total['marjin'], 1) . '%' ?></b></td>
        </tr></tfoot>
      </table>
    </div>
    <?php
};
?>

<?php
// Pecahan per platform dan per bulan hanya di tampilan ringkas. Pada tampilan
// daftar pesanan keduanya cuma mengulang isi halaman sebelumnya sambil
// menambah dua query berat, sementara yang dicari pengguna ada di bawah.
if ($view === 'ringkas'):
    $perBulan = Reports::productBreakdown($key, $ym, $platform, 'bulan');
    usort($perBulan, static fn($a, $b) => strcmp((string) $b['label'], (string) $a['label']));
?>
  <div class="card">
    <h2>Per platform</h2>
    <?php $tabelPecahan($perPlatform, $total, 'Platform', true); ?>
  </div>

  <div class="card">
    <h2>Per bulan settlement</h2>
    <?php $tabelPecahan($perBulan, $total, 'Bulan', false); ?>
    <p class="help" style="margin-top:10px">
      Pilih bulan lewat penyaring di atas untuk mempersempit seluruh halaman ini.
    </p>
  </div>

  <div class="card">
    <h2>Simulasi harga jual</h2>
    <p class="muted" style="margin-bottom:12px">
      Menghitung harga jual yang diperlukan untuk mencapai marjin tertentu, memakai rerata
      potongan, biaya platform, dan HPP produk ini selama 3 bulan terakhir.
    </p>
    <a class="btn" target="_blank" rel="noopener"
       href="simulasi.php?<?= e(http_build_query(array_filter(
           ['key' => $key, 'platform' => $platform], static fn($v) => $v !== null && $v !== ''))) ?>">
      Buka simulasi harga &rarr;
    </a>
  </div>
<?php endif; ?>


<?php
// ---- Daftar pesanan yang memuat produk ini ----
if (!$bolehPesanan) {
    echo '<div class="card"><h2>Daftar pesanan</h2>'
       . '<p class="muted">Akun Anda tidak diberi hak membuka tab Pesanan, jadi daftar pesanan '
       . 'untuk produk ini tidak ditampilkan.</p></div>';
    render_foot();
    exit;
}

if ($view !== 'pesanan') {
    ?>
    <div class="card">
      <h2>Daftar pesanan</h2>
      <p class="muted" style="margin-bottom:12px">
        <?= num($total['pesanan']) ?> pesanan memuat produk ini pada rentang yang dipilih.
        Tiap pesanan bisa dibuka rinciannya seperti di tab Pesanan.
      </p>
      <a class="btn" href="<?= e($linkView(['key' => $key, 'ym' => $ym, 'platform' => $platform, 'view' => 'pesanan'])) ?>">
        Lihat daftar pesanan &rarr;
      </a>
    </div>
    <?php
    render_foot();
    exit;
}

$per    = 50;
$jumlah = Reports::productOrderCount($key, $ym, $platform);
$pages  = max(1, (int) ceil($jumlah / $per));
$page   = max(1, (int) ($_GET['page'] ?? 1));
$page   = min($page, $pages);
$daftar = Reports::productOrders($key, $ym, $platform, $per, ($page - 1) * $per);
?>
<div class="card">
  <h2>Daftar pesanan <span class="muted" style="font-weight:400;font-size:13px">&mdash; <?= num($jumlah) ?> pesanan, nilai sudah dialokasikan ke produk ini saja</span></h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Tanggal</th><th>No. Pesanan</th><th>Platform</th><th>Status</th>
        <th class="num">Qty</th><th class="num">Kotor</th><th class="num">Potongan</th>
        <th class="num">Dana bersih</th><th class="num">HPP</th><th class="num">Laba</th>
        <th class="num">Marjin</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($daftar as $o): ?>
        <tr>
          <td class="nowrap"><?= shortDate($o['order_date']) ?></td>
          <td class="nowrap"><code class="k"><?= e($o['order_id']) ?></code></td>
          <td><?= platformBadge((string) $o['platform']) ?></td>
          <td><span class="badge <?= badgeStatus((string) $o['status_norm']) ?>"><?= e($o['status_raw']) ?></span></td>
          <td class="num"><?= num($o['qty']) ?></td>
          <td class="num"><?= rp($o['kotor']) ?></td>
          <td class="num neg"><?= rp(-abs((float) $o['potongan'])) ?></td>
          <td class="num"><?= rp($o['bersih']) ?></td>
          <td class="num neg"><?= rp(-abs((float) $o['hpp'])) ?></td>
          <td class="num <?= $o['laba'] < 0 ? 'neg' : 'pos' ?>"><?= rp($o['laba']) ?></td>
          <td class="num <?= $o['marjin'] !== null && $o['marjin'] < 0 ? 'neg' : '' ?>">
            <?= $o['marjin'] === null ? '-' : num($o['marjin'], 1) . '%' ?>
          </td>
          <td class="nowrap">
            <a class="btn ghost sm" href="order.php?platform=<?= e($o['platform']) ?>&amp;id=<?= urlencode((string) $o['order_id']) ?>">Rincian</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($daftar === []): ?>
        <tr><td colspan="12" class="muted">Tidak ada pesanan pada rentang ini.</td></tr>
      <?php endif; ?>
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
    </div>
  <?php endif; ?>

  <p class="help" style="margin-top:10px">
    Nilai di tabel ini adalah <b>bagian produk ini saja</b> dari tiap pesanan, dihitung memakai porsi
    subtotal sebelum diskon. Buka <b>Rincian</b> untuk melihat pesanan utuhnya beserta produk lain
    di dalamnya.
  </p>
</div>
<?php render_foot(); ?>
