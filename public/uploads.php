<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

Auth::require();

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
