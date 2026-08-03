<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Potongan halaman Laba & Biaya yang perhitungannya berat.
 *
 * Dipanggil terpisah oleh halaman utama supaya tabel ringkasan langsung
 * tampil, tidak ikut menunggu agregasi ratusan ribu baris.
 */

if (Auth::user() === null) {
    http_response_code(403);
    echo '<p class="muted">Sesi berakhir. <a href="login.php">Masuk lagi</a>.</p>';
    exit;
}
if (!Auth::can('pnl')) {
    http_response_code(403);
    echo '<p class="muted">Akun Anda tidak berhak membuka bagian ini.</p>';
    exit;
}

@set_time_limit(300);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

[$from, $to] = dateRange();
$platform = platformFilter();
$section  = q('section');

if ($section === 'produk') {
    $prodSort = q('psort', 'laba');
    // Seluruh produk diambil, bukan 100 teratas: yang di luar 100 disembunyikan
    // di layar tetapi tetap ikut tercetak, sehingga PDF-nya utuh tanpa perlu
    // memuat ulang halaman dengan pengaturan lain.
    $batasLayar = 100;
    $produk = Reports::productProfit($from, $to, $platform, 2000, (string) $prodSort);
    ?>

    <form method="get" class="filters" style="margin-bottom:14px">
      <?php foreach (['from' => $from, 'to' => $to, 'platform' => $platform] as $k => $v): ?>
        <?php if ($v !== null): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
      <?php endforeach; ?>
      <div class="field">
        <label>Urutkan produk</label>
        <select name="psort" onchange="this.form.submit()">
          <optgroup label="Terbaik di atas">
            <option value="laba"   <?= $prodSort === 'laba'   ? 'selected' : '' ?>>Laba tertinggi</option>
            <option value="marjin" <?= $prodSort === 'marjin' ? 'selected' : '' ?>>Marjin laba terbaik</option>
            <option value="bersih" <?= $prodSort === 'bersih' ? 'selected' : '' ?>>Bersih tertinggi</option>
            <option value="kotor"  <?= $prodSort === 'kotor'  ? 'selected' : '' ?>>Kotor tertinggi</option>
            <option value="qty"    <?= $prodSort === 'qty'    ? 'selected' : '' ?>>Terjual terbanyak</option>
          </optgroup>
          <optgroup label="Yang perlu diperiksa di atas">
            <option value="laba_asc"   <?= $prodSort === 'laba_asc'   ? 'selected' : '' ?>>Laba terendah</option>
            <option value="marjin_asc" <?= $prodSort === 'marjin_asc' ? 'selected' : '' ?>>Marjin laba terburuk</option>
            <option value="bersih_asc" <?= $prodSort === 'bersih_asc' ? 'selected' : '' ?>>Bersih terendah</option>
            <option value="kotor_asc"  <?= $prodSort === 'kotor_asc'  ? 'selected' : '' ?>>Kotor terendah</option>
            <option value="qty_asc"    <?= $prodSort === 'qty_asc'    ? 'selected' : '' ?>>Terjual tersedikit</option>
          </optgroup>
        </select>
      </div>
    </form>

    <p class="help" style="margin-top:-4px;margin-bottom:10px">
      <span class="no-print">Rantai nilainya sama dengan
      <a href="simulasi.php" target="_blank" rel="noopener">Simulasi Harga</a>:</span>
      kotor &minus; diskon &minus; biaya platform = <b>bersih</b> (dana diterima),
      lalu dikurangi <b>PPN <?= number_format(Tax::PPN_PERSEN, 0, ',', '.') ?>%</b> dan
      <b>PPh <?= number_format(Tax::PPH_PERSEN, 1, ',', '.') ?>%</b> menjadi <b>penjualan bersih</b>,
      baru dikurangi HPP. Marjin dihitung dari penjualan bersih.
      Kedua pajak dihitung dari nilai <i>setelah diskon</i>; pajak e-commerce hanya dikenakan
      pada pesanan sejak <?= e(date('d/m/Y', strtotime(Tax::PPH_MULAI))) ?>.
    </p>
    <div class="table-wrap">
      <table class="lebar">
        <thead><tr>
          <th>#</th><th>Produk</th><th>Platform</th>
          <th class="num">Qty</th>
          <th class="num">Kotor</th><th class="num">Diskon &amp; voucher</th>
          <th class="num">Biaya platform</th>
          <th class="num" title="Dana yang diterima dari platform, sebelum pajak">Bersih</th>
          <th class="num">PPN <?= number_format(Tax::PPN_PERSEN, 0, ',', '.') ?>%</th>
          <th class="num">PPh <?= number_format(Tax::PPH_PERSEN, 1, ',', '.') ?>%</th>
          <th class="num" title="Bersih setelah dikurangi PPN dan PPh">Penjualan bersih</th>
          <th class="num">HPP</th>
          <th class="num">Laba</th><th class="num">Marjin laba</th>
        </tr></thead>
        <tbody>
        <?php
        $pt = ['kotor' => 0.0, 'potongan' => 0.0, 'biaya' => 0.0, 'bersih' => 0.0,
               'ppn' => 0.0, 'pph' => 0.0, 'penjualan' => 0.0,
               'hpp' => 0.0, 'laba' => 0.0, 'qty' => 0.0];
        foreach ($produk as $i => $p):
            foreach ($pt as $k => $_) {
                $pt[$k] += (float) $p[$k];
            }
            $m = $p['marjin_laba'] === null ? null : (float) $p['marjin_laba'];
            $noHpp = (int) $p['qty_tanpa_hpp'] > 0;
            // Baris di luar 100 teratas disembunyikan di layar, tetapi tetap
            // ada di halaman sehingga ikut tercetak utuh.
            $lebih = $i >= $batasLayar; ?>
          <tr<?= $lebih ? ' class="lebih"' : '' ?>>
            <td class="muted"><?= $i + 1 ?></td>
            <td class="trunc" title="<?= e($p['produk']) ?>"><?= e($p['produk']) ?></td>
            <td><?= platformBadge((string) $p['platform']) ?></td>
            <td class="num"><?= num($p['qty']) ?></td>
            <td class="num"><?= rp($p['kotor']) ?></td>
            <td class="num <?= (float) $p['potongan'] < 0 ? 'neg' : 'muted' ?>"><?= rp($p['potongan']) ?></td>
            <td class="num neg"><?= rp($p['biaya']) ?></td>
            <td class="num"><?= rp($p['bersih']) ?></td>
            <td class="num neg"><?= rp($p['ppn']) ?></td>
            <td class="num neg"><?= rp($p['pph']) ?></td>
            <td class="num"><?= rp($p['penjualan']) ?></td>
            <td class="num <?= $noHpp ? 'warn' : 'neg' ?>" <?= $noHpp ? 'title="Sebagian atau seluruh unit belum punya HPP"' : '' ?>>
              <?= (float) $p['hpp'] == 0.0 && $noHpp ? '<span class="badge warn">belum ada</span>' : rp(-(float) $p['hpp']) ?>
            </td>
            <td class="num <?= (float) $p['laba'] < 0 ? 'neg' : 'pos' ?>"><b><?= rp($p['laba']) ?></b></td>
            <td class="num <?= $m === null ? 'muted' : ($m < 20 ? 'neg' : '') ?>">
              <?= $m === null ? '-' : number_format($m, 1, ',', '.') . '%' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($produk === []): ?>
          <tr><td colspan="14" class="muted">
            Belum bisa dihitung. Perlu berkas pesanan <i>dan</i> berkas laporan penghasilan
            untuk periode yang sama.
          </td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($produk !== []): ?>
        <tfoot><tr>
          <td colspan="3">Total <?= num(count($produk)) ?> produk</td>
          <td class="num"><?= num($pt['qty']) ?></td>
          <td class="num"><?= rp($pt['kotor']) ?></td>
          <td class="num neg"><?= rp($pt['potongan']) ?></td>
          <td class="num neg"><?= rp($pt['biaya']) ?></td>
          <td class="num"><?= rp($pt['bersih']) ?></td>
          <td class="num neg"><?= rp($pt['ppn']) ?></td>
          <td class="num neg"><?= rp($pt['pph']) ?></td>
          <td class="num"><?= rp($pt['penjualan']) ?></td>
          <td class="num neg"><?= rp(-$pt['hpp']) ?></td>
          <td class="num <?= $pt['laba'] < 0 ? 'neg' : 'pos' ?>"><?= rp($pt['laba']) ?></td>
          <td class="num"><?= $pt['penjualan'] > 0
              ? number_format($pt['laba'] / $pt['penjualan'] * 100, 1, ',', '.') . '%' : '-' ?></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>

    <?php if (count($produk) > $batasLayar): ?>
      <p class="help no-print" style="margin-top:10px">
        Ditampilkan <?= num($batasLayar) ?> teratas dari <?= num(count($produk)) ?> produk.
        <button class="btn ghost sm" type="button" data-lebih>Tampilkan semua</button>
        <br><b>Saat dicetak, seluruh <?= num(count($produk)) ?> produk ikut tercetak</b>
        meski di layar sedang diringkas.
      </p>
    <?php endif; ?>
    <?php if (count($produk) >= 2000): ?>
      <p class="help">Dibatasi 2.000 produk. Pakai <b>Ekspor CSV</b> bila produknya lebih banyak.</p>
    <?php endif; ?>
    <?php
    exit;
}

http_response_code(400);
echo '<p class="muted">Bagian tidak dikenal.</p>';
