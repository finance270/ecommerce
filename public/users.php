<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$me = Auth::requireTab('users');   // hanya admin

$errors = [];
$notes  = [];

/** Ambil daftar tab dari form, hanya yang dikenal. */
function tabsFromPost(): array
{
    $raw = $_POST['tabs'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    return array_values(array_intersect($raw, array_keys(Perm::TABS)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        $act = (string) ($_POST['act'] ?? '');
        $id  = (int) ($_POST['id'] ?? 0);

        try {
            if ($act === 'create') {
                $username = trim((string) ($_POST['username'] ?? ''));
                $pass     = (string) ($_POST['password'] ?? '');
                $name     = trim((string) ($_POST['full_name'] ?? ''));
                $role     = in_array($_POST['role'] ?? '', ['admin', 'staff', 'viewer'], true)
                    ? (string) $_POST['role'] : 'staff';
                $salary   = in_array($_POST['salary_access'] ?? '', ['all', 'only', 'none'], true)
                    ? (string) $_POST['salary_access'] : 'all';

                if ($username === '' || preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
                    throw new RuntimeException('Username 3-64 karakter, hanya huruf, angka, titik, garis bawah, atau strip.');
                }
                if (strlen($pass) < 8) {
                    throw new RuntimeException('Kata sandi minimal 8 karakter.');
                }
                if (Db::val('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
                    throw new RuntimeException('Username sudah dipakai.');
                }

                Db::q(
                    'INSERT INTO users (username, password_hash, full_name, role, permissions, salary_access)
                     VALUES (?,?,?,?,?,?)',
                    [
                        $username, password_hash($pass, PASSWORD_DEFAULT),
                        $name !== '' ? $name : $username, $role,
                        json_encode(tabsFromPost()), $salary,
                    ]
                );
                $notes[] = 'Pengguna ' . $username . ' dibuat.';
            } elseif ($act === 'update') {
                $target = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
                if ($target === null) {
                    throw new RuntimeException('Pengguna tidak ditemukan.');
                }

                $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'viewer'], true)
                    ? (string) $_POST['role'] : (string) $target['role'];
                // Akun direksi dibagikan lewat tautan terbuka dengan kata sandi
                // saja - menjadikannya admin berarti membuka seluruh aplikasi
                // di balik satu kata sandi pendek.
                if ($target['username'] === Perm::AKUN_DIREKSI && $role === 'admin') {
                    throw new RuntimeException('Akun direksi tidak boleh dijadikan admin.');
                }
                $salary = in_array($_POST['salary_access'] ?? '', ['all', 'only', 'none'], true)
                    ? (string) $_POST['salary_access'] : (string) $target['salary_access'];
                $aktif = isset($_POST['is_active']) ? 1 : 0;

                // Jangan sampai admin terakhir dinonaktifkan atau diturunkan.
                $adminLain = (int) Db::val(
                    "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id <> ?",
                    [$id],
                    0
                );
                if ($target['role'] === 'admin' && $adminLain === 0 && ($role !== 'admin' || $aktif === 0)) {
                    throw new RuntimeException('Ini satu-satunya admin aktif. Buat admin lain dulu sebelum mengubahnya.');
                }

                Db::q(
                    'UPDATE users SET full_name=?, role=?, permissions=?, salary_access=?, is_active=? WHERE id=?',
                    [
                        trim((string) ($_POST['full_name'] ?? '')) ?: $target['username'],
                        $role, json_encode(tabsFromPost()), $salary, $aktif, $id,
                    ]
                );

                $newPass = (string) ($_POST['password'] ?? '');
                if ($newPass !== '') {
                    if (strlen($newPass) < 8) {
                        throw new RuntimeException('Kata sandi baru minimal 8 karakter.');
                    }
                    Db::q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($newPass, PASSWORD_DEFAULT), $id]);
                    $notes[] = 'Kata sandi diperbarui.';
                }
                $notes[] = 'Pengaturan pengguna disimpan.';
            } elseif ($act === 'delete') {
                if ($id === (int) $me['id']) {
                    throw new RuntimeException('Anda tidak bisa menghapus akun sendiri.');
                }
                $target = Db::one('SELECT username, role FROM users WHERE id = ?', [$id]);
                if ($target === null) {
                    throw new RuntimeException('Pengguna tidak ditemukan.');
                }
                if ($target['username'] === Perm::AKUN_DIREKSI) {
                    throw new RuntimeException(
                        'Akun direksi tidak bisa dihapus. Nonaktifkan saja bila tautannya '
                        . 'tidak dipakai lagi.'
                    );
                }
                $adminLain = (int) Db::val(
                    "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id <> ?",
                    [$id],
                    0
                );
                if ($target['role'] === 'admin' && $adminLain === 0) {
                    throw new RuntimeException('Ini satu-satunya admin aktif, tidak bisa dihapus.');
                }
                Db::q('DELETE FROM users WHERE id = ?', [$id]);
                $notes[] = 'Pengguna ' . $target['username'] . ' dihapus.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$users = Db::all('SELECT * FROM users ORDER BY role, username');
$edit  = null;
$editId = (int) (q('edit') ?? 0);
if ($editId > 0) {
    $edit = Db::one('SELECT * FROM users WHERE id = ?', [$editId]);
}

/** @return string[] */
function tabsOf(?array $u): array
{
    if ($u === null) {
        return [];
    }
    if ($u['role'] === 'admin') {
        return array_keys(Perm::TABS);
    }
    $list = json_decode((string) ($u['permissions'] ?? ''), true);
    return is_array($list) ? $list : [];
}

render_head('Pengguna', 'users');
?>
<h1>Pengguna &amp; Hak Akses</h1>
<p class="sub">
  Mengatur siapa boleh membuka tab apa. Peran <b>admin</b> selalu punya seluruh akses dan
  merupakan satu-satunya peran yang boleh <b>menghapus</b> data.
</p>

<?php foreach ($errors as $er): ?><div class="alert bad"><?= e($er) ?></div><?php endforeach; ?>
<?php foreach ($notes as $n): ?><div class="alert ok"><?= e($n) ?></div><?php endforeach; ?>

<div class="card">
  <h2>Daftar pengguna</h2>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Username</th><th>Nama</th><th>Peran</th><th>Tab yang boleh dibuka</th>
        <th>Akses gaji</th><th>Status</th><th>Terakhir masuk</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): $t = tabsOf($u); ?>
        <tr>
          <td class="nowrap"><b><?= e($u['username']) ?></b></td>
          <td><?= e($u['full_name']) ?></td>
          <td><span class="badge <?= $u['role'] === 'admin' ? 'ok' : 'muted' ?>"><?= e($u['role']) ?></span></td>
          <td>
            <?php if ($u['role'] === 'admin'): ?>
              <span class="muted">seluruh tab</span>
            <?php elseif ($t === []): ?>
              <span class="badge bad">belum ada</span>
            <?php else: ?>
              <span class="muted" style="font-size:12px"><?= e(implode(', ', array_map(
                  static fn(string $k): string => Perm::TABS[$k][0] ?? $k, $t
              ))) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php $sa = $u['role'] === 'admin' ? 'all' : (string) $u['salary_access']; ?>
            <span class="badge <?= $sa === 'all' ? 'muted' : ($sa === 'only' ? 'info' : 'warn') ?>">
              <?= e(Perm::accessLabel($sa)) ?>
            </span>
          </td>
          <td><span class="badge <?= $u['is_active'] ? 'ok' : 'bad' ?>"><?= $u['is_active'] ? 'aktif' : 'nonaktif' ?></span></td>
          <td class="nowrap muted"><?= $u['last_login_at'] !== null ? e(date('d/m/Y H:i', strtotime((string) $u['last_login_at']))) : '-' ?></td>
          <td class="nowrap">
            <a class="btn ghost sm" href="?edit=<?= (int) $u['id'] ?>">Ubah</a>
            <?php if ((int) $u['id'] !== (int) $me['id']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus pengguna <?= e($u['username']) ?>? Tindakan ini tidak bisa dibatalkan.')">
                <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn ghost sm" type="submit">Hapus</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2><?= $edit !== null ? 'Ubah pengguna: ' . e($edit['username']) : 'Tambah pengguna baru' ?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <input type="hidden" name="act" value="<?= $edit !== null ? 'update' : 'create' ?>">
    <?php if ($edit !== null): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

    <div class="filters" style="margin-bottom:16px">
      <?php if ($edit === null): ?>
        <div class="field">
          <label>Username</label>
          <input type="text" name="username" required pattern="[A-Za-z0-9._-]{3,64}">
        </div>
      <?php endif; ?>
      <div class="field">
        <label>Nama lengkap</label>
        <input type="text" name="full_name" value="<?= e($edit['full_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Kata sandi <?= $edit !== null ? '(kosongkan bila tidak diubah)' : '' ?></label>
        <input type="password" name="password" <?= $edit === null ? 'required minlength="8"' : 'minlength="8"' ?>>
      </div>
      <div class="field">
        <label>Peran</label>
        <select name="role">
          <?php foreach (['staff' => 'Staff', 'viewer' => 'Viewer', 'admin' => 'Admin'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($edit['role'] ?? 'staff') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Akses kategori gaji (menu Beban)</label>
        <select name="salary_access">
          <?php foreach (['all', 'none', 'only'] as $k): ?>
            <option value="<?= $k ?>" <?= ($edit['salary_access'] ?? 'all') === $k ? 'selected' : '' ?>>
              <?= e(Perm::accessLabel($k)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($edit !== null): ?>
        <div class="field">
          <label>Status</label>
          <label style="font-weight:400;font-size:13px;text-transform:none;letter-spacing:0">
            <input type="checkbox" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>> Aktif
          </label>
        </div>
      <?php endif; ?>
    </div>

    <h3 style="font-size:14px;margin:0 0 8px">Tab yang boleh dibuka</h3>
    <p class="help" style="margin-top:0;margin-bottom:10px">
      Tidak berlaku untuk peran <b>admin</b> &mdash; admin selalu bisa membuka semuanya.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;margin-bottom:16px">
      <?php $checked = tabsOf($edit); foreach (Perm::TABS as $key => [$label, $file, $desc]): ?>
        <label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid var(--line);border-radius:7px">
          <input type="checkbox" name="tabs[]" value="<?= e($key) ?>" <?= in_array($key, $checked, true) ? 'checked' : '' ?>>
          <span>
            <b style="font-size:13px"><?= e($label) ?></b>
            <div class="muted" style="font-size:11.5px"><?= e($desc) ?></div>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <button class="btn" type="submit"><?= $edit !== null ? 'Simpan perubahan' : 'Buat pengguna' ?></button>
    <?php if ($edit !== null): ?>
      <a class="btn ghost" href="users.php">Batal</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>Cara kerja akses kategori gaji</h2>
  <ul class="help">
    <li><b>Semua kategori</b> &mdash; melihat dan mengunggah seluruh beban operasional.</li>
    <li><b>Hanya kategori gaji</b> &mdash; hanya melihat dan mengunggah baris bernama gaji.
      Baris kategori lain pada berkasnya akan dilewati saat impor.</li>
    <li><b>Tanpa kategori gaji</b> &mdash; melihat dan mengunggah semua kecuali gaji.
      Baris gaji pada berkasnya dilewati, dan angkanya tidak muncul di mana pun.</li>
  </ul>
  <p class="help">
    Kategori dianggap gaji bila namanya memuat salah satu kata:
    <?php foreach (Perm::SALARY_KEYWORDS as $k): ?><code class="k"><?= e($k) ?></code> <?php endforeach; ?>.
    Karena itu beri nama kategori dengan jelas, misalnya <i>Gaji Karyawan</i> atau <i>THR</i>.
  </p>
  <p class="help">
    Pembatasan ini juga membuat angka <b>beban operasional</b> dan <b>laba usaha</b> pada halaman
    Laba &amp; Biaya menyesuaikan hak akses masing-masing pengguna. Halaman tersebut memberi
    catatan bila ada nilai yang disembunyikan, supaya angkanya tidak salah dibaca sebagai final.
  </p>
</div>
<?php render_foot(); ?>
