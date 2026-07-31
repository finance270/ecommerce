<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Pemeriksaan lingkungan server.
 *
 * Menjawab satu pertanyaan: bisakah aplikasi ini nanti menarik pembaruan
 * langsung dari GitHub? Untuk itu perlu tiga hal sekaligus:
 *
 *   1. folder aplikasinya berupa clone git (ada .git), bukan hasil unzip;
 *   2. container punya perintah `git`;
 *   3. PHP boleh menjalankan perintah luar (exec tidak dimatikan);
 *   4. foldernya bisa ditulis oleh pengguna web server;
 *   5. ada jalan keluar ke github.com.
 *
 * Halaman ini hanya MEMBACA - tidak mengubah apa pun.
 */

Auth::requireTab('dashboard');
if (!Auth::isAdmin()) {
    http_response_code(403);
    render_head('Akses ditolak', '');
    echo '<div class="alert bad">Halaman ini khusus admin.</div>';
    render_foot();
    exit;
}

$akar = dirname(__DIR__);

/** Apakah sebuah fungsi benar-benar bisa dipakai (tidak masuk disable_functions)? */
function fungsiAktif(string $nama): bool
{
    if (!function_exists($nama)) {
        return false;
    }
    $mati = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array($nama, $mati, true);
}

/** Jalankan perintah bila diizinkan; kembalikan [keluaran, kode]. */
function jalankan(string $cmd): array
{
    if (!fungsiAktif('exec')) {
        return ['exec dimatikan di php.ini', -1];
    }
    $out = [];
    $kode = 0;
    @exec($cmd . ' 2>&1', $out, $kode);
    return [trim(implode("\n", $out)), $kode];
}

$adaExec = fungsiAktif('exec');
$adaGitDir = is_dir($akar . '/.git');

[$versiGit, $kodeGit] = $adaExec ? jalankan('git --version') : ['-', -1];
$adaGit = $kodeGit === 0 && str_contains(strtolower($versiGit), 'git version');

$remote = $cabang = $commit = $statusKerja = '-';
if ($adaGit && $adaGitDir) {
    $q = escapeshellarg($akar);
    [$remote]  = jalankan("git -C {$q} remote get-url origin");
    [$cabang]  = jalankan("git -C {$q} rev-parse --abbrev-ref HEAD");
    [$commit]  = jalankan("git -C {$q} log -1 --pretty=format:'%h %ad %s' --date=short");
    [$kotor]   = jalankan("git -C {$q} status --porcelain");
    $statusKerja = $kotor === '' ? 'bersih' : substr_count($kotor, "\n") + 1 . ' berkas berubah';
}

$bisaTulis = is_writable($akar);
$pemilik   = function_exists('posix_getpwuid') && function_exists('fileowner')
    ? (posix_getpwuid((int) fileowner($akar))['name'] ?? '?') : '?';
$prosesOleh = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : (getenv('USER') ?: '?');

// Uji jalan keluar ke GitHub tanpa mengubah apa pun.
$jaringan = 'belum diuji';
$jaringanOk = false;
if (isset($_GET['uji'])) {
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'method' => 'HEAD',
        'header' => "User-Agent: ecommerce-analytics\r\n"]]);
    $mulai = microtime(true);
    // Diisi PHP saat permintaan menghasilkan header; disiapkan dulu supaya
    // pemeriksaan di bawah tidak menyentuh variabel yang belum ada.
    $http_response_header = [];
    $h = @file_get_contents('https://github.com', false, $ctx);
    $ms = (int) round((microtime(true) - $mulai) * 1000);
    if ($h !== false || !empty($http_response_header)) {
        $jaringanOk = true;
        $jaringan = 'terhubung (' . $ms . ' ms)';
    } else {
        $jaringan = 'tidak bisa menjangkau github.com';
    }
}

$siap = $adaGitDir && $adaGit && $adaExec && $bisaTulis;

// Alamat repo untuk perintah clone: pakai origin yang terbaca kalau memang
// sudah clone, selain itu alamat repo aplikasi ini.
$asalRepo = 'https://github.com/finance270/ecommerce.git';
if (preg_match('~^(https://|git@)~', $remote) === 1 && str_contains($remote, 'github.com')) {
    $asalRepo = $remote;
}

/** Perintah pindah dari folder biasa ke clone git, untuk path tertentu. */
function perintahClone(string $path, string $asal): string
{
    $path = rtrim(trim($path), '/');
    $potong = strrpos($path, '/');
    $induk = $potong !== false && $potong > 0 ? substr($path, 0, $potong) : '/';
    $nama = $potong !== false ? substr($path, $potong + 1) : $path;
    if ($nama === '') {
        $nama = 'ecommerce';
    }
    return "cd {$induk}\n"
        . "mv {$nama} {$nama}-lama\n"
        . "git clone {$asal} {$nama}\n"
        . "cp {$nama}-lama/.env {$nama}/ 2>/dev/null || true\n"
        . "cp -r {$nama}-lama/config {$nama}/ 2>/dev/null || true\n"
        . "cp -r {$nama}-lama/storage {$nama}/ 2>/dev/null || true";
}

render_head('Cek lingkungan', '');

/** Baris hasil pemeriksaan. */
function baris(string $nama, bool $ok, string $nilai, string $saran = ''): void
{
    ?>
    <tr>
      <td><?= e($nama) ?></td>
      <td><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'ya' : 'tidak' ?></span></td>
      <td class="muted" style="font-family:ui-monospace,monospace;font-size:12px"><?= e($nilai) ?></td>
      <td class="muted" style="font-size:12px"><?= $ok ? '' : $saran ?></td>
    </tr>
    <?php
}
?>
<h1>Cek lingkungan server</h1>
<p class="sub">
  Memeriksa apakah aplikasi ini bisa menarik pembaruan langsung dari GitHub.
  Halaman ini <b>hanya membaca</b>, tidak mengubah apa pun.
</p>

<div class="alert <?= $siap ? 'ok' : 'warn' ?>">
  <?php if ($siap): ?>
    <b>Siap.</b> Syarat untuk tombol &ldquo;tarik pembaruan dari GitHub&rdquo; sudah terpenuhi.
  <?php else: ?>
    <b>Belum siap.</b> Ada syarat yang belum terpenuhi &mdash; lihat kolom saran di bawah.
  <?php endif; ?>
</div>

<div class="card">
  <h2>Hasil pemeriksaan</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Yang diperiksa</th><th>Hasil</th><th>Nilai</th><th>Kalau belum</th></tr></thead>
      <tbody>
        <?php
        baris('Folder aplikasi berupa clone git', $adaGitDir, $akar . '/.git',
            'Foldernya hasil unzip. Perlu di-<i>clone</i> ulang dengan git supaya bisa ditarik pembaruannya.');
        baris('Container punya perintah git', $adaGit, $versiGit,
            'Pasang git di container, mis. tambahkan <code class="k">apk add git</code> / '
            . '<code class="k">apt-get install -y git</code> pada skrip start.');
        baris('PHP boleh menjalankan perintah luar', $adaExec,
            'disable_functions = ' . ((string) ini_get('disable_functions') ?: '(kosong)'),
            'Hapus <code class="k">exec</code> dari <code class="k">disable_functions</code> di php.ini.');
        baris('Folder aplikasi bisa ditulis', $bisaTulis,
            'pemilik: ' . $pemilik . ' · proses PHP: ' . $prosesOleh,
            'Samakan pemilik folder dengan pengguna web server, atau beri izin tulis.');
        ?>
        <tr>
          <td>Bisa menjangkau github.com</td>
          <td>
            <?php if (isset($_GET['uji'])): ?>
              <span class="badge <?= $jaringanOk ? 'ok' : 'bad' ?>"><?= $jaringanOk ? 'ya' : 'tidak' ?></span>
            <?php else: ?>
              <span class="badge muted">?</span>
            <?php endif; ?>
          </td>
          <td class="muted" style="font-size:12px"><?= e($jaringan) ?></td>
          <td class="muted" style="font-size:12px">
            <?php if (!isset($_GET['uji'])): ?>
              <a class="btn ghost sm" href="?uji=1">Uji koneksi sekarang</a>
            <?php elseif (!$jaringanOk): ?>
              Container tidak punya akses internet keluar. Cek jaringan/proxy CasaOS.
            <?php endif; ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    Folder aplikasi yang dilihat PHP: <code class="k"><?= e($akar) ?></code>.
    Kalau di CasaOS foldernya dipasang (<i>bind mount</i>) dari path lain, path di host
    bisa berbeda dari yang tertulis di sini &mdash; yang menentukan tetap yang dilihat PHP.
  </p>
</div>

<?php if ($adaGitDir && $adaGit): ?>
<div class="card">
  <h2>Keterangan repositori</h2>
  <div class="table-wrap">
    <table>
      <tbody>
        <tr><td style="width:180px">Asal (origin)</td>
            <td style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($remote) ?></td></tr>
        <tr><td>Cabang aktif</td>
            <td style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($cabang) ?></td></tr>
        <tr><td>Commit terakhir</td>
            <td style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($commit) ?></td></tr>
        <tr><td>Perubahan lokal</td>
            <td style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($statusKerja) ?></td></tr>
      </tbody>
    </table>
  </div>
  <p class="help" style="margin-top:10px">
    Kalau <b>perubahan lokal</b> tidak &ldquo;bersih&rdquo;, artinya ada berkas yang diubah langsung
    di server. Menarik pembaruan bisa menimpanya, jadi perlu diputuskan dulu mau diapakan.
  </p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Kalau foldernya bukan clone git</h2>
  <p class="help" style="margin-top:-4px">
    Jalankan sekali di terminal <b>host</b> (bukan di dalam container), setelah menyalin dulu
    folder lama sebagai cadangan. Isi path folder aplikasi di host &mdash; perintahnya
    ikut menyesuaikan.
  </p>
  <div class="field" style="margin-bottom:12px">
    <label for="pathHost">Path folder aplikasi di host</label>
    <input type="text" id="pathHost" value="<?= e($akar) ?>"
           style="width:100%;max-width:420px;font-family:ui-monospace,monospace;font-size:12.5px">
  </div>
  <pre id="perintahClone" style="background:#f7f9fc;border:1px solid var(--line);border-radius:6px;
              padding:12px;overflow-x:auto;font-size:12.5px;margin:0"><?= e(perintahClone($akar, $asalRepo)) ?></pre>
  <p class="help" style="margin-top:10px">
    <code class="k">.env</code>, <code class="k">config/</code>, dan <code class="k">storage/</code>
    disalin balik karena isinya milik server Anda, bukan bagian dari repo.
    Setelah itu buka <a href="setup.php">Pemasangan</a> sekali.
  </p>
</div>

<script>
(function () {
  var isi = document.getElementById('pathHost');
  var kotak = document.getElementById('perintahClone');
  var asal = <?= json_encode($asalRepo, JSON_UNESCAPED_SLASHES) ?>;
  if (!isi || !kotak) return;

  function susun() {
    // Path dipecah jadi folder induk + nama folder supaya perintahnya tetap
    // terbaca (cd ke induk, ganti nama yang lama, clone dengan nama yang sama).
    var path = (isi.value || '').trim().replace(/\/+$/, '');
    var potong = path.lastIndexOf('/');
    var induk = potong > 0 ? path.slice(0, potong) : '/';
    var nama = potong >= 0 ? path.slice(potong + 1) : path;
    if (nama === '') { nama = 'ecommerce'; }
    kotak.textContent =
      'cd ' + induk + '\n' +
      'mv ' + nama + ' ' + nama + '-lama\n' +
      'git clone ' + asal + ' ' + nama + '\n' +
      'cp ' + nama + '-lama/.env ' + nama + '/ 2>/dev/null || true\n' +
      'cp -r ' + nama + '-lama/config ' + nama + '/ 2>/dev/null || true\n' +
      'cp -r ' + nama + '-lama/storage ' + nama + '/ 2>/dev/null || true';
  }

  isi.addEventListener('input', susun);
  susun();
})();
</script>

<p style="margin-top:16px"><a class="btn ghost" href="index.php">&larr; Kembali</a></p>
<?php render_foot(); ?>
