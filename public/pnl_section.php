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

@set_time_limit(300);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

[$from, $to] = dateRange();
$platform = platformFilter();
$section  = q('section');

if ($section === 'produk') {
    $prodSort = q('psort', 'laba');
    $produk   = Reports::productProfit($from, $to, $platform, 100, (string) $prodSort);
    $cover    = Reports::productNetCoverage($from, $to, $platform);
    // Berbasis jumlah pesanan, bukan nilai: nilai settlement bisa negatif
    // (pembalikan) sehingga persentase berbasis nilai bisa melewati 100%.
    $cov = $cover['total_pesanan'] > 0
        ? $cover['covered_pesanan'] / $cover['total_pesanan'] * 100 : 100.0;
    ?>
    <?php if ($cover['covered_pesanan'] < $cover['total_pesanan']): ?>
      <div class="alert warn" style="margin-bottom:14px">
        Baru <b><?= number_format($cov, 1, ',', '.') ?>%</b> pesanan yang bisa dipecah ke produk
        (<?= num($cover['covered_pesanan']) ?> dari <?= num($cover['total_pesanan']) ?> pesanan).
        Sisanya settlement yang <b>berkas pesanannya belum diunggah</b>, sehingga isi produknya belum diketahui.
        Unggah berkas <i>Semua Pesanan</i> / <i>Order</i> untuk periode terkait agar analisis ini lengkap.
        <a href="monitoring.php">Lihat periode mana yang kurang &rarr;</a>
      </div>
    <?php endif; ?>

    <form method="get" class="filters" style="margin-bottom:14px">
      <?php foreach (['from' => $from, 'to' => $to, 'platform' => $platform] as $k => $v): ?>
        <?php if ($v !== null): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
      <?php endforeach; ?>
      <div class="field">
        <label>Urutkan produk</label>
        <select name="psort" onchange="this.form.submit()">
          <option value="laba"   <?= $prodSort === 'laba'   ? 'selected' : '' ?>>Laba tertinggi</option>
          <option value="marjin" <?= $prodSort === 'marjin' ? 'selected' : '' ?>>Marjin laba terbaik</option>
          <option value="bersih" <?= $prodSort === 'bersih' ? 'selected' : '' ?>>Bersih tertinggi</option>
          <option value="kotor"  <?= $prodSort === 'kotor'  ? 'selected' : '' ?>>Kotor tertinggi</option>
          <option value="qty"    <?= $prodSort === 'qty'    ? 'selected' : '' ?>>Terjual terbanyak</option>
        </select>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>#</th><th>Produk</th><th>Platform</th>
          <th class="num">Pesanan</th><th class="num">Qty</th>
          <th class="num">Kotor</th><th class="num">Diskon &amp; voucher</th>
          <th class="num">Pengembalian</th><th class="num">Biaya platform</th>
          <th class="num">Bersih</th><th class="num">HPP</th>
          <th class="num">Laba</th><th class="num">Marjin laba</th>
        </tr></thead>
        <tbody>
        <?php
        $pt = ['kotor' => 0.0, 'potongan' => 0.0, 'pengembalian' => 0.0, 'biaya' => 0.0,
               'bersih' => 0.0, 'hpp' => 0.0, 'laba' => 0.0, 'qty' => 0.0];
        foreach ($produk as $i => $p):
            foreach ($pt as $k => $_) {
                $pt[$k] += (float) $p[$k];
            }
            $m = $p['marjin_laba'] === null ? null : (float) $p['marjin_laba'];
            $noHpp = (int) $p['qty_tanpa_hpp'] > 0; ?>
          <tr>
            <td class="muted"><?= $i + 1 ?></td>
            <td class="trunc" title="<?= e($p['produk']) ?>"><?= e($p['produk']) ?></td>
            <td><?= platformBadge((string) $p['platform']) ?></td>
            <td class="num"><?= num($p['pesanan']) ?></td>
            <td class="num"><?= num($p['qty']) ?></td>
            <td class="num"><?= rp($p['kotor']) ?></td>
            <td class="num <?= (float) $p['potongan'] < 0 ? 'neg' : 'muted' ?>"><?= rp($p['potongan']) ?></td>
            <td class="num <?= (float) $p['pengembalian'] < 0 ? 'neg' : 'muted' ?>"><?= rp($p['pengembalian']) ?></td>
            <td class="num neg"><?= rp($p['biaya']) ?></td>
            <td class="num"><?= rp($p['bersih']) ?></td>
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
          <tr><td colspan="13" class="muted">
            Belum bisa dihitung. Perlu berkas pesanan <i>dan</i> berkas laporan penghasilan
            untuk periode yang sama.
          </td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($produk !== []): ?>
        <tfoot><tr>
          <td colspan="4">Total <?= count($produk) ?> produk teratas</td>
          <td class="num"><?= num($pt['qty']) ?></td>
          <td class="num"><?= rp($pt['kotor']) ?></td>
          <td class="num neg"><?= rp($pt['potongan']) ?></td>
          <td class="num neg"><?= rp($pt['pengembalian']) ?></td>
          <td class="num neg"><?= rp($pt['biaya']) ?></td>
          <td class="num"><?= rp($pt['bersih']) ?></td>
          <td class="num neg"><?= rp(-$pt['hpp']) ?></td>
          <td class="num pos"><?= rp($pt['laba']) ?></td>
          <td class="num"><?= $pt['bersih'] > 0 ? number_format($pt['laba'] / $pt['bersih'] * 100, 1, ',', '.') . '%' : '-' ?></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php
    exit;
}

if ($section === 'biaya') {
    $detail = Reports::feeDetail($from, $to, $platform);
    ?>
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
    <?php
    exit;
}

http_response_code(400);
echo '<p class="muted">Bagian tidak dikenal.</p>';
