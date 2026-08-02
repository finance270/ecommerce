<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Kelola PT dan siapa yang boleh membukanya.
 *
 * Pembagian tugasnya sengaja dipisah menjadi dua halaman:
 *
 *   halaman ini  - SIAPA yang boleh masuk ke PT mana. Diatur oleh pemilik PT.
 *   Pengguna     - APA saja yang boleh dia lihat di dalam PT itu (tab, gaji).
 *                  Diatur oleh admin PT tersebut, di database PT itu sendiri.
 *
 * Dengan begitu satu orang bisa jadi admin penuh di PT A dan hanya melihat
 * laba rugi di PT B tanpa perlu dua akun.
 */

$akun = Auth::akun();
if ($akun === null) {
    header('Location: login.php');
    exit;
}
$akunId = (int) $akun['id'];

$err = null;
$ok  = null;

/** PT yang perannya pemilik - hanya di sinilah keanggotaan boleh diubah. */
function ptMilik(int $akunId, string $kode): array
{
    $p = Pusat::perusahaanByKode($kode);
    if ($p === null || Pusat::peran($akunId, $kode) !== 'pemilik') {
        throw new RuntimeException('Anda bukan pemilik PT tersebut.');
    }
    return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Sesi tidak valid, muat ulang halaman.');
        }
        $aksi = (string) ($_POST['aksi'] ?? '');

        if ($aksi === 'tambah_pt') {
            // Membuat PT berarti membuat database baru, jadi haknya dipegang
            // pemilik aplikasi saja - bukan setiap orang yang diberi akses ke
            // salah satu PT.
            if ((int) $akun['is_super'] !== 1) {
                throw new RuntimeException('Hanya pemilik aplikasi yang bisa menambah PT.');
            }
            $kode = (string) ($_POST['kode'] ?? '');
            $nama = (string) ($_POST['nama'] ?? '');
            $db   = trim((string) ($_POST['db'] ?? ''));
            if ($db === '') {
                // Nama database dibentuk dari kode supaya tidak perlu dipikirkan.
                $db = 'ecommerce_' . Tenant::normalKode($kode);
            }
            $baru = Pusat::daftarkanPerusahaan($kode, $nama, $db, $akunId);
            $ok = 'PT ' . $baru['nama'] . ' dibuat beserta databasenya (' . $baru['db'] . ').';

        } elseif ($aksi === 'tambah_anggota') {
            $pt = ptMilik($akunId, (string) ($_POST['pt'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $peran = (string) ($_POST['peran'] ?? 'staf');

            $target = Pusat::akunByEmail($email);
            if ($target === null) {
                $sandi = (string) ($_POST['password'] ?? '');
                if (strlen($sandi) < 8) {
                    throw new RuntimeException(
                        'Email tersebut belum terdaftar. Isi kata sandi awal (minimal 8 karakter) '
                        . 'untuk membuatkan akunnya.'
                    );
                }
                $id = Pusat::buatAkun($email, $sandi, trim((string) ($_POST['nama_anggota'] ?? '')));
                $target = Pusat::akunById($id);
            }
            Pusat::tambahAnggota((int) $target['id'], $pt, $peran);
            $ok = $email . ' sekarang bisa membuka ' . $pt['nama'] . ' sebagai ' . $peran . '.';

        } elseif ($aksi === 'ubah_nama') {
            $pt = ptMilik($akunId, (string) ($_POST['pt'] ?? ''));
            Pusat::ubahNama((int) $pt['id'], (string) ($_POST['nama'] ?? ''));
            $ok = 'Nama PT diperbarui.';

        } elseif ($aksi === 'ubah_db') {
            $pt = ptMilik($akunId, (string) ($_POST['pt'] ?? ''));
            $h = Pusat::ubahDatabase((int) $pt['id'], (string) ($_POST['db'] ?? ''));
            $ok = 'Database ' . $pt['db_name'] . ' dipindahkan ke ' . trim((string) $_POST['db'])
                . ' (' . $h['tabel'] . ' tabel, ' . $h['view'] . ' view dibuat ulang).'
                . ($h['lama_dibuang'] ? '' : ' Database lama masih menyisakan ' . $h['sisa']
                    . ' objek sehingga tidak dibuang - periksa manual.');

        } elseif ($aksi === 'ubah_peran') {
            $pt = ptMilik($akunId, (string) ($_POST['pt'] ?? ''));
            $target = (int) ($_POST['akun_id'] ?? 0);
            $peran = (string) ($_POST['peran'] ?? 'staf');
            if ($target === $akunId && $peran !== 'pemilik' && Pusat::jumlahPemilik((int) $pt['id']) < 2) {
                throw new RuntimeException('PT harus punya minimal satu pemilik.');
            }
            Pusat::tambahAnggota($target, $pt, $peran);
            $ok = 'Peran diperbarui.';

        } elseif ($aksi === 'hapus_anggota') {
            $pt = ptMilik($akunId, (string) ($_POST['pt'] ?? ''));
            $target = (int) ($_POST['akun_id'] ?? 0);
            if (Pusat::peran($target, (string) $pt['kode']) === 'pemilik'
                && Pusat::jumlahPemilik((int) $pt['id']) < 2) {
                throw new RuntimeException('Pemilik terakhir tidak bisa dikeluarkan.');
            }
            Pusat::hapusAnggota($target, $pt);
            $ok = 'Akses dicabut.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$daftar = Pusat::perusahaanAkun($akunId);

// PT yang sedang ditampilkan daftar penggunanya.
$ptAktif = null;
$pilih = Tenant::normalKode((string) ($_GET['pt'] ?? ''));
foreach ($daftar as $p) {
    if ($p['peran'] !== 'pemilik') {
        continue;
    }
    if ($pilih === '' || $p['kode'] === $pilih) {
        $ptAktif = $p;   // tanpa ?pt=, tampilkan PT pertama yang dimiliki
        break;
    }
}

render_head('Perusahaan', '');
?>
<h1>Perusahaan</h1>
<p class="sub">
  Masuk sebagai <b><?= e($akun['nama'] ?: $akun['email']) ?></b>
  (<code class="k"><?= e($akun['email']) ?></code>).
  Satu akun bisa memegang beberapa PT; datanya tetap terpisah di database
  masing-masing.
</p>

<?php if ($err !== null): ?><div class="alert bad"><b>Gagal.</b> <?= e($err) ?></div><?php endif; ?>
<?php if ($ok !== null): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>

<div class="card">
  <h2>PT yang bisa Anda buka</h2>
  <?php if ($daftar === []): ?>
    <p class="help">Belum ada. Tambahkan PT pertama lewat formulir di bawah.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Perusahaan</th><th>Kode</th><th>Database</th><th>Peran</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($daftar as $p): ?>
            <tr>
              <td>
                <?php if ($p['peran'] === 'pemilik'): ?>
                  <form method="post" style="display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                    <input type="hidden" name="aksi" value="ubah_nama">
                    <input type="hidden" name="pt" value="<?= e($p['kode']) ?>">
                    <input type="text" name="nama" value="<?= e($p['nama']) ?>" required
                           style="min-width:180px" aria-label="Nama PT">
                    <button class="btn ghost sm" type="submit">Simpan</button>
                  </form>
                <?php else: ?>
                  <b><?= e($p['nama']) ?></b>
                <?php endif; ?>
              </td>
              <td class="muted" style="font-family:ui-monospace,monospace;font-size:12px"><?= e($p['kode']) ?></td>
              <td class="muted" style="font-family:ui-monospace,monospace;font-size:12px">
                <?php if ($p['peran'] === 'pemilik'): ?>
                  <form method="post" style="display:flex;gap:6px;align-items:center"
                        onsubmit="return confirm('Pindahkan seluruh isi database <?= e($p['db_name']) ?> ke nama baru?\n\nSemua tabel dipindahkan lalu database lama dihapus. Pastikan tidak ada yang sedang mengunggah data.')">
                    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                    <input type="hidden" name="aksi" value="ubah_db">
                    <input type="hidden" name="pt" value="<?= e($p['kode']) ?>">
                    <input type="text" name="db" value="<?= e($p['db_name']) ?>" required
                           pattern="[A-Za-z0-9_-]{1,64}" aria-label="Nama database"
                           style="min-width:160px;font-family:ui-monospace,monospace;font-size:12px">
                    <button class="btn ghost sm" type="submit">Pindah</button>
                  </form>
                <?php else: ?>
                  <?= e($p['db_name']) ?>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $p['peran'] === 'staf' ? 'muted' : 'ok' ?>"><?= e($p['peran']) ?></span></td>
              <td style="text-align:right;white-space:nowrap">
                <?php if ($p['peran'] === 'pemilik'): ?>
                  <a class="btn ghost sm" href="?pt=<?= e($p['kode']) ?>">Pengguna</a>
                <?php endif; ?>
                <form method="post" action="pilih-perusahaan.php" style="display:inline">
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
    <p class="help" style="margin-top:10px;margin-bottom:0">
      Nama PT bebas diubah kapan saja. <b>Nama database</b> juga bisa diubah:
      seluruh tabelnya dipindahkan ke nama baru lalu database lama dihapus.
      Pemindahannya hanya mengubah catatan, bukan menyalin data, jadi cepat
      berapa pun besarnya &mdash; tapi lakukan saat tidak ada yang sedang
      mengunggah berkas.
    </p>
  <?php endif; ?>
</div>

<?php if ((int) $akun['is_super'] === 1): ?>
<div class="card" style="max-width:640px">
  <h2>Tambah PT</h2>
  <p class="help" style="margin-top:-4px">
    Databasenya dibuat dan diisi tabelnya secara otomatis. Anda menjadi pemiliknya,
    dan bisa menyerahkan pengelolaannya ke orang lain dengan menjadikannya pemilik.
  </p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <input type="hidden" name="aksi" value="tambah_pt">
    <div class="field" style="margin-bottom:12px">
      <label>Nama PT</label>
      <input type="text" name="nama" required placeholder="mis. PT Kopi Nusantara" style="width:100%">
    </div>
    <div class="field" style="margin-bottom:12px">
      <label>Kode (dipakai di tautan)</label>
      <input type="text" name="kode" required placeholder="mis. kopinusantara" style="width:100%"
             pattern="[a-zA-Z0-9_-]{2,40}">
    </div>
    <div class="field" style="margin-bottom:16px">
      <label>Nama database <span class="muted">(kosongkan untuk otomatis)</span></label>
      <input type="text" name="db" placeholder="ecommerce_&lt;kode&gt;" style="width:100%"
             pattern="[A-Za-z0-9_-]{1,64}">
    </div>
    <button class="btn" type="submit">Buat PT</button>
  </form>
</div>
<?php endif; ?>

<?php if ($ptAktif !== null): ?>
  <?php $anggota = Pusat::anggota((int) $ptAktif['id']); ?>
  <div class="card">
    <h2>Pengguna &mdash; <?= e($ptAktif['nama']) ?></h2>
    <p class="help" style="margin-top:-4px">
      Yang diatur di sini hanya <b>siapa yang boleh membuka PT ini</b>.
      Tab apa saja yang dia lihat di dalamnya diatur di menu
      <a href="users.php">Pengguna</a> setelah PT-nya dibuka.
    </p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Email</th><th>Nama</th><th>Peran</th><th>Terakhir masuk</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($anggota as $a): ?>
            <tr>
              <td style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($a['email']) ?></td>
              <td><?= e($a['nama']) ?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                  <input type="hidden" name="aksi" value="ubah_peran">
                  <input type="hidden" name="pt" value="<?= e($ptAktif['kode']) ?>">
                  <input type="hidden" name="akun_id" value="<?= (int) $a['akun_id'] ?>">
                  <select name="peran" onchange="this.form.submit()">
                    <?php foreach (Pusat::PERAN as $r): ?>
                      <option value="<?= e($r) ?>" <?= $a['peran'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td class="muted"><?= e($a['last_login_at'] ?? 'belum pernah') ?></td>
              <td style="text-align:right">
                <?php if ((int) $a['akun_id'] !== $akunId): ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('Cabut akses <?= e($a['email']) ?> dari <?= e($ptAktif['nama']) ?>?')">
                    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                    <input type="hidden" name="aksi" value="hapus_anggota">
                    <input type="hidden" name="pt" value="<?= e($ptAktif['kode']) ?>">
                    <input type="hidden" name="akun_id" value="<?= (int) $a['akun_id'] ?>">
                    <button class="btn ghost sm" type="submit">Cabut</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 style="margin-top:18px">Beri akses ke orang lain</h3>
    <form method="post" class="filters" style="align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
      <input type="hidden" name="aksi" value="tambah_anggota">
      <input type="hidden" name="pt" value="<?= e($ptAktif['kode']) ?>">
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" required placeholder="orang@perusahaan.com">
      </div>
      <div class="field">
        <label>Nama <span class="muted">(bila akun baru)</span></label>
        <input type="text" name="nama_anggota" placeholder="mis. Budi">
      </div>
      <div class="field">
        <label>Kata sandi awal <span class="muted">(bila akun baru)</span></label>
        <input type="password" name="password" minlength="8" autocomplete="new-password">
      </div>
      <div class="field">
        <label>Peran</label>
        <select name="peran">
          <?php foreach (Pusat::PERAN as $r): ?>
            <option value="<?= e($r) ?>" <?= $r === 'staf' ? 'selected' : '' ?>><?= e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit">Tambahkan</button>
    </form>
    <p class="help" style="margin-top:10px;margin-bottom:0">
      <b>pemilik</b> mengatur daftar pengguna PT ini &middot;
      <b>admin</b> memegang seluruh tab di dalam PT &middot;
      <b>staf</b> hanya tab yang dicentang di menu Pengguna.
      Email yang sudah terdaftar cukup diisi emailnya saja.
    </p>
  </div>
<?php endif; ?>

<p style="margin-top:16px"><a class="btn ghost" href="pilih-perusahaan.php">&larr; Pilih PT</a></p>
<?php render_foot(); ?>
