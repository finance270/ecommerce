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

// Rantai nilai per unit, memakai urutan baku yang sama dengan Laba & Biaya.
$c = Reports::rantaiLaba([
    'kotor'    => $hargaUnit,
    'refund'   => $perUnit((float) $dasar['refund']),
    'potongan' => $perUnit((float) $dasar['potongan']),
    'biaya'    => $perUnit((float) $dasar['biaya']),
    'lain'     => $perUnit((float) $dasar['lain']),
    'hpp'      => $hppUnit,
], $ppnPersen, $pphPersen);
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
        <th>Komponen</th><th class="num">Per unit (Rp)</th><th class="num">Persentase</th>
        <th>Catatan / acuan</th><th style="width:120px"></th>
      </tr></thead>
      <tbody>
        <tr>
          <td><b>Harga jual terdaftar</b></td>
          <td class="num"><b><?= rp($c['harga']) ?></b></td>
          <td class="num muted">100,00%</td>
          <td class="muted" style="font-size:11.5px">% dari harga jual</td>
          <td></td>
        </tr>

        <?php if ($c['refund'] > 0): ?>
        <tr>
          <td>Pengembalian dana (refund)</td>
          <td class="num neg"><?= rp(-$c['refund']) ?></td>
          <td class="num neg"><?= num($c['refund'] / $c['harga'] * 100, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">% dari harga jual</td>
          <td></td>
        </tr>
        <?php endif; ?>

        <?php
        /** Baris ringkas + baris rincian yang bisa dibuka-tutup. */
        $blok = static function (string $id, string $judul, float $total, float $harga, array $items): void {
            if ($total <= 0 && $items === []) {
                return;
            } ?>
          <tr>
            <td><?= e($judul) ?></td>
            <td class="num neg"><?= rp(-$total) ?></td>
            <td class="num neg"><?= num($total / $harga * 100, 2) ?>%</td>
            <td class="muted" style="font-size:11.5px">% dari harga jual</td>
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
              <td class="num muted"><?= num(abs($it['nilai']) / $harga * 100, 2) ?>%</td>
              <td class="muted" style="font-size:11.5px"><?= e(Profiles::LABELS[$it['kategori']] ?? $it['kategori']) ?></td>
              <td></td>
            </tr>
          <?php endforeach;
        };

        $rinciPot = array_map(static fn(array $x): array =>
            ['label' => $x['label'], 'kategori' => $x['kategori'], 'nilai' => $x['nilai'] / $qty], $rinci['potongan']);
        $rinciBi = array_map(static fn(array $x): array =>
            ['label' => $x['label'], 'kategori' => $x['kategori'], 'nilai' => $x['nilai'] / $qty], $rinci['biaya']);

        $blok('rPotongan', 'Potongan & diskon ditanggung penjual', $c['potongan'], $c['harga'], $rinciPot);
        ?>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Harga setelah dikurang diskon</b></td>
          <td class="num"><b><?= rp($c['setelah_diskon']) ?></b></td>
          <td class="num"><b><?= num($c['setelah_diskon'] / $c['harga'] * 100, 2) ?>%</b></td>
          <td class="muted" style="font-size:11.5px">harga jual &minus; diskon</td>
          <td></td>
        </tr>

        <?php $blok('rBiaya', 'Biaya platform', $c['biaya'], $c['harga'], $rinciBi); ?>

        <?php if (abs($c['lain']) >= 1): ?>
        <tr>
          <td>Penyesuaian &amp; selisih platform</td>
          <td class="num <?= $c['lain'] > 0 ? 'neg' : 'pos' ?>"><?= rp(-$c['lain']) ?></td>
          <td class="num muted"><?= num(abs($c['lain']) / $c['harga'] * 100, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">% dari harga jual</td>
          <td></td>
        </tr>
        <?php endif; ?>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Dana diterima bersih</b></td>
          <td class="num"><b><?= rp($c['dana_diterima']) ?></b></td>
          <td class="num"><b><?= num($c['dana_diterima'] / $c['harga'] * 100, 2) ?>%</b></td>
          <td class="muted" style="font-size:11.5px">harga setelah diskon &minus; biaya platform</td>
          <td></td>
        </tr>

        <tr>
          <td>PPN <?= num($c['ppn_persen'], 0) ?>%</td>
          <td class="num neg"><?= rp(-$c['ppn']) ?></td>
          <td class="num neg"><?= num($c['ppn'] / $c['harga'] * 100, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">
            terkandung di dalam harga &mdash; dikeluarkan dengan
            <?= num($c['ppn_persen'], 0) ?>/<?= num(100 + $c['ppn_persen'], 0) ?>
          </td>
          <td></td>
        </tr>
        <?php if ($c['pph_persen'] > 0): ?>
        <tr>
          <td>Peredaran bruto tanpa PPN (DPP)</td>
          <td class="num"><?= rp($c['dpp']) ?></td>
          <td class="num"><?= num($c['dpp'] / $c['harga'] * 100, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">
            harga <b>sebelum diskon</b> &minus; PPN
          </td>
          <td></td>
        </tr>
        <tr>
          <td>Pajak e-commerce <?= num($c['pph_persen'], 1) ?>%</td>
          <td class="num neg"><?= rp(-$c['pph']) ?></td>
          <td class="num neg"><?= num($c['pph'] / $c['harga'] * 100, 2) ?>%</td>
          <td class="muted" style="font-size:11.5px">
            <?= num($c['pph_persen'], 1) ?>% dari DPP &mdash; diskon tidak mengurangi dasarnya
          </td>
          <td></td>
        </tr>
        <?php endif; ?>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Penjualan bersih</b></td>
          <td class="num"><b><?= rp($c['penjualan_bersih']) ?></b></td>
          <td class="num"><b><?= num($c['penjualan_bersih'] / $c['harga'] * 100, 2) ?>%</b></td>
          <td class="muted" style="font-size:11.5px">dana diterima &minus; PPN &minus; pajak e-commerce</td>
          <td></td>
        </tr>

        <tr>
          <td>HPP per unit</td>
          <td class="num neg">
            <?= $c['hpp'] > 0 ? rp(-$c['hpp']) : '<span class="badge warn">belum ada</span>' ?>
          </td>
          <td class="num neg">
            <?= $c['hpp'] > 0 && $c['penjualan_bersih'] > 0
                ? num($c['hpp'] / $c['penjualan_bersih'] * 100, 2) . '%' : '-' ?>
          </td>
          <td class="muted" style="font-size:11.5px">
            % dari penjualan bersih<?= $hppDb !== null ? ' &middot; ' . e($hppDb['period_ym']) : '' ?>
          </td>
          <td></td>
        </tr>

        <tr style="background:rgba(0,0,0,.02)">
          <td><b>Laba bersih sekarang</b></td>
          <td class="num <?= $c['laba'] < 0 ? 'neg' : 'pos' ?>"><b><?= rp($c['laba']) ?></b></td>
          <td class="num <?= ($c['marjin'] ?? 0) < 0 ? 'neg' : 'pos' ?>">
            <b><?= $c['marjin'] === null || $c['hpp'] <= 0 ? '-' : num($c['marjin'], 2) . '%' ?></b>
          </td>
          <td class="muted" style="font-size:11.5px">% dari penjualan bersih</td>
          <td></td>
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
        <th>Komponen</th><th class="num">Per unit (Rp)</th><th class="num">Persentase</th>
        <th>Catatan / acuan</th>
      </tr></thead>
      <tbody>
        <tr><td><b>Harga jual terdaftar</b></td><td class="num" id="oHarga"><b>-</b></td>
            <td class="num muted">100,00%</td><td class="muted" style="font-size:11.5px">% dari harga jual</td></tr>
        <?php if ($c['refund'] > 0): ?>
          <tr><td>Pengembalian dana</td><td class="num neg" id="oRefund">-</td>
              <td class="num neg" id="pRefund">-</td>
              <td class="muted" style="font-size:11.5px">% dari harga jual</td></tr>
        <?php endif; ?>
        <tr><td>Potongan &amp; diskon ditanggung penjual</td><td class="num neg" id="oPotongan">-</td>
            <td class="num neg" id="pPotongan">-</td>
            <td class="muted" style="font-size:11.5px">% dari harga jual</td></tr>
        <?php foreach ($rinci['potongan'] as $it): ?>
          <tr class="rinci rPotongan" hidden>
            <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
            <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"
                data-grup="potongan"
                data-share="<?= e((string) ($it['nilai'] / -(float) $dasar['potongan'])) ?>">-</td>
            <td class="num muted"><?= num(abs($it['nilai']) / $hargaTot * 100, 2) ?>%</td>
            <td class="muted" style="font-size:11.5px"><?= e(Profiles::LABELS[$it['kategori']] ?? $it['kategori']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Harga setelah dikurang diskon</b></td><td class="num" id="oSetelah"><b>-</b></td>
            <td class="num" id="pSetelah"><b>-</b></td>
            <td class="muted" style="font-size:11.5px">harga jual &minus; diskon</td></tr>
        <tr><td>Biaya platform</td><td class="num neg" id="oBiaya">-</td>
            <td class="num neg" id="pBiaya">-</td>
            <td class="muted" style="font-size:11.5px">% dari harga jual</td></tr>
        <?php foreach ($rinci['biaya'] as $it): ?>
          <tr class="rinci rBiaya" hidden>
            <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
            <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"
                data-grup="biaya"
                data-share="<?= e((string) ($it['nilai'] / -(float) $dasar['biaya'])) ?>">-</td>
            <td class="num muted"><?= num(abs($it['nilai']) / $hargaTot * 100, 2) ?>%</td>
            <td class="muted" style="font-size:11.5px"><?= e(Profiles::LABELS[$it['kategori']] ?? $it['kategori']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (abs($c['lain']) >= 1): ?>
          <tr><td>Penyesuaian &amp; selisih</td><td class="num" id="oLain">-</td>
              <td class="num muted"><?= num(abs($c['lain']) / $c['harga'] * 100, 2) ?>%</td>
              <td class="muted" style="font-size:11.5px">% dari harga jual</td></tr>
        <?php endif; ?>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Dana diterima bersih</b></td><td class="num" id="oDana"><b>-</b></td>
            <td class="num" id="pDana"><b>-</b></td>
            <td class="muted" style="font-size:11.5px">harga setelah diskon &minus; biaya platform</td></tr>
        <tr><td>PPN</td><td class="num neg" id="oPpn">-</td><td class="num neg" id="pPpn">-</td>
            <td class="muted" style="font-size:11.5px" id="kPpn">&mdash;</td></tr>
        <tr><td>Peredaran bruto tanpa PPN (DPP)</td><td class="num" id="oDpp">-</td>
            <td class="num" id="pDpp">-</td>
            <td class="muted" style="font-size:11.5px">harga <b>sebelum diskon</b> &minus; PPN</td></tr>
        <tr><td>Pajak e-commerce</td><td class="num neg" id="oPph">-</td><td class="num neg" id="pPph">-</td>
            <td class="muted" style="font-size:11.5px" id="kPph">&mdash;</td></tr>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Penjualan bersih</b></td><td class="num" id="oJual"><b>-</b></td>
            <td class="num" id="pJual"><b>-</b></td>
            <td class="muted" style="font-size:11.5px">dana diterima &minus; PPN &minus; pajak e-commerce</td></tr>
        <tr><td>HPP per unit <span class="muted" id="oHppKet" style="font-size:11.5px"></span></td>
            <td class="num neg" id="oHpp">-</td>
            <td class="num neg" id="oHppPct">-</td>
            <td class="muted" style="font-size:11.5px">% dari penjualan bersih</td></tr>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Laba bersih per unit</b></td><td class="num" id="oLaba"><b>-</b></td>
            <td class="num" id="oMarjin"><b>-</b></td>
            <td class="muted" style="font-size:11.5px">% dari penjualan bersih</td></tr>
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
    harga:   <?= json_encode(round($c['harga'])) ?>,
    hpp:     <?= json_encode(round($c['hpp'])) ?>,
    refPct:  <?= json_encode(round($c['refund']   / $c['harga'] * 100, 6)) ?>,
    potPct:  <?= json_encode(round($c['potongan'] / $c['harga'] * 100, 6)) ?>,
    biPct:   <?= json_encode(round($c['biaya']    / $c['harga'] * 100, 6)) ?>,
    lainPct: <?= json_encode(round($c['lain']     / $c['harga'] * 100, 6)) ?>,
    ppn:     <?= json_encode($c['ppn_persen']) ?>,
    pph:     <?= json_encode($c['pph_persen']) ?>,
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
  function pc(n, d) { return n.toFixed(d === undefined ? 2 : d).replace('.', ',') + '%'; }
  function set(id, txt, tebal) {
    var e = el(id);
    if (e) { e.innerHTML = tebal ? '<b>' + txt + '</b>' : txt; }
  }
  function ambil(input, fallback) {
    var v = parseFloat(input.value);
    return isFinite(v) ? v : fallback;
  }

  /**
   * Rantai nilai, urutannya sama persis dengan Reports::rantaiLaba() di sisi
   * PHP: diskon dulu, lalu biaya platform, baru pajak.
   *
   * Dua pajaknya berbeda dasar, dan itu disengaja:
   *   PPN  - dari nilai yang benar-benar ditagihkan (setelah diskon), dan
   *          dikeluarkan DARI DALAM harga karena harga sudah termasuk PPN.
   *   PPh  - dari peredaran bruto SEBELUM diskon tanpa PPN (PMK 37/2025),
   *          sehingga diskon tidak mengurangi dasarnya.
   */
  function hitung(harga) {
    var potPct = ambil(elPot, awal.potPct);
    var biPct  = ambil(elBi, awal.biPct);
    var ppn    = ambil(elPpn, awal.ppn);
    var pph    = ambil(elPph, awal.pph);

    var refund   = harga * awal.refPct / 100;
    var potongan = harga * potPct / 100;
    var setelah  = harga - refund - potongan;
    var biaya    = harga * biPct / 100;
    var lain     = harga * awal.lainPct / 100;
    var dana     = setelah - biaya - lain;
    var nPpn     = setelah * ppn / (100 + ppn);
    var dpp      = (harga - refund) * 100 / (100 + ppn);
    var nPph     = dpp * pph / 100;
    var jual     = dana - nPpn - nPph;

    // HPP efektif: bila PPN masukan bisa dikreditkan, modal sebenarnya
    // adalah HPP tanpa PPN karena bagian itu kembali lewat pengkreditan.
    var hpp = ambil(elHpp, awal.hpp);
    if (elKredit.checked && ppn > 0) { hpp = hpp / (1 + ppn / 100); }

    return {
      harga: harga, refund: refund, potongan: potongan, setelah: setelah,
      biaya: biaya, lain: lain, dana: dana, ppn: nPpn, dpp: dpp, pph: nPph,
      jual: jual, hpp: hpp, laba: jual - hpp,
      marjin: jual > 0 ? (jual - hpp) / jual * 100 : null,
      ppnPersen: ppn, pphPersen: pph, potPct: potPct, biPct: biPct
    };
  }

  function render(harga) {
    var v = hitung(harga);
    var p = function (x) { return v.harga > 0 ? pc(x / v.harga * 100) : '-'; };

    set('oHarga', rp(v.harga), true);
    set('oRefund', rp(-v.refund));      set('pRefund', p(v.refund));
    set('oPotongan', rp(-v.potongan));  set('pPotongan', p(v.potongan));
    set('oSetelah', rp(v.setelah), true); set('pSetelah', p(v.setelah), true);
    set('oBiaya', rp(-v.biaya));        set('pBiaya', p(v.biaya));
    set('oLain', rp(-v.lain));
    set('oDana', rp(v.dana), true);     set('pDana', p(v.dana), true);
    set('oPpn', rp(-v.ppn));            set('pPpn', p(v.ppn));
    set('oDpp', rp(v.dpp));             set('pDpp', p(v.dpp));
    set('oPph', rp(-v.pph));            set('pPph', p(v.pph));
    set('kPpn', pc(v.ppnPersen, 0) + ' dari harga setelah dikurang diskon');
    set('kPph', pc(v.pphPersen, 1) + ' dari harga setelah dikurang diskon');
    set('oJual', rp(v.jual), true);     set('pJual', p(v.jual), true);
    set('oHpp', rp(-v.hpp));
    set('oHppPct', v.jual > 0 && v.hpp > 0 ? pc(v.hpp / v.jual * 100) : '-');
    set('oLaba', rp(v.laba), true);
    set('oMarjin', v.marjin === null || v.hpp <= 0 ? '-' : pc(v.marjin), true);

    el('oLaba').className = 'num ' + (v.laba < 0 ? 'neg' : 'pos');
    el('oMarjin').className = 'num ' + (v.marjin === null ? '' : (v.marjin < 0 ? 'neg' : 'pos'));
    el('oHppKet').textContent = elKredit.checked ? '(tanpa PPN masukan)' : '';

    // Baris rincian mengikuti total kelompoknya, jadi jumlahnya selalu sama
    // dengan baris ringkasan walau persentasenya diubah manual.
    document.querySelectorAll('[data-share]').forEach(function (td) {
      var total = -(td.getAttribute('data-grup') === 'potongan' ? v.potongan : v.biaya);
      td.textContent = rp(total * parseFloat(td.getAttribute('data-share')));
    });

    var pesan;
    if (v.jual <= 0) {
      pesan = '<b class="neg">Penjualan bersih nol atau negatif.</b> Periksa lagi persentasenya.';
    } else if (v.hpp <= 0) {
      pesan = '<b class="neg">HPP belum diisi.</b> Isi HPP per unit di atas supaya marjinnya berarti.';
    } else if (v.marjin < 0) {
      pesan = '<b class="neg">Jual rugi.</b> HPP masih lebih besar dari penjualan bersih.';
    } else if (v.marjin < marjinWajarMin) {
      pesan = 'Marjin <b>' + pc(v.marjin, 1) + '</b> masih di bawah rentang wajar ('
            + marjinWajarMin + '&ndash;' + marjinWajarMax + '%).';
    } else if (v.marjin > marjinWajarMax) {
      pesan = 'Marjin <b>' + pc(v.marjin, 1) + '</b> di atas rentang wajar ('
            + marjinWajarMin + '&ndash;' + marjinWajarMax + '%) &mdash; enak, tapi pastikan HPP-nya sudah lengkap.';
    } else {
      pesan = 'Marjin <b>' + pc(v.marjin, 1) + '</b> berada di rentang wajar ('
            + marjinWajarMin + '&ndash;' + marjinWajarMax + '%).';
    }
    el('simCatatan').innerHTML = pesan;

    var ubah = [];
    if (Math.abs(v.potPct - awal.potPct) > 0.005) { ubah.push('potongan'); }
    if (Math.abs(v.biPct - awal.biPct) > 0.005)   { ubah.push('biaya platform'); }
    if (Math.abs(ambil(elHpp, awal.hpp) - awal.hpp) > 0.5) { ubah.push('HPP'); }
    if (Math.abs(v.ppnPersen - awal.ppn) > 0.005) { ubah.push('PPN'); }
    if (Math.abs(v.pphPersen - awal.pph) > 0.005) { ubah.push('pajak e-commerce'); }
    if (elKredit.checked !== awal.kreditPpn) { ubah.push('kredit PPN masukan'); }
    el('simUbah').innerHTML = ubah.length
      ? '<b>Diubah manual:</b> ' + ubah.join(', ') + ' &mdash; tidak lagi mengikuti histori terakhir.'
      : '';
  }

  function dariHarga() {
    var harga = ambil(elHarga, awal.harga);
    if (harga <= 0) { return; }
    var v = hitung(harga);
    elMarjin.value = (v.jual > 0 ? v.marjin : 0).toFixed(1);
    render(harga);
  }

  function dariMarjin() {
    var m = parseFloat(elMarjin.value);
    if (!isFinite(m) || m >= 100) { return; }
    // Seluruh komponen sebanding dengan harga, jadi penjualan bersih dan HPP
    // cukup dihitung sekali pada harga acuan lalu diskalakan.
    var acuan = hitung(awal.harga > 0 ? awal.harga : 100000);
    if (acuan.harga <= 0 || acuan.jual <= 0 || acuan.hpp <= 0) { return; }
    var jualRasio = acuan.jual / acuan.harga;          // penjualan bersih per rupiah harga
    var penyebut  = jualRasio * (1 - m / 100);
    if (penyebut <= 0) { return; }
    var harga = acuan.hpp / penyebut;
    if (!isFinite(harga) || harga <= 0) { return; }
    elHarga.value = Math.ceil(harga / 100) * 100;      // dibulatkan ke atas per Rp 100
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
