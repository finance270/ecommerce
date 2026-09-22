<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Monitoring kelengkapan data - satu tabel saja: kelengkapan per bulan.
 *
 * Seluruhnya berdasar TANGGAL PESANAN, sama seperti Laba & Biaya. Setiap
 * pesanan pasti berakhir selesai atau berhenti sebagai retur/batal, jadi yang
 * dipantau hanya dua hal yang membuat sebuah bulan belum bisa dibaca final:
 * pesanan yang belum selesai, dan pesanan selesai yang dananya belum cair.
 *
 * Daftar pesanannya tidak ditampilkan terus-menerus - baru muncul setelah
 * angka pada bulan yang bersangkutan diklik.
 */

Auth::requireTab('monitoring');
@set_time_limit(300);

/** Ambil satu parameter bulan (YYYY-MM) dari tautan, atau null. */
$bulanParam = static function (string $nama): ?string {
    $v = q($nama);
    return is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v) === 1 ? $v : null;
};

$cair = $bulanParam('cair');     // rincian "dana belum cair" satu bulan
$pend = $bulanParam('pending');  // rincian "belum selesai" satu bulan

$bulan        = Reports::dataMonitor();
$belumRinci   = $cair !== null ? Reports::danaBelumDilepasRinci(7, null, 300, $cair) : [];
$belumSelesai = $pend !== null ? Reports::pesananBelumSelesai($pend, 300) : [];

/** Tautan ke halaman ini dengan sebagian parameter diganti. */
$tautan = static function (array $ganti) use ($cair, $pend): string {
    $p = array_merge(['cair' => $cair, 'pending' => $pend], $ganti);
    $qs = http_build_query(array_filter($p, static fn($v) => $v !== null && $v !== ''));
    return $qs === '' ? 'monitoring.php' : '?' . $qs;
};

render_head('Monitoring Data', 'monitoring');
?>
<h1>Monitoring Kelengkapan Data</h1>
<p class="sub">
  Melihat periode mana yang datanya belum lengkap, supaya laporan tidak dibaca sebagai angka
  final padahal pesanannya belum tuntas atau dananya belum cair.
</p>

<div class="card">
  <h2>
    Kelengkapan per bulan
    <a class="btn ghost sm" href="export.php?report=monitoring">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Dihitung menurut <b>tanggal pesanan</b>, sama seperti Laba &amp; Biaya. Setiap pesanan pasti
    berakhir <b>selesai</b> atau berhenti sebagai <b>retur/batal</b>; selama masih ada yang
    <b>belum selesai</b>, angka bulan itu masih akan berubah. Pesanan yang sudah selesai tetapi
    <b>dananya belum cair</b> sudah diakui sebagai penjualan, tetapi biaya platform dan laba
    bersihnya belum diketahui. Klik angkanya untuk melihat pesanan mana saja.
  </p>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Bulan</th>
        <th class="num">Pesanan</th><th class="num">Selesai</th><th class="num">Retur/batal</th>
        <th class="num">Belum selesai</th><th class="num">Dana belum cair</th>
        <th class="num">HPP</th><th class="num">Beban operasional</th>
        <th>Terakhir diperbarui</th>
      </tr></thead>
      <tbody>
      <?php foreach ($bulan as $b):
          $hppOk = $b['produk'] > 0 ? ($b['produk'] - $b['produk_tanpa_hpp']) / $b['produk'] * 100 : null;
          $upd = max((string) $b['pesanan_update'], (string) $b['settlement_update']);
          // Persentase dihitung dari JUMLAH pesanan, bukan nilainya: nilai
          // pesanan retur bisa negatif sehingga persentase berbasis nilai
          // dapat melampaui 100% dan membingungkan.
          $selesaiPersen = $b['pesanan'] > 0 ? $b['selesai'] / $b['pesanan'] * 100 : null;
          $cairPersen = $b['selesai'] > 0
              ? ($b['selesai'] - $b['belum_cair'] - $b['belum_dinilai']) / $b['selesai'] * 100
              : null; ?>
        <tr<?= $cair === $b['ym'] || $pend === $b['ym'] ? ' class="sorot"' : '' ?>>
          <td class="nowrap"><b><?= e($b['ym']) ?></b></td>
          <td class="num"><?= $b['pesanan'] > 0 ? num($b['pesanan']) : '<span class="badge bad">kosong</span>' ?></td>
          <td class="num">
            <?php if ($b['pesanan'] > 0): ?>
              <?= num($b['selesai']) ?>
              <div class="muted" style="font-size:11px"><?= number_format((float) $selesaiPersen, 1, ',', '.') ?>%</div>
            <?php else: ?><span class="muted">-</span><?php endif; ?>
          </td>
          <td class="num"><?= $b['retur_batal'] > 0 ? num($b['retur_batal']) : '<span class="muted">-</span>' ?></td>
          <td class="num">
            <?php if ($b['pending'] > 0): ?>
              <a href="<?= e($tautan(['pending' => $b['ym']]) . '#proses') ?>" class="neg"><?= num($b['pending']) ?></a>
              <div class="muted" style="font-size:11px">nilai <?= rp($b['nilai_pending']) ?></div>
            <?php elseif ($b['pesanan'] > 0): ?><span class="badge ok">tuntas</span>
            <?php else: ?><span class="muted">-</span><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($b['belum_cair'] > 0): ?>
              <a href="<?= e($tautan(['cair' => $b['ym']]) . '#dana') ?>" class="neg"><?= num($b['belum_cair']) ?> dari <?= num($b['selesai']) ?></a>
              <div class="muted" style="font-size:11px">nilai <?= rp($b['nilai_belum_cair']) ?></div>
              <div class="bar"><span style="width:<?= round((float) $cairPersen) ?>%;background:#c0392b"></span></div>
            <?php elseif ($b['belum_dinilai'] > 0): ?>
              <span class="badge info">belum bisa dinilai</span>
              <div class="muted" style="font-size:11px"><?= num($b['belum_dinilai']) ?> pesanan</div>
            <?php elseif ($b['selesai'] > 0): ?><span class="badge ok">cair semua</span>
            <?php else: ?><span class="muted">-</span><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($hppOk === null): ?><span class="muted">-</span>
            <?php elseif ($hppOk >= 100): ?><span class="badge ok">lengkap</span>
            <?php else: ?>
              <?php if (Auth::can('costs')): ?>
                <a href="costs.php?ym=<?= e($b['ym']) ?>" class="neg"><?= number_format($hppOk, 0, ',', '.') ?>%</a>
              <?php else: ?>
                <span class="neg"><?= number_format($hppOk, 0, ',', '.') ?>%</span>
              <?php endif; ?>
              <div class="muted" style="font-size:11px"><?= num($b['produk_tanpa_hpp']) ?> produk kurang</div>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($b['beban_baris'] > 0): ?><?= rp($b['beban']) ?>
            <?php elseif (Auth::can('expenses')): ?><a href="expenses.php" class="muted">belum diisi</a>
            <?php else: ?><span class="muted">belum diisi</span><?php endif; ?>
          </td>
          <td class="nowrap muted"><?= $upd !== '' ? e(date('d/m/Y H:i', strtotime($upd))) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($bulan === []): ?><tr><td colspan="9" class="muted">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="help" style="margin-top:12px">
    <b>Belum bisa dinilai</b> berarti berkas penghasilan untuk tanggal itu memang belum diunggah,
    jadi yang belum ada adalah datanya &mdash; bukan dananya.
  </p>
</div>

<?php if ($pend !== null): ?>
<div class="card" id="proses">
  <h2>
    Pesanan yang belum selesai &mdash; <?= e($pend) ?>
    <a class="btn ghost sm" href="<?= e($tautan(['pending' => null])) ?>">Tutup</a>
    <a class="btn ghost sm" href="<?= e('export.php?report=belum_selesai&ym=' . urlencode($pend)) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Masih berjalan di platform &mdash; belum selesai, belum retur, belum batal. Pesanan ini
    <b>belum diakui</b> sebagai penjualan di Laba &amp; Biaya, jadi angka bulannya masih akan
    berubah setelah statusnya berubah. Untuk bulan yang sudah lama lewat, status yang tidak
    pernah berubah biasanya berarti <b>berkas pesanannya belum diunggah ulang</b> setelah
    pesanan itu tuntas.
    <?= count($belumSelesai) >= 300 ? 'Ditampilkan 300 pesanan terlama.' : '' ?>
  </p>
  <?php if ($belumSelesai === []): ?>
    <div class="alert ok">
      Tidak ada pesanan yang menggantung pada <?= e($pend) ?> &mdash; semuanya sudah selesai,
      retur, atau batal.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Platform</th><th>No. Pesanan</th><th>Tanggal pesanan</th>
          <th class="num">Umur</th><th class="num">Nilai</th><th>Status di platform</th>
        </tr></thead>
        <tbody>
        <?php $totPend = 0.0; foreach ($belumSelesai as $r): $totPend += (float) $r['nilai']; ?>
          <tr>
            <td><?= platformBadge((string) $r['platform']) ?></td>
            <td class="nowrap"><a href="<?= e('order.php?platform=' . urlencode((string) $r['platform']) . '&id=' . urlencode((string) $r['order_id'])) ?>"><?= e($r['order_id']) ?></a></td>
            <td class="nowrap muted"><?= e(date('d/m/Y', strtotime((string) $r['order_date']))) ?></td>
            <td class="num <?= (int) $r['umur'] > 30 ? 'neg' : '' ?>"><?= num((int) $r['umur']) ?> hari</td>
            <td class="num"><?= rp((float) $r['nilai']) ?></td>
            <td class="trunc muted" style="font-size:12px" title="<?= e((string) $r['status_raw']) ?>">
              <?= e($r['status_raw'] ?: $r['status_norm']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4">Total <?= num(count($belumSelesai)) ?> pesanan ditampilkan</td>
          <td class="num"><?= rp($totPend) ?></td><td></td></tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($cair !== null): ?>
<div class="card" id="dana">
  <h2>
    Pesanan yang dananya belum cair &mdash; <?= e($cair) ?>
    <a class="btn ghost sm" href="<?= e($tautan(['cair' => null])) ?>">Tutup</a>
    <a class="btn ghost sm" href="<?= e('export.php?report=belum_cair&ym=' . urlencode($cair)) ?>">Ekspor CSV</a>
  </h2>
  <p class="help" style="margin-top:-4px;margin-bottom:12px">
    Seluruh pesanan <b>selesai</b> bertanggal <?= e($cair) ?> yang belum ada catatan pencairannya
    &mdash; termasuk yang masih wajar karena baru selesai. Umurnya dihitung sejak
    <b>pesanan selesai</b> (Shopee: <i>Waktu Pesanan Selesai</i>, Tokopedia: <i>Delivered Time</i>),
    karena dari situlah hitungan pencairan platform mulai berjalan; biasanya dana dilepas sekitar
    seminggu setelahnya. Selama belum cair, biaya platform dan laba pesanan ini belum masuk
    hitungan.
    <?= count($belumRinci) >= 300 ? 'Ditampilkan 300 teratas.' : '' ?>
  </p>
  <?php if ($belumRinci === []): ?>
    <div class="alert ok">Semua pesanan selesai bulan <?= e($cair) ?> sudah ada catatan pencairannya.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Platform</th><th>No. Pesanan</th><th>Tanggal pesanan</th><th>Tanggal selesai</th>
          <th class="num">Menunggu</th><th class="num">Nilai</th><th>Status di platform</th>
        </tr></thead>
        <tbody>
        <?php $totCair = 0.0; foreach ($belumRinci as $r): $totCair += (float) $r['nilai']; ?>
          <tr>
            <td><?= platformBadge((string) $r['platform']) ?></td>
            <td class="nowrap"><a href="<?= e('order.php?platform=' . urlencode((string) $r['platform']) . '&id=' . urlencode((string) $r['order_id'])) ?>"><?= e($r['order_id']) ?></a></td>
            <td class="nowrap muted"><?= e(date('d/m/Y', strtotime((string) $r['order_date']))) ?></td>
            <td class="nowrap muted"><?= e(date('d/m/Y', strtotime((string) $r['tgl_selesai']))) ?></td>
            <td class="num <?= (int) $r['umur'] > 14 ? 'neg' : '' ?>"><?= num((int) $r['umur']) ?> hari</td>
            <td class="num"><?= rp((float) $r['nilai']) ?></td>
            <td class="trunc muted" style="font-size:12px" title="<?= e((string) $r['status_raw']) ?>"><?= e($r['status_raw'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5">Total <?= num(count($belumRinci)) ?> pesanan ditampilkan</td>
          <td class="num"><?= rp($totCair) ?></td><td></td></tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render_foot(); ?>
