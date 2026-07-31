<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Simulasi penentuan harga jual satu produk.
 *
 * Halaman berdiri sendiri - dibuka di tab baru dari uji kewajaran HPP - supaya
 * bisa dipakai fokus dan dicetak apa adanya jadi PDF.
 *
 * Dasarnya adalah PESANAN TERAKHIR yang sudah selesai dan lengkap biayanya.
 * Histori terakhir tiap platform ditampilkan berdampingan; bila pengguna belum
 * memilih platform, yang dipakai adalah yang HARGA JUALNYA PALING TINGGI.
 * HPP diambil dari bulan terakhir yang ada isinya. Semuanya hanya nilai awal -
 * seluruh angka bisa diubah manual, dan tombol Reset mengembalikannya ke
 * histori terakhir.
 *
 * Seluruh persentase marjin memakai penyebut PENJUALAN BERSIH, yaitu harga
 * jual dikurangi pajak, pengembalian, dan potongan/diskon - lihat
 * Reports::penjualanBersih(). Biaya platform tidak ikut dikurangkan dari
 * penyebut karena itu biaya menjual, bukan pengurang penjualan.
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

$platform = platformFilter();
$ident    = Reports::productIdentity($key);
if ($ident === null) {
    render_head('Produk tidak ditemukan', 'costs');
    echo '<div class="alert bad">Produk ini tidak ada pada data penjualan.</div>'
       . '<p><a class="btn ghost" href="costs.php">&larr; Kembali ke HPP</a></p>';
    render_foot();
    exit;
}

// Histori terakhir tiap platform ditampilkan berdampingan. Kalau pengguna
// belum memilih platform, yang dipakai sebagai dasar simulasi adalah yang
// HARGA JUALNYA PALING TINGGI - itu patokan teratas untuk menetapkan harga.
$perPf = [];
foreach (['tokopedia', 'shopee'] as $pf) {
    $o = Reports::latestSettledOrder($key, $pf);
    if ($o === null) {
        continue;
    }
    $d = Reports::pricingFromOrder($key, (string) $o['platform'], (string) $o['order_id']);
    if ($d['qty'] === 0) {
        continue;
    }
    $perPf[$pf] = ['ref' => $o, 'dasar' => $d];
}

if ($platform !== null) {
    $ref = $perPf[$platform]['ref'] ?? null;
} else {
    $ref = null;
    $tertinggi = -1.0;
    foreach ($perPf as $x) {
        if ((float) $x['dasar']['harga_unit'] > $tertinggi) {
            $tertinggi = (float) $x['dasar']['harga_unit'];
            $ref = $x['ref'];
        }
    }
}

$hppDb = Reports::latestCost($key);
$judul = (string) $ident['produk'];

render_head('Simulasi harga - ' . $judul, 'costs');
?>
<h1 title="<?= e($judul) ?>"><?= e($judul) ?></h1>
<p class="sub">
  <?php if ($ident['variasi'] !== ''): ?>
    Variasi <b><?= e($ident['variasi']) ?></b>
  <?php endif; ?>
  <?php if ($ident['sku'] !== ''): ?>
    &middot; SKU <code class="k"><?= e($ident['sku']) ?></code>
  <?php endif; ?>
  <span class="no-print">
    &middot; <a href="product.php?key=<?= e($key) ?>">Lihat rincian produk</a>
    &middot; <a href="costs.php">&larr; Kembali ke HPP</a>
  </span>
</p>

<form method="get" class="filters card no-print" style="margin-bottom:18px">
  <input type="hidden" name="key" value="<?= e($key) ?>">
  <div class="field">
    <label>Platform</label>
    <select name="platform" onchange="this.form.submit()">
      <option value="">Semua platform</option>
      <option value="tokopedia" <?= $platform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
      <option value="shopee"    <?= $platform === 'shopee'    ? 'selected' : '' ?>>Shopee</option>
    </select>
  </div>
  <div class="field"><label>&nbsp;</label>
    <button class="btn ghost" type="button" onclick="window.print()">Cetak / simpan PDF</button>
  </div>
</form>

<?php if ($ref === null): ?>
  <div class="alert warn">
    <b>Belum ada dasar perhitungan.</b>
    Produk ini belum punya pesanan <b>selesai</b> yang biayanya sudah tercatat
    <?= $platform !== null ? 'di ' . ($platform === 'shopee' ? 'Shopee' : 'Tokopedia') : '' ?>.
    <?php if ($platform !== null): ?>
      Coba pilih <b>Semua platform</b> di atas, atau unggah berkas laporan penghasilan terbaru.
    <?php else: ?>
      Unggah berkas laporan penghasilan terbaru lebih dulu.
    <?php endif; ?>
  </div>
  <?php render_foot(); exit; ?>
<?php endif; ?>

<?php
$dasar = Reports::pricingFromOrder($key, (string) $ref['platform'], (string) $ref['order_id']);
$rinci = Reports::breakdownFromOrder($key, (string) $ref['platform'], (string) $ref['order_id']);

if ($dasar['qty'] === 0) {
    echo '<div class="alert warn">Pesanan terakhir tidak bisa dipakai sebagai dasar '
       . '(nilai produknya nol).</div>';
    render_foot();
    exit;
}

$hargaUnit = (float) $dasar['harga_unit'];
$hppUnit   = $hppDb !== null ? (float) $hppDb['cost_per_unit'] : 0.0;
$hargaTot  = (float) $dasar['harga'];
$qty       = (int) $dasar['qty'];
$perUnit   = static fn(float $total): float => $total / $qty;

// ---- Lapisan pajak ----
// Kalau marketplace SUDAH memungut PPh Pasal 22 pada pesanan acuan, nilainya
// sudah masuk ke biaya platform. Menambahkannya lagi berarti dihitung dua kali,
// jadi tarif awalnya dinolkan dan alasannya diberitahukan.
$pphSudahDipungut = (float) $dasar['pajak_platform'] > 0;
$ppnPersen = Tax::PPN_PERSEN;
$pphPersen = $pphSudahDipungut ? 0.0 : Tax::PPH_PERSEN;

// Porsi terhadap harga jual (harga sudah termasuk PPN).
$ppnPorsi = $ppnPersen / (100 + $ppnPersen) * 100;      // 11%  -> 9,9099%
$dppPorsi = 100 - $ppnPorsi;
$pphPorsi = $pphPersen * $dppPorsi / 100;               // 0,5% dari DPP

$ppnUnit = $hargaUnit * $ppnPorsi / 100;
$dppUnit = $hargaUnit - $ppnUnit;
$pphUnit = $dppUnit * $pphPersen / 100;

$bersihUnit  = $perUnit((float) $dasar['bersih']);
$setelahUnit = $bersihUnit - $ppnUnit - $pphUnit;

// Penyebut seluruh persentase marjin: penjualan bersih, yaitu harga jual
// dikurangi pajak, pengembalian, dan potongan/diskon. Biaya platform TIDAK
// ikut dikurangkan - itu biaya menjual, bukan pengurang penjualan.
$jualBersihUnit = $hargaUnit - $ppnUnit - $pphUnit
                - $perUnit((float) $dasar['refund'])
                - $perUnit((float) $dasar['potongan']);
$labaUnit   = $setelahUnit - $hppUnit;
$marjinKini = $jualBersihUnit > 0 && $hppUnit > 0 ? $labaUnit / $jualBersihUnit * 100 : null;
?>

<?php if (count($perPf) > 1): ?>
<div class="card">
  <h2>Histori terakhir tiap platform</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Pesanan terakhir yang selesai dan lengkap biayanya di masing-masing platform. Yang dipakai
    sebagai dasar simulasi adalah yang <b>harga jualnya paling tinggi</b>
    <?= $platform !== null ? ' (saat ini dikunci ke platform pilihan Anda)' : '' ?>.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Platform</th><th>Pesanan terakhir</th><th>Dana dilepas</th>
        <th class="num">Harga jual/unit</th><th class="num">Potongan</th>
        <th class="num">Biaya platform</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($perPf as $pf => $x):
          $dipakai = $ref !== null && (string) $ref['order_id'] === (string) $x['ref']['order_id']
                     && (string) $ref['platform'] === (string) $x['ref']['platform']; ?>
        <tr<?= $dipakai ? ' style="background:rgba(0,0,0,.02)"' : '' ?>>
          <td><?= platformBadge($pf) ?></td>
          <td class="nowrap"><code class="k"><?= e($x['ref']['order_id']) ?></code></td>
          <td class="nowrap"><?= shortDate($x['ref']['settlement_date']) ?></td>
          <td class="num"><b><?= rp((float) $x['dasar']['harga_unit']) ?></b></td>
          <td class="num neg"><?= num((float) $x['dasar']['potongan_pct'], 2) ?>%</td>
          <td class="num neg"><?= num((float) $x['dasar']['biaya_pct'], 2) ?>%</td>
          <td class="nowrap">
            <?php if ($dipakai): ?>
              <span class="badge ok">dipakai</span>
            <?php else: ?>
              <a class="btn ghost sm no-print"
                 href="simulasi.php?key=<?= e($key) ?>&amp;platform=<?= e($pf) ?>">Pakai ini</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Dasar perhitungan</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Diambil dari <b>pesanan terakhir yang selesai dan sudah lengkap biayanya</b>:
    <?= platformBadge((string) $ref['platform']) ?>
    <code class="k"><?= e($ref['order_id']) ?></code>,
    dana dilepas <b><?= shortDate($ref['settlement_date']) ?></b>
    (<?= num($qty) ?> unit produk ini di dalamnya).
    <br>
    <?php if ($hppDb !== null): ?>
      HPP diambil dari bulan terakhir yang terisi: <b><?= e($hppDb['period_ym']) ?></b>.
    <?php else: ?>
      <b class="neg">HPP produk ini belum pernah diisi</b> &mdash; isi manual di bawah,
      atau lengkapi lewat menu <?= tabLink('costs', 'costs.php', 'HPP') ?>.
    <?php endif; ?>
  </p>

  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Komponen</th><th class="num">Per unit</th><th class="num">% dari harga jual</th><th style="width:120px"></th>
      </tr></thead>
      <tbody>
        <tr>
          <td><b>Harga jual terdaftar</b></td>
          <td class="num"><b><?= rp($hargaUnit) ?></b></td>
          <td class="num muted">100,00%</td>
          <td class="muted" style="font-size:11.5px">termasuk PPN, sebelum diskon</td>
        </tr>
        <tr>
          <td>PPN <?= num($ppnPersen, 0) ?>% keluaran</td>
          <td class="num neg"><?= rp(-$ppnUnit) ?></td>
          <td class="num neg"><?= num($ppnPorsi, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">dititipkan ke negara</td>
        </tr>
        <tr>
          <td class="muted">= Peredaran bruto (DPP, tanpa PPN)</td>
          <td class="num muted"><?= rp($dppUnit) ?></td>
          <td class="num muted"><?= num($dppPorsi, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">dasar hitung PPh</td>
        </tr>
        <?php if ($pphPersen > 0): ?>
        <tr>
          <td>PPh Pasal 22 e-commerce <?= num($pphPersen, 1) ?>%</td>
          <td class="num neg"><?= rp(-$pphUnit) ?></td>
          <td class="num neg"><?= num($pphPorsi, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">dipungut marketplace</td>
        </tr>
        <?php endif; ?>
        <?php if ($dasar['refund'] > 0): ?>
        <tr>
          <td>Pengembalian dana (refund)</td>
          <td class="num neg"><?= rp(-$perUnit((float) $dasar['refund'])) ?></td>
          <td class="num neg"><?= num((float) $dasar['refund_pct'], 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">barang kembali</td>
        </tr>
        <?php endif; ?>

        <?php
        /** Baris ringkas + baris rincian yang bisa dibuka-tutup. */
        $blok = static function (string $id, string $judul, float $total, float $pct, array $items)
                use ($perUnit, $hargaTot): void {
            if ($total <= 0 && $items === []) {
                return;
            } ?>
          <tr>
            <td><?= e($judul) ?></td>
            <td class="num neg"><?= rp(-$perUnit($total)) ?></td>
            <td class="num neg"><?= num($pct, 2) ?>%</td>
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
              <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"><?= rp($perUnit($it['nilai'])) ?></td>
              <td class="num muted"><?= num(abs($it['nilai']) / $hargaTot * 100, 2) ?>%</td>
              <td class="muted" style="font-size:11.5px"><?= e(Profiles::LABELS[$it['kategori']] ?? $it['kategori']) ?></td>
            </tr>
          <?php endforeach;
        };

        $blok('rPotongan', 'Potongan & diskon ditanggung penjual',
              (float) $dasar['potongan'], (float) $dasar['potongan_pct'], $rinci['potongan']);
        $blok('rBiaya', 'Biaya platform',
              (float) $dasar['biaya'], (float) $dasar['biaya_pct'], $rinci['biaya']);
        ?>

        <?php if (abs((float) $dasar['lain']) >= 1): ?>
        <tr>
          <td>Penyesuaian &amp; selisih platform</td>
          <td class="num <?= $dasar['lain'] > 0 ? 'neg' : 'pos' ?>"><?= rp(-$perUnit((float) $dasar['lain'])) ?></td>
          <td class="num muted"><?= num(abs((float) $dasar['lain_pct']), 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">kompensasi &amp; koreksi</td>
        </tr>
        <?php endif; ?>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Penjualan bersih</b></td>
          <td class="num"><b><?= rp($jualBersihUnit) ?></b></td>
          <td class="num"><b><?= num($jualBersihUnit / $hargaUnit * 100, 2) ?>%</b></td>
          <td class="muted" style="font-size:11.5px">dasar hitung marjin</td>
        </tr>
        <tr>
          <td class="muted">Dana dari platform (sebelum pajak)</td>
          <td class="num muted"><?= rp($bersihUnit) ?></td>
          <td class="num muted"><?= num((float) $dasar['bersih_pct'], 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">yang masuk rekening</td>
        </tr>
        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Dana bersih setelah pajak</b></td>
          <td class="num"><b><?= rp($setelahUnit) ?></b></td>
          <td class="num"><b><?= num($setelahUnit / $hargaUnit * 100, 2) ?>%</b></td>
          <td></td>
        </tr>
        <tr>
          <td>HPP per unit</td>
          <td class="num neg"><?= $hppUnit > 0 ? rp(-$hppUnit) : '<span class="badge warn">belum ada</span>' ?></td>
          <td class="num neg"><?= $hppUnit > 0 ? num($hppUnit / $hargaUnit * 100, 2) . '%' : '-' ?></td>
          <td class="muted" style="font-size:11.5px"><?= $hppDb !== null ? e($hppDb['period_ym']) : '' ?></td>
        </tr>
        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Laba bersih sekarang</b></td>
          <td class="num <?= $labaUnit < 0 ? 'neg' : 'pos' ?>"><b><?= rp($labaUnit) ?></b></td>
          <td class="num"><b>marjin <?= $marjinKini === null ? '-' : num($marjinKini, 1) . '%' ?></b></td>
          <td class="muted" style="font-size:11.5px">dari penjualan bersih</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>
    Simulasi
    <button class="btn ghost sm no-print" type="button" id="simReset" style="float:right">
      Reset ke histori terakhir
    </button>
  </h2>
  <p class="help no-print" style="margin-top:-4px;margin-bottom:14px">
    Semua angka di bawah bisa diubah. Isi <b>harga jual</b> untuk melihat marjin yang didapat, atau
    isi <b>marjin</b> yang diinginkan untuk melihat harga jual yang diperlukan. Persentase potongan,
    biaya, dan nilai HPP juga bisa disesuaikan kalau Anda tahu tarifnya akan berubah &mdash;
    tombol <b>Reset</b> mengembalikan semuanya ke angka histori terakhir.
  </p>

  <div class="grid2 no-print" style="margin-bottom:8px">
    <div class="field">
      <label for="simHarga">Harga jual per unit (Rp)</label>
      <input type="number" id="simHarga" step="100" min="0" style="width:100%">
    </div>
    <div class="field">
      <label for="simMarjin">Marjin bersih setelah HPP (%)</label>
      <input type="number" id="simMarjin" step="0.1" max="99.9" style="width:100%">
    </div>
  </div>
  <div class="grid3 no-print" style="margin-bottom:10px">
    <div class="field">
      <label for="simPotongan">Potongan &amp; diskon (%)</label>
      <input type="number" id="simPotongan" step="0.01" min="0" max="99" style="width:100%">
    </div>
    <div class="field">
      <label for="simBiaya">Biaya platform (%)</label>
      <input type="number" id="simBiaya" step="0.01" min="0" max="99" style="width:100%">
    </div>
    <div class="field">
      <label for="simHpp">HPP per unit (Rp)</label>
      <input type="number" id="simHpp" step="100" min="0" style="width:100%">
    </div>
  </div>
  <div class="grid3 no-print" style="margin-bottom:16px">
    <div class="field">
      <label for="simPpn">PPN (%) &mdash; harga sudah termasuk</label>
      <input type="number" id="simPpn" step="0.5" min="0" max="99" style="width:100%">
    </div>
    <div class="field">
      <label for="simPph">PPh Pasal 22 e-commerce (%)</label>
      <input type="number" id="simPph" step="0.1" min="0" max="99" style="width:100%">
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <label style="font-weight:400;font-size:12.5px;display:flex;align-items:center;gap:7px">
        <input type="checkbox" id="simKreditPpn" style="width:auto">
        HPP termasuk PPN masukan yang bisa dikreditkan
      </label>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Komponen</th><th class="num">Per unit</th><th class="num">% dari harga jual</th>
      </tr></thead>
      <tbody>
        <tr><td><b>Harga jual</b></td><td class="num" id="oHarga"><b>-</b></td><td class="num muted">100,00%</td></tr>
        <tr><td>PPN keluaran</td><td class="num neg" id="oPpn">-</td><td class="num neg" id="pPpn">-</td></tr>
        <tr><td class="muted">= Peredaran bruto (DPP)</td><td class="num muted" id="oDpp">-</td>
            <td class="num muted" id="pDpp">-</td></tr>
        <tr><td>PPh Pasal 22 e-commerce</td><td class="num neg" id="oPph">-</td>
            <td class="num neg" id="pPph">-</td></tr>
        <?php if ($dasar['refund'] > 0): ?>
          <tr><td>Pengembalian dana</td><td class="num neg" id="oRefund">-</td>
              <td class="num neg" id="pRefund">-</td></tr>
        <?php endif; ?>
        <tr>
          <td>Potongan &amp; diskon</td><td class="num neg" id="oPotongan">-</td>
          <td class="num neg" id="pPotongan">-</td>
        </tr>
        <?php foreach ($rinci['potongan'] as $it): ?>
          <tr class="rinci rPotongan" hidden>
            <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
            <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"
                data-grup="potongan"
                data-share="<?= e((string) ($it['nilai'] / -(float) $dasar['potongan'])) ?>">-</td>
            <td class="num muted"><?= num(abs($it['nilai']) / $hargaTot * 100, 2) ?>%</td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td>Biaya platform</td><td class="num neg" id="oBiaya">-</td>
          <td class="num neg" id="pBiaya">-</td>
        </tr>
        <?php foreach ($rinci['biaya'] as $it): ?>
          <tr class="rinci rBiaya" hidden>
            <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
            <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"
                data-grup="biaya"
                data-share="<?= e((string) ($it['nilai'] / -(float) $dasar['biaya'])) ?>">-</td>
            <td class="num muted"><?= num(abs($it['nilai']) / $hargaTot * 100, 2) ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (abs((float) $dasar['lain']) >= 1): ?>
          <tr><td>Penyesuaian &amp; selisih</td><td class="num" id="oLain">-</td>
              <td class="num muted"><?= num(abs((float) $dasar['lain_pct']), 2) ?>%</td></tr>
        <?php endif; ?>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Penjualan bersih</b> <span class="muted" style="font-size:11.5px">dasar marjin</span></td>
            <td class="num" id="oJual"><b>-</b></td><td class="num" id="pJual"><b>-</b></td></tr>
        <tr><td class="muted">Dana dari platform (sebelum pajak)</td>
            <td class="num muted" id="oPlatform">-</td><td class="num muted" id="pPlatform">-</td></tr>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Dana bersih setelah pajak</b></td><td class="num" id="oBersih"><b>-</b></td>
            <td class="num" id="pBersih"><b>-</b></td></tr>
        <tr><td>HPP per unit <span class="muted" id="oHppKet" style="font-size:11.5px"></span></td>
            <td class="num neg" id="oHpp">-</td>
            <td class="num neg" id="oHppPct">-</td></tr>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Laba bersih per unit</b></td><td class="num" id="oLaba"><b>-</b></td>
            <td class="num" id="oMarjin"><b>-</b></td></tr>
      </tbody>
    </table>
  </div>

  <div id="simCatatan" class="help" style="margin-top:12px"></div>
  <div id="simUbah" class="help" style="margin-top:6px"></div>

  <div class="alert info" style="margin-top:14px">
    <b>Soal pajaknya.</b>
    Harga jual di etalase dianggap <b>sudah termasuk PPN <?= num(Tax::PPN_PERSEN, 0) ?>%</b>, jadi bagian PPN-nya
    bukan pendapatan Anda melainkan titipan yang disetor ke negara &mdash; DPP dihitung mundur
    (harga &divide; 1,<?= num(Tax::PPN_PERSEN, 0) ?>). Isi <b>0</b> kalau Anda bukan Pengusaha Kena Pajak.
    <br>
    <b>PPh Pasal 22 e-commerce <?= num(Tax::PPH_PERSEN, 1) ?>%</b> (PMK 37/2025) dipungut langsung oleh marketplace
    dari <b>peredaran bruto tanpa PPN</b> &mdash; itulah sebabnya di atas dikalikan DPP, bukan harga jual.
    Tokopedia, Shopee, Lazada, dan Blibli resmi memungut mulai
    <b><?= shortDate(Tax::PPH_MULAI) ?></b><?= Tax::pphBerlaku() ? '' : ' (belum berjalan pada data yang ada sekarang)' ?>.
    Orang pribadi dengan peredaran bruto sampai
    <b><?= rp(Tax::PPH_BEBAS_OMZET, true) ?></b> setahun tidak dipungut &mdash; isi <b>0</b> kalau Anda termasuk.
    Pungutan ini bukan beban baru: nilainya jadi pengurang PPh Final atau kredit pajak di SPT Tahunan,
    tapi kasnya tetap keluar lebih dulu sehingga tetap diperhitungkan saat menentukan harga.
    <?php if ($pphSudahDipungut): ?>
      <br><b class="neg">Pesanan acuan ini sudah dipungut PPh oleh marketplace</b>, nilainya sudah termasuk
      di biaya platform. Tarif PPh di atas dinolkan supaya tidak terhitung dua kali.
    <?php endif; ?>
  </div>

</div>

<script>
(function () {
  // Nilai awal dari histori terakhir. Dipakai juga oleh tombol Reset.
  var awal = {
    harga:   <?= json_encode(round($hargaUnit)) ?>,
    hpp:     <?= json_encode(round($hppUnit)) ?>,
    refPct:  <?= json_encode(round((float) $dasar['refund_pct'], 6)) ?>,
    potPct:  <?= json_encode(round((float) $dasar['potongan_pct'], 6)) ?>,
    biPct:   <?= json_encode(round((float) $dasar['biaya_pct'], 6)) ?>,
    lainPct: <?= json_encode(round((float) $dasar['lain_pct'], 6)) ?>,
    ppn:     <?= json_encode($ppnPersen) ?>,
    pph:     <?= json_encode($pphPersen) ?>,
    kreditPpn: false
  };
  var marjinWajarMin = <?= json_encode(Reports::MARJIN_MIN) ?>;
  var marjinWajarMax = <?= json_encode(Reports::MARJIN_MAX) ?>;

  function el(id) { return document.getElementById(id); }
  var elHarga = el('simHarga'), elMarjin = el('simMarjin');
  var elPot = el('simPotongan'), elBi = el('simBiaya'), elHpp = el('simHpp');
  var elPpn = el('simPpn'), elPph = el('simPph'), elKredit = el('simKreditPpn');

  function rp(n) {
    return (n < 0 ? '-' : '') + 'Rp ' + Math.round(Math.abs(n)).toLocaleString('id-ID');
  }
  function pc(n, d) { return n.toFixed(d === undefined ? 1 : d).replace('.', ',') + '%'; }
  function set(id, txt, tebal) {
    var e = el(id);
    if (e) { e.innerHTML = tebal ? '<b>' + txt + '</b>' : txt; }
  }
  function ambil(input, fallback) {
    var v = parseFloat(input.value);
    return isFinite(v) ? v : fallback;
  }

  /**
   * Porsi PPN terhadap harga jual. Harga sudah termasuk PPN, jadi dihitung
   * mundur: 11% -> 11/111 = 9,9099% dari harga jual.
   */
  function ppnPorsi() {
    var t = ambil(elPpn, awal.ppn);
    return t / (100 + t) * 100;
  }

  /** Porsi PPh terhadap harga jual: tarif dikalikan DPP, bukan harga jual. */
  function pphPorsi() {
    return ambil(elPph, awal.pph) * (100 - ppnPorsi()) / 100;
  }

  /**
   * HPP efektif. Bila PPN masukan bisa dikreditkan, modal sebenarnya adalah
   * HPP tanpa PPN - bagian PPN-nya kembali lewat pengkreditan.
   */
  function hppEfektif() {
    var hpp = ambil(elHpp, awal.hpp);
    if (!elKredit.checked) { return hpp; }
    var t = ambil(elPpn, awal.ppn);
    return hpp / (1 + t / 100);
  }

  /** Porsi dana bersih = sisa harga jual setelah seluruh pengurang termasuk pajak. */
  function netPct() {
    return 100 - ambil(elPot, awal.potPct) - ambil(elBi, awal.biPct)
               - awal.refPct - awal.lainPct - ppnPorsi() - pphPorsi();
  }

  /** Porsi dana platform saja (belum dipotong pajak). */
  function platformPct() {
    return 100 - ambil(elPot, awal.potPct) - ambil(elBi, awal.biPct)
               - awal.refPct - awal.lainPct;
  }

  /**
   * Porsi PENJUALAN BERSIH: harga jual dikurangi pajak, pengembalian, dan
   * potongan. Biaya platform tidak ikut - itu biaya menjual, bukan pengurang
   * penjualan. Inilah penyebut seluruh persentase marjin.
   */
  function jualPct() {
    return 100 - ppnPorsi() - pphPorsi() - awal.refPct - ambil(elPot, awal.potPct);
  }

  function render(harga) {
    var potPct = ambil(elPot, awal.potPct);
    var biPct  = ambil(elBi, awal.biPct);
    var hpp    = hppEfektif();
    var net    = netPct();
    var bersih = harga * net / 100;
    var jual   = harga * jualPct() / 100;
    var laba   = bersih - hpp;
    var marjin = jual > 0 ? laba / jual * 100 : null;

    var pPpn = ppnPorsi(), pPph = pphPorsi(), pPlat = platformPct();
    set('oJual', rp(jual), true);
    set('pJual', pc(jualPct(), 2), true);
    set('oPpn', rp(-harga * pPpn / 100));
    set('pPpn', pc(pPpn, 2));
    set('oDpp', rp(harga * (100 - pPpn) / 100));
    set('pDpp', pc(100 - pPpn, 2));
    set('oPph', rp(-harga * pPph / 100));
    set('pPph', pc(pPph, 2));
    set('oPlatform', rp(harga * pPlat / 100));
    set('pPlatform', pc(pPlat, 2));
    el('oHppKet').textContent = elKredit.checked ? '(tanpa PPN masukan)' : '';

    set('oHarga', rp(harga), true);
    set('oRefund', rp(-harga * awal.refPct / 100));
    set('pRefund', pc(awal.refPct, 2));
    set('oPotongan', rp(-harga * potPct / 100));
    set('pPotongan', pc(potPct, 2));
    set('oBiaya', rp(-harga * biPct / 100));
    set('pBiaya', pc(biPct, 2));
    set('oLain', rp(-harga * awal.lainPct / 100));
    set('oBersih', rp(bersih), true);
    set('pBersih', pc(net, 2), true);
    set('oHpp', rp(-hpp));
    set('oHppPct', harga > 0 ? pc(-hpp / harga * 100, 2) : '-');
    set('oLaba', rp(laba), true);
    set('oMarjin', marjin === null ? '-' : pc(marjin), true);

    el('oLaba').className = 'num ' + (laba < 0 ? 'neg' : 'pos');
    el('oMarjin').className = 'num ' + (marjin === null ? '' : (marjin < 0 ? 'neg' : 'pos'));

    // Baris rincian mengikuti total kelompoknya, jadi jumlahnya selalu sama
    // dengan baris ringkasan walau persentasenya diubah manual.
    document.querySelectorAll('[data-share]').forEach(function (td) {
      var totalGrup = -harga * (td.getAttribute('data-grup') === 'potongan' ? potPct : biPct) / 100;
      td.textContent = rp(totalGrup * parseFloat(td.getAttribute('data-share')));
    });

    var pesan;
    if (net <= 0) {
      pesan = '<b class="neg">Potongan dan biaya melebihi 100%.</b> Periksa lagi persentasenya.';
    } else if (hpp <= 0) {
      pesan = '<b class="neg">HPP belum diisi.</b> Isi HPP per unit di atas supaya marjinnya berarti.';
    } else if (marjin === null) {
      pesan = 'Harga jual belum diisi.';
    } else if (marjin < 0) {
      pesan = '<b class="neg">Jual rugi.</b> Dengan harga ini HPP masih lebih besar dari dana yang diterima.';
    } else if (marjin < marjinWajarMin) {
      pesan = 'Marjin <b>' + pc(marjin) + '</b> masih di bawah rentang wajar ('
            + marjinWajarMin + '&ndash;' + marjinWajarMax + '%).';
    } else if (marjin > marjinWajarMax) {
      pesan = 'Marjin <b>' + pc(marjin) + '</b> di atas rentang wajar ('
            + marjinWajarMin + '&ndash;' + marjinWajarMax + '%) &mdash; enak, tapi pastikan HPP-nya sudah lengkap.';
    } else {
      pesan = 'Marjin <b>' + pc(marjin) + '</b> berada di rentang wajar ('
            + marjinWajarMin + '&ndash;' + marjinWajarMax + '%).';
    }
    // Marjin sebelum pajak: penyebutnya penjualan bersih tanpa lapisan pajak,
    // supaya sebanding dengan angka di uji kewajaran HPP.
    var bersihPlat = harga * pPlat / 100;
    var jualPra    = harga * (100 - awal.refPct - potPct) / 100;
    if (jualPra > 0 && hpp > 0) {
      var mSebelum = (bersihPlat - hpp) / jualPra * 100;
      pesan += ' <span class="muted">Sebelum pajak marjinnya ' + pc(mSebelum)
             + ' &mdash; itu angka yang dipakai uji kewajaran HPP.</span>';
    }
    el('simCatatan').innerHTML = pesan;

    // Beri tahu kalau angkanya sudah tidak lagi mengikuti histori.
    var ubah = [];
    if (Math.abs(potPct - awal.potPct) > 0.005) { ubah.push('potongan'); }
    if (Math.abs(biPct - awal.biPct) > 0.005)   { ubah.push('biaya platform'); }
    if (Math.abs(ambil(elHpp, awal.hpp) - awal.hpp) > 0.5) { ubah.push('HPP'); }
    if (Math.abs(ambil(elPpn, awal.ppn) - awal.ppn) > 0.005) { ubah.push('PPN'); }
    if (Math.abs(ambil(elPph, awal.pph) - awal.pph) > 0.005) { ubah.push('PPh'); }
    if (elKredit.checked !== awal.kreditPpn) { ubah.push('kredit PPN masukan'); }
    el('simUbah').innerHTML = ubah.length
      ? '<b>Diubah manual:</b> ' + ubah.join(', ') + ' &mdash; tidak lagi mengikuti histori terakhir.'
      : '';
  }

  function dariHarga() {
    var harga = ambil(elHarga, awal.harga);
    if (harga <= 0) { return; }
    var bersih = harga * netPct() / 100;
    var jual   = harga * jualPct() / 100;
    var hpp    = hppEfektif();
    elMarjin.value = (jual > 0 ? (bersih - hpp) / jual * 100 : 0).toFixed(1);
    render(harga);
  }

  function dariMarjin() {
    var m = parseFloat(elMarjin.value);
    var net = netPct(), jual = jualPct();
    var hpp = hppEfektif();
    if (!isFinite(m) || jual <= 0 || hpp <= 0) { return; }
    // laba = harga*net/100 - hpp, dan marjin = laba / (harga*jual/100).
    // Diselesaikan untuk harga:  harga = hpp / (net/100 - m/100 * jual/100)
    var penyebut = net / 100 - (m / 100) * (jual / 100);
    if (penyebut <= 0) { return; }
    var harga = hpp / penyebut;
    if (!isFinite(harga) || harga <= 0) { return; }
    elHarga.value = Math.ceil(harga / 100) * 100;   // dibulatkan ke atas per Rp 100
    render(parseFloat(elHarga.value));
  }

  function reset() {
    elHarga.value = awal.harga;
    elHpp.value   = awal.hpp;
    elPot.value   = awal.potPct.toFixed(2);
    elBi.value    = awal.biPct.toFixed(2);
    elPpn.value   = awal.ppn;
    elPph.value   = awal.pph;
    elKredit.checked = awal.kreditPpn;
    dariHarga();
  }

  elHarga.addEventListener('input', dariHarga);
  elMarjin.addEventListener('input', dariMarjin);
  [elPot, elBi, elHpp, elPpn, elPph].forEach(function (e) { e.addEventListener('input', dariHarga); });
  elKredit.addEventListener('change', dariHarga);
  el('simReset').addEventListener('click', reset);
  reset();
})();
</script>

<script>
// Buka-tutup baris rincian. Baris yang tertutup memakai [hidden] sehingga
// otomatis tidak ikut tercetak - hasil cetak selalu sama dengan tampilan.
document.querySelectorAll('[data-toggle]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var kelas = btn.getAttribute('data-toggle');
    var buka  = btn.getAttribute('aria-expanded') !== 'true';
    document.querySelectorAll('tr.rinci.' + kelas).forEach(function (tr) {
      tr.hidden = !buka;
    });
    btn.setAttribute('aria-expanded', buka ? 'true' : 'false');
    btn.textContent = buka ? 'Tutup rincian' : 'Lihat rincian';
  });
});
</script>

<?php render_foot(); ?>
