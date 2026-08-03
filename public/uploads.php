<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::requireTab('uploads');

/**
 * Penghapusan data per bulan.
 *
 * Impor tidak pernah menggandakan, tetapi tidak ada yang bisa membatalkan
 * unggahan ke perusahaan yang keliru - datanya sah menurut database tujuan.
 * Karena itu disediakan penghapusan per bulan, mengikuti cara berkasnya
 * diekspor dari platform. Selalu didahului pratinjau: apa pun yang dihapus
 * hanya bisa dikembalikan dengan mengunggah berkasnya lagi.
 */
$hapusPesan = null;
$hapusGalat = null;
$pratinjau  = null;
$hpBulan    = (string) ($_POST['ym'] ?? $_GET['ym'] ?? '');
$hpJenis    = (array) ($_POST['jenis'] ?? Pembersih::JENIS);
$hpPlatform = (string) ($_POST['platform'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi'])) {
    try {
        if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Sesi tidak valid, muat ulang halaman.');
        }
        if (!Auth::canDelete()) {
            throw new RuntimeException('Hanya admin yang boleh menghapus data.');
        }
        $hpJenis = array_values(array_intersect($hpJenis, Pembersih::JENIS));
        if ($hpJenis === []) {
            throw new RuntimeException('Pilih minimal satu jenis data.');
        }
        $plat = $hpPlatform !== '' ? $hpPlatform : null;

        if ($_POST['aksi'] === 'pratinjau') {
            $pratinjau = Pembersih::hitung($hpBulan, $hpJenis, $plat);
        } elseif ($_POST['aksi'] === 'hapus') {
            // Bulan diketik ulang oleh penggunanya sebagai pengesahan; tanpa
            // itu satu klik keliru bisa menghapus sebulan penuh data.
            if (trim((string) ($_POST['sah'] ?? '')) !== $hpBulan) {
                throw new RuntimeException(
                    'Ketik ulang bulannya (' . $hpBulan . ') pada kotak pengesahan untuk melanjutkan.'
                );
            }
            $n = Pembersih::jalankan($hpBulan, $hpJenis, $plat);
            $bagian = [];
            foreach ($n as $k => $v) {
                if ($v > 0) {
                    $bagian[] = num($v) . ' ' . $k;
                }
            }
            $hapusPesan = $bagian === []
                ? 'Tidak ada data yang cocok, jadi tidak ada yang dihapus.'
                : 'Terhapus: ' . implode(', ', $bagian) . '.';
        }
    } catch (Throwable $e) {
        $hapusGalat = $e->getMessage();
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 40;
$total = (int) Db::val('SELECT COUNT(*) FROM uploads', [], 0);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = Db::all(
    "SELECT u.*, us.username FROM uploads u
     LEFT JOIN users us ON us.id = u.uploaded_by
     ORDER BY u.id DESC LIMIT {$per} OFFSET {$offset}"
);

// Berkas yang persis sama pernah diunggah lebih dari sekali.
$dupes = Db::all(
    'SELECT file_hash, COUNT(*) n, MAX(original_name) nama FROM uploads
     GROUP BY file_hash HAVING n > 1 ORDER BY n DESC LIMIT 10'
);

render_head('Riwayat Upload', 'uploads');
?>
<h1>Riwayat Upload</h1>
<p class="sub"><?= num($total) ?> berkas pernah diproses.</p>

<?php if ($dupes !== []): ?>
  <div class="alert info">
    <b>Catatan:</b> ada berkas dengan isi yang persis sama yang diunggah lebih dari sekali
    (mis. <?= e($dupes[0]['nama']) ?> &mdash; <?= (int) $dupes[0]['n'] ?>&times;).
    Ini aman: baris yang isinya sama otomatis dilewati sehingga data tidak dobel.
  </div>
<?php endif; ?>

<?php if (Auth::canDelete()): ?>
  <?php $tersedia = Pembersih::bulanTersedia(); ?>
  <div class="card">
    <h2>Hapus data per bulan</h2>
    <p class="help" style="margin-top:-4px">
      Untuk membereskan berkas yang terlanjur diunggah ke perusahaan yang keliru.
      Yang terhapus hanya perusahaan yang sedang dibuka:
      <b><?= e(Tenant::aktif()['nama']) ?></b>
      (<code class="k"><?= e(Tenant::aktif()['db']) ?></code>).
      Lihat dulu pratinjaunya sebelum menghapus &mdash; data yang hilang hanya bisa
      dikembalikan dengan mengunggah berkasnya lagi.
      Riwayat unggahannya sendiri tetap tercatat di daftar bawah sebagai jejak.
    </p>
    <p class="help">
      Dasar tanggalnya mengikuti jenis datanya: <b>pesanan</b> memakai tanggal pesanan dibuat,
      <b>penghasilan</b> memakai tanggal dana dilepaskan. Berkas Income Shopee biasanya
      melewati batas bulan, jadi periksa juga bulan berikutnya bila masih ada sisa.
    </p>

    <?php if ($hapusGalat !== null): ?>
      <div class="alert bad"><b>Gagal.</b> <?= e($hapusGalat) ?></div>
    <?php endif; ?>
    <?php if ($hapusPesan !== null): ?>
      <div class="alert ok"><?= e($hapusPesan) ?></div>
    <?php endif; ?>

    <form method="post" class="filters" style="align-items:flex-end;margin-bottom:0">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <div class="field">
        <label>Bulan</label>
        <select name="ym" required>
          <option value="">- pilih -</option>
          <?php foreach ($tersedia as $ym => $n): ?>
            <option value="<?= e($ym) ?>" <?= $ym === $hpBulan ? 'selected' : '' ?>>
              <?= e($ym) ?> &mdash; <?= num($n['pesanan']) ?> pesanan, <?= num($n['settlement']) ?> settlement
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Platform</label>
        <select name="platform">
          <option value="">Semua</option>
          <option value="tokopedia" <?= $hpPlatform === 'tokopedia' ? 'selected' : '' ?>>Tokopedia</option>
          <option value="shopee" <?= $hpPlatform === 'shopee' ? 'selected' : '' ?>>Shopee</option>
        </select>
      </div>
      <div class="field">
        <label>Jenis data</label>
        <label style="font-weight:400;display:block">
          <input type="checkbox" name="jenis[]" value="pesanan"
                 <?= in_array('pesanan', $hpJenis, true) ? 'checked' : '' ?>> Pesanan (Order)
        </label>
        <label style="font-weight:400;display:block">
          <input type="checkbox" name="jenis[]" value="penghasilan"
                 <?= in_array('penghasilan', $hpJenis, true) ? 'checked' : '' ?>> Penghasilan (Income)
        </label>
      </div>
      <button class="btn ghost" type="submit" name="aksi" value="pratinjau">Lihat pratinjau</button>
    </form>

    <?php if ($pratinjau !== null): ?>
      <?php $adaIsi = array_sum($pratinjau) > 0; ?>
      <div class="alert <?= $adaIsi ? 'warn' : 'info' ?>" style="margin-top:14px">
        <b>Yang akan dihapus untuk <?= e($hpBulan) ?><?= $hpPlatform !== '' ? ' (' . e($hpPlatform) . ')' : '' ?>:</b>
        <?php if (!$adaIsi): ?>
          tidak ada data yang cocok.
        <?php else: ?>
          <ul style="margin:6px 0 0 18px">
            <?php foreach ($pratinjau as $k => $v): ?>
              <li><?= num($v) ?> <?= e($k) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php if ($adaIsi): ?>
        <form method="post" class="filters" style="align-items:flex-end;margin-bottom:0"
              onsubmit="return confirm('Hapus permanen data <?= e($hpBulan) ?> di <?= e(Tenant::aktif()['nama']) ?>?')">
          <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
          <input type="hidden" name="ym" value="<?= e($hpBulan) ?>">
          <input type="hidden" name="platform" value="<?= e($hpPlatform) ?>">
          <?php foreach ($hpJenis as $j): ?>
            <input type="hidden" name="jenis[]" value="<?= e($j) ?>">
          <?php endforeach; ?>
          <div class="field">
            <label>Ketik <code class="k"><?= e($hpBulan) ?></code> untuk mengesahkan</label>
            <input type="text" name="sah" required placeholder="<?= e($hpBulan) ?>" autocomplete="off">
          </div>
          <button class="btn" type="submit" name="aksi" value="hapus"
                  style="background:var(--bad,#c0392b);border-color:var(--bad,#c0392b)">
            Hapus permanen
          </button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Waktu</th><th>Berkas</th><th>Jenis</th><th>Periode data</th><th>Status</th>
        <th class="num">Dibaca</th><th class="num">Baru</th><th class="num">Update</th>
        <th class="num">Sudah sama</th><th class="num">Durasi</th><th>Oleh</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $tone = match ($r['status']) {
              'success' => 'ok',
              'failed'  => 'bad',
              'partial' => 'warn',
              default   => 'info',
          }; ?>
        <tr>
          <td class="nowrap muted"><?= e(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></td>
          <td class="trunc" title="<?= e($r['original_name']) ?>"><?= e($r['original_name']) ?>
            <div class="muted" style="font-size:11px"><?= humanBytes((int) $r['size_bytes']) ?></div>
          </td>
          <td class="nowrap">
            <?= $r['platform'] !== null ? platformBadge((string) $r['platform']) : '<span class="muted">-</span>' ?>
            <div class="muted" style="font-size:11px"><?= e(match ($r['dataset']) {
              'order' => 'Pesanan', 'settlement' => 'Settlement',
              'hpp' => 'HPP produk', 'beban' => 'Beban operasional', default => '-',
            }) ?></div>
          </td>
          <td class="nowrap muted">
            <?= $r['period_from'] !== null ? shortDate($r['period_from']) . ' – ' . shortDate($r['period_to']) : '-' ?>
          </td>
          <td><span class="badge <?= $tone ?>"><?= e($r['status']) ?></span>
            <?php if ($r['message']): ?>
              <div class="muted trunc" style="font-size:11px;max-width:220px" title="<?= e($r['message']) ?>"><?= e($r['message']) ?></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= num($r['rows_read']) ?></td>
          <td class="num pos"><?= num($r['rows_inserted']) ?></td>
          <td class="num"><?= num($r['rows_updated']) ?></td>
          <td class="num muted"><?= num($r['rows_unchanged']) ?></td>
          <td class="num muted"><?= number_format((int) $r['duration_ms'] / 1000, 1, ',', '.') ?>s</td>
          <td class="muted"><?= e($r['username'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="11" class="muted">Belum ada upload.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
      <?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php render_foot(); ?>
