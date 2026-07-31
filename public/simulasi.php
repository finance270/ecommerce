<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Simulasi penentuan harga jual satu produk.
 *
 * Halaman berdiri sendiri - dibuka di tab baru dari uji kewajaran HPP - supaya
 * bisa dipakai fokus dan dicetak apa adanya jadi PDF. Sengaja TIDAK memuat
 * ringkasan produk yang berat: yang dibutuhkan di sini hanya pola biaya dan
 * HPP produk tersebut.
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

<?php
    $nBulan = (int) (q('n') ?? 3);
    $nBulan = max(1, min(12, $nBulan));
    $dasar  = Reports::pricingBasis($key, $platform, $nBulan);
    $rinci  = Reports::pricingBreakdown($key, $platform, $nBulan);

    $hppUnit   = $dasar['hpp_unit'];
    $hargaUnit = $dasar['harga_unit'];
    $bersihPct = $dasar['bersih_pct'];
    $qty       = (int) $dasar['qty'];
    $siapSimulasi = $hppUnit !== null && $hppUnit > 0 && $bersihPct !== null && $bersihPct > 0;
    $perUnit = static fn(float $total): float => $qty > 0 ? $total / $qty : 0.0;
?>
  <div class="card">
    <h2>
      Dasar perhitungan
      <span class="muted" style="font-weight:400;font-size:13px">
        &mdash; rerata <?= count($dasar['bulan_dipakai']) ?> bulan terakhir
      </span>
      <button class="btn ghost sm no-print" type="button" onclick="window.print()"
              style="float:right">Cetak / simpan PDF</button>
    </h2>

    <p class="help" style="margin-top:-4px;margin-bottom:12px">
      <?php if ($dasar['bulan_dipakai'] !== []): ?>
        Bulan yang dipakai: <b><?= e(implode(', ', $dasar['bulan_dipakai'])) ?></b>
        (<?= num($qty) ?> unit terjual<?= $platform !== null ? ' di ' . ($platform === 'shopee' ? 'Shopee' : 'Tokopedia') : ' di seluruh platform' ?>).
        Persentase dihitung dari nilai gabungan seluruh bulan itu, jadi bulan yang ramai
        berbobot lebih besar &mdash; lebih mewakili keadaan sebenarnya daripada rerata biasa.
      <?php else: ?>
        Belum ada data settlement untuk produk ini.
      <?php endif; ?>
    </p>

    <form method="get" class="filters" style="margin-bottom:14px">
      <input type="hidden" name="key" value="<?= e($key) ?>">
      <input type="hidden" name="view" value="simulasi">
      <div class="field">
        <label>Platform</label>
        <select name="platform" onchange="this.form.submit()">
          <option value="">Semua platform</option>
          <option value="tokopedia" <?= $platform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
          <option value="shopee"    <?= $platform === 'shopee'    ? 'selected' : '' ?>>Shopee</option>
        </select>
      </div>
      <div class="field">
        <label>Pakai berapa bulan terakhir</label>
        <select name="n" onchange="this.form.submit()">
          <?php foreach ([1, 2, 3, 6, 12] as $opt): ?>
            <option value="<?= $opt ?>" <?= $nBulan === $opt ? 'selected' : '' ?>><?= $opt ?> bulan</option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <?php if ($platform === null): ?>
      <p class="help" style="margin-bottom:12px">
        Sedang menggabung Tokopedia dan Shopee. Karena komisi kedua platform berbeda, pilih
        salah satu platform di atas kalau ingin harga yang benar-benar pas untuk platform itu.
      </p>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Komponen</th><th class="num">Per unit</th><th class="num">% dari harga jual</th><th style="width:120px"></th>
        </tr></thead>
        <tbody>
          <tr>
            <td><b>Harga jual terdaftar</b></td>
            <td class="num"><b><?= $hargaUnit === null ? '-' : rp($hargaUnit) ?></b></td>
            <td class="num muted">100,00%</td>
            <td class="muted" style="font-size:11.5px">harga sebelum diskon</td>
          </tr>

          <?php if ($dasar['refund'] > 0): ?>
          <tr>
            <td>Pengembalian dana (refund)</td>
            <td class="num neg"><?= rp(-$perUnit($dasar['refund'])) ?></td>
            <td class="num neg"><?= num($dasar['refund_pct'], 2) ?>%</td>
            <td class="muted" style="font-size:11.5px">barang kembali</td>
          </tr>
          <?php endif; ?>

          <?php
          /**
           * Baris ringkas + baris rincian yang bisa dibuka-tutup.
           * Persen selalu diukur terhadap NILAI TOTAL harga jual pada periode
           * ini ($hargaTotal), bukan terhadap harga per unit.
           */
          $hargaTotal = (float) $dasar['harga'];
          $blok = static function (string $id, string $judul, float $total, array $items)
                  use ($perUnit, $hargaTotal): void {
              if ($total <= 0 && $items === []) {
                  return;
              } ?>
            <tr>
              <td><?= e($judul) ?></td>
              <td class="num neg"><?= rp(-$perUnit($total)) ?></td>
              <td class="num neg"><?= $hargaTotal > 0 ? num($total / $hargaTotal * 100, 2) . '%' : '-' ?></td>
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
                <td class="num muted">
                  <?= $hargaTotal > 0 ? num(abs($it['nilai']) / $hargaTotal * 100, 2) . '%' : '-' ?>
                </td>
                <td class="muted" style="font-size:11.5px"><?= e(Profiles::LABELS[$it['kategori']] ?? $it['kategori']) ?></td>
              </tr>
            <?php endforeach;
          };
          ?>
          <?php $blok('rPotongan', 'Potongan & diskon ditanggung penjual', (float) $dasar['potongan'], $rinci['potongan']); ?>
          <?php $blok('rBiaya', 'Biaya platform', (float) $dasar['biaya'], $rinci['biaya']); ?>

          <?php if (abs((float) $dasar['lain']) >= 1): ?>
          <tr>
            <td>Penyesuaian &amp; selisih platform</td>
            <td class="num <?= $dasar['lain'] > 0 ? 'neg' : 'pos' ?>"><?= rp(-$perUnit((float) $dasar['lain'])) ?></td>
            <td class="num muted"><?= num(abs((float) $dasar['lain_pct']), 2) ?>%</td>
            <td class="muted" style="font-size:11.5px">kompensasi & koreksi</td>
          </tr>
          <?php endif; ?>

          <tr style="background:rgba(0,0,0,.02)">
            <td><b>Dana diterima bersih</b></td>
            <td class="num"><b><?= rp($perUnit((float) $dasar['bersih'])) ?></b></td>
            <td class="num"><b><?= $bersihPct === null ? '-' : num($bersihPct, 2) . '%' ?></b></td>
            <td></td>
          </tr>
          <tr>
            <td>HPP per unit</td>
            <td class="num neg"><?= $hppUnit === null ? '<span class="badge warn">belum ada</span>' : rp(-$hppUnit) ?></td>
            <td class="num neg"><?= $hppUnit !== null && $hargaUnit > 0 ? num($hppUnit / $hargaUnit * 100, 2) . '%' : '-' ?></td>
            <td></td>
          </tr>
          <tr style="background:rgba(0,0,0,.02)">
            <td><b>Laba bersih sekarang</b></td>
            <td class="num <?= ((float) $dasar['bersih'] - (float) $dasar['hpp']) < 0 ? 'neg' : 'pos' ?>">
              <b><?= rp($perUnit((float) $dasar['bersih'] - (float) $dasar['hpp'])) ?></b>
            </td>
            <td class="num"><b>marjin <?= $dasar['marjin'] === null ? '-' : num($dasar['marjin'], 1) . '%' ?></b></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </div>

    <?php if ($dasar['qty_tanpa_hpp'] > 0): ?>
      <p class="help" style="margin-top:10px">
        <?= num($dasar['qty_tanpa_hpp']) ?> dari <?= num($qty) ?> unit pada periode ini belum
        punya HPP. HPP per unit di atas dihitung <b>hanya dari unit yang sudah punya HPP</b>, supaya
        reratanya tidak tertarik turun dan simulasinya tidak terlalu optimistis.
      </p>
    <?php endif; ?>
  </div>

  <?php if (!$siapSimulasi): ?>
    <div class="alert warn">
      <b>Belum bisa disimulasikan.</b>
      <?php if ($hppUnit === null || $hppUnit <= 0): ?>
        HPP produk ini belum diisi, padahal HPP adalah dasar perhitungan harga jual.
        <?= tabLink('costs', 'costs.php', 'Isi HPP dulu &rarr;') ?>
      <?php else: ?>
        Dana diterima bersih pada periode ini nol atau negatif, sehingga porsi biaya tidak bisa dihitung.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>Simulasi</h2>
      <p class="help no-print" style="margin-top:-4px;margin-bottom:14px">
        Ubah <b>salah satu</b> kolom &mdash; yang lain ikut menyesuaikan. Isi harga jual untuk melihat
        marjin yang didapat, atau isi marjin yang diinginkan untuk melihat harga jual yang diperlukan.
      </p>

      <div class="grid2 no-print" style="margin-bottom:16px">
        <div class="field">
          <label for="simHarga">Harga jual per unit (Rp)</label>
          <input type="number" id="simHarga" step="100" min="0" style="width:100%"
                 value="<?= e((string) round((float) $hargaUnit)) ?>">
        </div>
        <div class="field">
          <label for="simMarjin">Marjin bersih setelah HPP (%)</label>
          <input type="number" id="simMarjin" step="0.1" max="99.9" style="width:100%"
                 value="<?= e((string) round((float) $dasar['marjin'], 1)) ?>">
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
                  <td class="num neg"><?= num($dasar['refund_pct'], 2) ?>%</td></tr>
            <?php endif; ?>
            <tr>
              <td>Potongan &amp; diskon</td><td class="num neg" id="oPotongan">-</td>
              <td class="num neg"><?= num($dasar['potongan_pct'], 2) ?>%</td>
            </tr>
            <?php foreach ($rinci['potongan'] as $i => $it): ?>
              <tr class="rinci rPotongan" hidden>
                <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
                <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"
                    data-sim-pct="<?= e((string) ($it['nilai'] / $dasar['harga'] * 100)) ?>">-</td>
                <td class="num muted"><?= num(abs($it['nilai']) / $dasar['harga'] * 100, 2) ?>%</td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td>Biaya platform</td><td class="num neg" id="oBiaya">-</td>
              <td class="num neg"><?= num($dasar['biaya_pct'], 2) ?>%</td>
            </tr>
            <?php foreach ($rinci['biaya'] as $it): ?>
              <tr class="rinci rBiaya" hidden>
                <td style="padding-left:26px" class="muted">&mdash; <?= e($it['label']) ?></td>
                <td class="num <?= $it['nilai'] < 0 ? 'neg' : 'pos' ?>"
                    data-sim-pct="<?= e((string) ($it['nilai'] / $dasar['harga'] * 100)) ?>">-</td>
                <td class="num muted"><?= num(abs($it['nilai']) / $dasar['harga'] * 100, 2) ?>%</td>
              </tr>
            <?php endforeach; ?>
            <?php if (abs((float) $dasar['lain']) >= 1): ?>
              <tr><td>Penyesuaian &amp; selisih</td><td class="num" id="oLain">-</td>
                  <td class="num muted"><?= num(abs((float) $dasar['lain_pct']), 2) ?>%</td></tr>
            <?php endif; ?>
            <tr style="background:rgba(0,0,0,.02)">
                <td><b>Dana diterima bersih</b></td><td class="num" id="oBersih"><b>-</b></td>
                <td class="num"><b><?= num($bersihPct, 2) ?>%</b></td></tr>
            <tr><td>HPP per unit</td><td class="num neg"><?= rp(-$hppUnit) ?></td>
                <td class="num neg" id="oHppPct">-</td></tr>
            <tr style="background:rgba(0,0,0,.02)">
                <td><b>Laba bersih per unit</b></td><td class="num" id="oLaba"><b>-</b></td>
                <td class="num" id="oMarjin"><b>-</b></td></tr>
          </tbody>
        </table>
      </div>

      <div id="simCatatan" class="help" style="margin-top:12px"></div>

      <p class="help" style="margin-top:12px">
        Persentase pengembalian, potongan, dan biaya platform dianggap <b>tetap</b> mengikuti pola
        <?= count($dasar['bulan_dipakai']) ?> bulan terakhir. Kalau harga naik, komisi dan biaya
        ikut naik sebanding &mdash; itu sebabnya menaikkan harga tidak menaikkan marjin
        seluruhnya. Angka ini panduan, bukan janji: harga baru bisa mengubah jumlah penjualan.
      </p>
    </div>

    <script>
    (function () {
      var hpp    = <?= json_encode(round((float) $hppUnit, 2)) ?>;
      var refPct = <?= json_encode(round((float) $dasar['refund_pct'], 6)) ?>;
      var potPct = <?= json_encode(round((float) $dasar['potongan_pct'], 6)) ?>;
      var biPct  = <?= json_encode(round((float) $dasar['biaya_pct'], 6)) ?>;
      var lainPct = <?= json_encode(round((float) $dasar['lain_pct'], 6)) ?>;
      var netPct = <?= json_encode(round((float) $bersihPct, 6)) ?>;
      var marjinWajarMin = <?= json_encode(Reports::MARJIN_MIN) ?>;
      var marjinWajarMax = <?= json_encode(Reports::MARJIN_MAX) ?>;

      var elHarga = document.getElementById('simHarga');
      var elMarjin = document.getElementById('simMarjin');
      function el(id) { return document.getElementById(id); }

      function rp(n) {
        return (n < 0 ? '-' : '') + 'Rp ' + Math.round(Math.abs(n)).toLocaleString('id-ID');
      }
      function pc(n) { return n.toFixed(1).replace('.', ',') + '%'; }
      function set(id, txt, tebal) {
        var e = el(id);
        if (e) { e.innerHTML = tebal ? '<b>' + txt + '</b>' : txt; }
      }

      function render(harga) {
        var bersih = harga * netPct / 100;
        var laba   = bersih - hpp;
        var marjin = bersih > 0 ? laba / bersih * 100 : null;

        set('oHarga', rp(harga), true);
        set('oRefund', rp(-harga * refPct / 100));
        set('oPotongan', rp(-harga * potPct / 100));
        set('oBiaya', rp(-harga * biPct / 100));
        set('oLain', rp(-harga * lainPct / 100));
        set('oBersih', rp(bersih), true);
        set('oHppPct', harga > 0 ? pc(-hpp / harga * 100) : '-');
        set('oLaba', rp(laba), true);
        set('oMarjin', marjin === null ? '-' : pc(marjin), true);

        var elLaba = el('oLaba');
        elLaba.className = 'num ' + (laba < 0 ? 'neg' : 'pos');
        el('oMarjin').className = 'num ' + (marjin === null ? '' : (marjin < 0 ? 'neg' : 'pos'));

        // Baris rincian ikut menyesuaikan harga baru.
        document.querySelectorAll('[data-sim-pct]').forEach(function (td) {
          td.textContent = rp(harga * parseFloat(td.getAttribute('data-sim-pct')) / 100);
        });

        var pesan;
        if (marjin === null) {
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
      }

      function dariHarga() {
        var harga = parseFloat(elHarga.value);
        if (!isFinite(harga) || harga <= 0) { return; }
        var bersih = harga * netPct / 100;
        elMarjin.value = (bersih > 0 ? (bersih - hpp) / bersih * 100 : 0).toFixed(1);
        render(harga);
      }

      function dariMarjin() {
        var m = parseFloat(elMarjin.value);
        if (!isFinite(m) || m >= 100) { return; }
        // bersih = hpp / (1 - m/100), lalu harga = bersih / (netPct/100)
        var harga = (hpp / (1 - m / 100)) * 100 / netPct;
        if (!isFinite(harga) || harga <= 0) { return; }
        elHarga.value = Math.ceil(harga / 100) * 100;   // dibulatkan ke atas per Rp 100
        render(parseFloat(elHarga.value));
      }

      elHarga.addEventListener('input', dariHarga);
      elMarjin.addEventListener('input', dariMarjin);
      dariHarga();
    })();
    </script>
  <?php endif; ?>

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
