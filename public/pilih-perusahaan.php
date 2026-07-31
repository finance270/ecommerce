<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Memilih PT yang akan dibuka.
 *
 * Muncul setelah masuk dengan email bila akunnya memegang lebih dari satu PT,
 * dan bisa dibuka kapan saja lewat tautan "Ganti PT" di kanan atas.
 */

$akun = Auth::akun();
if ($akun === null) {
    header('Location: login.php');
    exit;
}

$daftar = Pusat::perusahaanAkun((int) $akun['id']);
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $err = 'Sesi kedaluwarsa, silakan coba lagi.';
    } elseif (Auth::masukPerusahaan((string) ($_POST['kode'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $err = 'PT tersebut tidak bisa dibuka. Databasenya mungkin belum terpasang.';
    }
}

render_head('Pilih PT', '');
?>
<h1>Pilih PT</h1>
<p class="sub">
  Masuk sebagai <b><?= e($akun['nama'] ?: $akun['email']) ?></b>
  (<code class="k"><?= e($akun['email']) ?></code>).
</p>

<?php if ($err !== null): ?>
  <div class="alert bad"><?= e($err) ?></div>
<?php endif; ?>

<?php if ($daftar === []): ?>
  <div class="alert warn">
    <b>Belum ada PT.</b> Akun ini belum dihubungkan ke perusahaan mana pun.
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Perusahaan</th><th>Peran</th><th>Database</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($daftar as $p): ?>
            <tr>
              <td><b><?= e($p['nama']) ?></b></td>
              <td><span class="badge <?= $p['peran'] === 'staf' ? 'muted' : 'ok' ?>"><?= e($p['peran']) ?></span></td>
              <td class="muted" style="font-family:ui-monospace,monospace;font-size:12px"><?= e($p['db_name']) ?></td>
              <td style="text-align:right">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                  <input type="hidden" name="kode" value="<?= e($p['kode']) ?>">
                  <button class="btn sm" type="submit">Buka</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<p style="margin-top:16px">
  <a class="btn ghost" href="perusahaan.php">Kelola PT &amp; pengguna</a>
  <a class="btn ghost" href="logout.php">Keluar</a>
</p>
<?php render_foot(); ?>
