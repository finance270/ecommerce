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
 * Dasarnya adalah PESANAN TERAKHIR yang sudah selesai dan lengkap biayanya,
 * bukan rerata beberapa bulan: tarif komisi dan biaya layanan berubah dari
 * waktu ke waktu, jadi pesanan paling akhir yang mencerminkan tarif berlaku.
 * HPP diambil dari bulan terakhir yang ada isinya. Keduanya hanya nilai awal -
 * seluruh angka bisa diubah manual, dan tombol Reset mengembalikannya ke
 * histori terakhir.
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

$ref   = Reports::latestSettledOrder($key, $platform);
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
$bersihUnit = $perUnit((float) $dasar['bersih']);
?>

<div class="card">
  <h2>Dasar perhitungan</h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Diambil dari <b>pesanan terakhir yang selesai dan sudah lengkap biayanya</b>:
    <?= platformBadge((string) $ref['platform']) ?>
    <code class="k"><?= e($ref['order_id']) ?></code>,
    dana dilepas <b><?= shortDate($ref['settlement_date']) ?></b>
    (<?= num($qty) ?> unit produk ini di dalamnya).
    Tarif komisi berubah dari waktu ke waktu, jadi pesanan terakhir lebih mewakili
    tarif yang berlaku sekarang daripada rerata beberapa bulan.
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
          <td class="muted" style="font-size:11.5px">harga sebelum diskon</td>
        </tr>
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
          <td><b>Dana diterima bersih</b></td>
          <td class="num"><b><?= rp($bersihUnit) ?></b></td>
          <td class="num"><b><?= num((float) $dasar['bersih_pct'], 2) ?>%</b></td>
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
          <td class="num <?= ($bersihUnit - $hppUnit) < 0 ? 'neg' : 'pos' ?>">
            <b><?= rp($bersihUnit - $hppUnit) ?></b>
          </td>
          <td class="num"><b>marjin
            <?= $bersihUnit > 0 && $hppUnit > 0
                ? num(($bersihUnit - $hppUnit) / $bersihUnit * 100, 1) . '%'
                : '-' ?></b></td>
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
  <div class="grid3 no-print" style="margin-bottom:16px">
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

  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Komponen</th><th class="num">Per unit</th><th class="num">% dari harga jual</th>
      </tr></thead>
      <tbody>
        <tr><td><b>Harga jual</b></td><td class="num" id="oHarga"><b>-</b></td><td class="num muted">100,00%</td></tr>
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
            <td><b>Dana diterima bersih</b></td><td class="num" id="oBersih"><b>-</b></td>
            <td class="num" id="pBersih"><b>-</b></td></tr>
        <tr><td>HPP per unit</td><td class="num neg" id="oHpp">-</td>
            <td class="num neg" id="oHppPct">-</td></tr>
        <tr style="background:rgba(0,0,0,.02)">
            <td><b>Laba bersih per unit</b></td><td class="num" id="oLaba"><b>-</b></td>
            <td class="num" id="oMarjin"><b>-</b></td></tr>
      </tbody>
    </table>
  </div>

  <div id="simCatatan" class="help" style="margin-top:12px"></div>
  <div id="simUbah" class="help" style="margin-top:6px"></div>

  <p class="help" style="margin-top:12px">
    Persentase pengembalian, potongan, dan biaya platform dianggap <b>tetap</b> terhadap harga jual.
    Kalau harga naik, komisi dan biaya ikut naik sebanding &mdash; itu sebabnya menaikkan harga
    tidak menaikkan marjin seluruhnya. Angka ini panduan, bukan janji: harga baru bisa mengubah
    jumlah penjualan.
  </p>
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
    lainPct: <?= json_encode(round((float) $dasar['lain_pct'], 6)) ?>
  };
  var marjinWajarMin = <?= json_encode(Reports::MARJIN_MIN) ?>;
  var marjinWajarMax = <?= json_encode(Reports::MARJIN_MAX) ?>;

  function el(id) { return document.getElementById(id); }
  var elHarga = el('simHarga'), elMarjin = el('simMarjin');
  var elPot = el('simPotongan'), elBi = el('simBiaya'), elHpp = el('simHpp');

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

  /** Porsi dana bersih = sisa harga jual setelah seluruh pengurang. */
  function netPct() {
    return 100 - ambil(elPot, awal.potPct) - ambil(elBi, awal.biPct)
               - awal.refPct - awal.lainPct;
  }

  function render(harga) {
    var potPct = ambil(elPot, awal.potPct);
    var biPct  = ambil(elBi, awal.biPct);
    var hpp    = ambil(elHpp, awal.hpp);
    var net    = netPct();
    var bersih = harga * net / 100;
    var laba   = bersih - hpp;
    var marjin = bersih > 0 ? laba / bersih * 100 : null;

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
    el('simCatatan').innerHTML = pesan;

    // Beri tahu kalau angkanya sudah tidak lagi mengikuti histori.
    var ubah = [];
    if (Math.abs(potPct - awal.potPct) > 0.005) { ubah.push('potongan'); }
    if (Math.abs(biPct - awal.biPct) > 0.005)   { ubah.push('biaya platform'); }
    if (Math.abs(hpp - awal.hpp) > 0.5)         { ubah.push('HPP'); }
    el('simUbah').innerHTML = ubah.length
      ? '<b>Diubah manual:</b> ' + ubah.join(', ') + ' &mdash; tidak lagi mengikuti histori terakhir.'
      : '';
  }

  function dariHarga() {
    var harga = ambil(elHarga, awal.harga);
    if (harga <= 0) { return; }
    var net = netPct();
    var bersih = harga * net / 100;
    var hpp = ambil(elHpp, awal.hpp);
    elMarjin.value = (bersih > 0 ? (bersih - hpp) / bersih * 100 : 0).toFixed(1);
    render(harga);
  }

  function dariMarjin() {
    var m = parseFloat(elMarjin.value);
    var net = netPct();
    var hpp = ambil(elHpp, awal.hpp);
    if (!isFinite(m) || m >= 100 || net <= 0 || hpp <= 0) { return; }
    // bersih = hpp / (1 - m/100), lalu harga = bersih / (net/100)
    var harga = (hpp / (1 - m / 100)) * 100 / net;
    if (!isFinite(harga) || harga <= 0) { return; }
    elHarga.value = Math.ceil(harga / 100) * 100;   // dibulatkan ke atas per Rp 100
    render(parseFloat(elHarga.value));
  }

  function reset() {
    elHarga.value = awal.harga;
    elHpp.value   = awal.hpp;
    elPot.value   = awal.potPct.toFixed(2);
    elBi.value    = awal.biPct.toFixed(2);
    dariHarga();
  }

  elHarga.addEventListener('input', dariHarga);
  elMarjin.addEventListener('input', dariMarjin);
  [elPot, elBi, elHpp].forEach(function (e) { e.addEventListener('input', dariHarga); });
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
