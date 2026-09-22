<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Penjualan yang tidak jadi - dua peristiwa berbeda dalam satu halaman.
 *
 *   BATAL & RETUR   pesanan yang berhenti sebelum tuntas. Platform tidak
 *                   pernah membayarkannya, jadi tidak ada uang yang bergerak;
 *                   nilainya diambil dari tabel pesanan.
 *   PENGEMBALIAN    uang yang sudah cair lalu ditarik kembali. Nilainya ada
 *                   di berkas penghasilan, dan sudah dipotong dari pendapatan
 *                   kotor di Laba & Biaya.
 *
 * Keduanya sengaja disandingkan dalam satu tabel karena sering tertukar:
 * jumlah pesanan batal hampir selalu jauh lebih besar daripada jumlah
 * transaksi pengembalian.
 *
 * Halaman ini sengaja hanya berisi ANGKA - tanpa daftar transaksi - supaya
 * seluruhnya terbaca tanpa menggulir. Rincian per pesanan tetap tersedia
 * lewat ekspor CSV.
 */

Auth::requireTab('refunds');
@set_time_limit(300);

[$from, $to] = dateRange();
$platform = platformFilter();

$batal = Reports::batalSummary($from, $to, $platform);
$ring  = Reports::refundSummary($from, $to, $platform);

$refund = (float) ($ring['refund'] ?? 0);
$rasio  = $ring['rasio'] ?? null;

// Kedua rekap bulanan digabung jadi satu baris per bulan+platform, supaya
// pembatalan dan pengembalian bisa dibandingkan langsung tanpa dua tabel.
$baris = [];
$kunci = static fn(array $r): string => $r['bulan'] . '|' . $r['platform'];
$kosong = [
    'batal' => 0, 'retur' => 0, 'nilai' => 0.0, 'nilai_semua' => 0.0,
    'refund' => 0.0, 'kotor_sebelum' => 0.0, 'trx_refund' => 0,
];
foreach (Reports::batalByMonth($from, $to, $platform) as $r) {
    $k = $kunci($r);
    $baris[$k] = array_merge($kosong, [
        'bulan' => $r['bulan'], 'platform' => $r['platform'],
        'batal' => (int) $r['batal'], 'retur' => (int) $r['retur'],
        'nilai' => (float) $r['nilai'], 'nilai_semua' => (float) $r['nilai_semua'],
    ]);
}
foreach (Reports::refundByMonth($from, $to, $platform) as $r) {
    $k = $kunci($r);
    $baris[$k] = array_merge(
        $baris[$k] ?? $kosong + ['bulan' => $r['bulan'], 'platform' => $r['platform']],
        [
            'refund'        => (float) $r['refund'],
            'kotor_sebelum' => (float) $r['kotor_sebelum'],
            'trx_refund'    => (int) $r['trx_refund'],
        ]
    );
}
krsort($baris);

render_head('Batal & Retur', 'refunds');
?>
<h1>Pembatalan &amp; Pengembalian Dana</h1>
<p class="sub">
  Dua hal yang sering tertukar, dan keduanya sama-sama <b>tidak masuk omzet</b> di Laba &amp; Biaya.
  Berbasis <b>tanggal pesanan</b>, sama seperti laporan Laba &amp; Biaya.
  <b>Batal/retur</b> berarti platform tidak pernah membayarkannya &mdash; tidak ada uang yang
  dikembalikan, nilainya sekadar penjualan yang tidak jadi, dan diukur dari berkas <b>pesanan</b>.
  <b>Pengembalian dana</b> berarti dana sudah cair lalu ditarik kembali, diukur dari berkas
  <b>penghasilan</b>, dan sudah dipotong dari pendapatan kotor sehingga tidak dikurangi dua kali.
</p>
<p class="sub" style="margin-top:-8px">
  Karena itu jumlah pesanan batal hampir selalu <b>jauh lebih besar</b> daripada jumlah transaksi
  pengembalian.
  <?php if ($platform === 'shopee' || $platform === null): ?>
    Khusus Shopee, pesanan yang returnya disetujui tetap ditulis berstatus <b>Selesai</b>, jadi
    pesanan itu terhitung sebagai <b>pengembalian dana</b>, bukan sebagai batal.
  <?php endif; ?>
  Rincian per pesanan tidak ditampilkan di sini &mdash; ambil lewat <b>Ekspor CSV</b> bila perlu.
</p>

<?php render_filter($from, $to, $platform); ?>

<div class="kpis">
  <div class="kpi <?= $batal['rasio'] !== null && $batal['rasio'] > 10 ? 'bad' : '' ?>">
    <div class="label">Nilai pesanan batal</div>
    <div class="value"><?= rp($batal['nilai'], true) ?></div>
    <div class="hint">
      <?= num($batal['pesanan']) ?> pesanan &mdash;
      <?= num($batal['batal']) ?> batal &middot; <?= num($batal['retur']) ?> retur
    </div>
  </div>
  <div class="kpi">
    <div class="label">Batal terhadap seluruh pesanan</div>
    <div class="value"><?= $batal['rasio'] === null ? '-' : num($batal['rasio'], 2) . '%' ?></div>
    <div class="hint">dari <?= rp($batal['nilai_total'], true) ?> &middot; <?= num($batal['pesanan_total']) ?> pesanan</div>
  </div>
  <div class="kpi <?= $rasio !== null && $rasio > 5 ? 'bad' : '' ?>">
    <div class="label">Pengembalian dana</div>
    <div class="value"><?= rp($refund, true) ?></div>
    <div class="hint">
      <?= num($ring['trx_refund'] ?? 0) ?> transaksi pada
      <?= num($ring['pesanan_refund'] ?? 0) ?> pesanan
    </div>
  </div>
  <div class="kpi">
    <div class="label">Pengembalian terhadap kotor</div>
    <div class="value"><?= $rasio === null ? '-' : num($rasio, 2) . '%' ?></div>
    <div class="hint">dari <?= rp($ring['kotor_sebelum'] ?? 0, true) ?> sebelum refund</div>
  </div>
</div>

<div class="card">
  <h2>
    Per bulan
    <span style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn ghost sm" href="<?= e(exportLink('batal')) ?>">Ekspor pesanan batal</a>
      <a class="btn ghost sm" href="<?= e(exportLink('refunds')) ?>">Ekspor pengembalian</a>
      <a class="btn ghost sm" href="<?= e(exportLink('refund_product')) ?>">Ekspor per produk</a>
    </span>
  </h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th rowspan="2">Bulan</th><th rowspan="2">Platform</th>
          <th class="num" colspan="4">Batal &amp; retur</th>
          <th class="num" colspan="3">Pengembalian dana</th>
        </tr>
        <tr>
          <th class="num">Batal</th><th class="num">Retur</th>
          <th class="num">Nilai tidak jadi</th><th class="num">Rasio</th>
          <th class="num">Nilai</th><th class="num">Transaksi</th><th class="num">Rasio</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($baris as $b):
          $semua = (float) $b['nilai_semua'];
          $kotor = (float) $b['kotor_sebelum'];
          $rBatal  = $semua > 0 ? (float) $b['nilai'] / $semua * 100 : null;
          $rRefund = $kotor > 0 ? (float) $b['refund'] / $kotor * 100 : null; ?>
        <tr>
          <td class="nowrap"><?= e($b['bulan']) ?></td>
          <td><?= platformBadge((string) $b['platform']) ?></td>
          <td class="num"><?= $b['batal'] > 0 ? num($b['batal']) : '<span class="muted">-</span>' ?></td>
          <td class="num"><?= $b['retur'] > 0 ? num($b['retur']) : '<span class="muted">-</span>' ?></td>
          <td class="num neg"><?= $b['nilai'] > 0 ? rp($b['nilai']) : '<span class="muted">-</span>' ?></td>
          <td class="num <?= $rBatal !== null && $rBatal > 10 ? 'neg' : '' ?>">
            <?= $rBatal === null ? '<span class="muted">-</span>' : num($rBatal, 2) . '%' ?>
          </td>
          <td class="num neg"><?= $b['refund'] > 0 ? rp($b['refund']) : '<span class="muted">-</span>' ?></td>
          <td class="num"><?= $b['trx_refund'] > 0 ? num($b['trx_refund']) : '<span class="muted">-</span>' ?></td>
          <td class="num <?= $rRefund !== null && $rRefund > 5 ? 'neg' : '' ?>">
            <?= $rRefund === null ? '<span class="muted">-</span>' : num($rRefund, 2) . '%' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($baris === []): ?>
        <tr><td colspan="9" class="pos">
          Tidak ada pesanan batal, retur, maupun pengembalian dana pada rentang ini.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    <b>Rasio batal</b> dihitung terhadap nilai <b>seluruh pesanan</b> bulan itu, sedangkan
    <b>rasio pengembalian</b> terhadap pendapatan kotor <b>sebelum refund</b> &mdash; masing-masing
    dasar yang benar untuk angkanya sendiri.
  </p>
</div>
<?php render_foot(); ?>
