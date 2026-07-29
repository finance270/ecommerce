<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$user = Auth::requireTab('upload');

@ini_set('memory_limit', '768M');
@set_time_limit(900);

$results = [];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $errors[] = ['name' => '-', 'msg' => 'Sesi kedaluwarsa. Muat ulang halaman lalu unggah lagi.'];
    } else {
        $force = (string) ($_POST['dataset'] ?? '');
        $force = $force !== '' ? $force : null;

        $storage = (string) Config::get('storage_dir');
        if (Config::get('keep_files') && !is_dir($storage)) {
            @mkdir($storage, 0775, true);
        }

        $files = $_FILES['files'] ?? null;
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;

        for ($i = 0; $i < $count; $i++) {
            $name = (string) $files['name'][$i];
            $tmp  = (string) $files['tmp_name'][$i];
            $code = (int) $files['error'][$i];

            if ($code !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                $errors[] = ['name' => $name, 'msg' => uploadErrorText($code)];
                continue;
            }
            if (!preg_match('/\.xlsx$/i', $name)) {
                $errors[] = ['name' => $name, 'msg' => 'Hanya berkas .xlsx (hasil ekspor asli) yang didukung.'];
                continue;
            }

            try {
                $res = (new Importer())->run($tmp, $name, (int) $user['id'], $force);

                if (Config::get('keep_files')) {
                    $safe = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                    if (@move_uploaded_file($tmp, rtrim($storage, '/') . '/' . $safe)) {
                        Db::q('UPDATE uploads SET stored_name = ? WHERE id = ?', [$safe, $res['upload_id']]);
                    }
                }
                $results[] = ['name' => $name, 'res' => $res];
            } catch (Throwable $e) {
                $errors[] = ['name' => $name, 'msg' => $e->getMessage()];
            }
        }

        if ($results === [] && $errors === []) {
            $errors[] = ['name' => '-', 'msg' => 'Tidak ada berkas yang dipilih.'];
        }
    }
}

function uploadErrorText(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'Ukuran berkas melebihi batas PHP. Naikkan upload_max_filesize dan post_max_size (lihat README).',
        UPLOAD_ERR_PARTIAL   => 'Berkas hanya terkirim sebagian, silakan ulangi.',
        UPLOAD_ERR_NO_FILE   => 'Tidak ada berkas terpilih.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara PHP tidak tersedia di container.',
        UPLOAD_ERR_CANT_WRITE => 'PHP gagal menulis berkas sementara.',
        default              => 'Berkas gagal diunggah (kode ' . $code . ').',
    };
}

render_head('Upload Data', 'upload');
?>
<h1>Upload Data Ekspor</h1>
<p class="sub">
  Unggah berkas <b>.xlsx</b> hasil ekspor Tokopedia atau Shopee. Jenis berkas dikenali otomatis.
  Berkas yang sama boleh diunggah berulang kali &mdash; data tidak akan dobel.
</p>

<?php foreach ($errors as $er): ?>
  <div class="alert bad"><b><?= e($er['name']) ?>:</b> <?= e($er['msg']) ?></div>
<?php endforeach; ?>

<?php foreach ($results as $r):
    $t = $r['res']['totals'];
    $baru = $t['inserted'];
    $ubah = $t['updated'];
    $sama = $t['unchanged'];
    $tone = $baru > 0 ? 'ok' : ($ubah > 0 ? 'warn' : 'info');
    ?>
  <div class="alert <?= $tone ?>">
    <b><?= e($r['name']) ?></b> &mdash; dikenali sebagai <b><?= e($r['res']['label']) ?></b>.<br>
    <?= num($baru) ?> data baru ditambahkan,
    <?= num($ubah) ?> data diperbarui,
    <?= num($sama) ?> data sudah sama (dilewati, tidak dobel).
    <?php if ($t['skipped'] > 0): ?>
      <?= num($t['skipped']) ?> baris dilewati (baris keterangan/kosong).
    <?php endif; ?>
    <div class="table-wrap" style="margin-top:10px">
      <table>
        <thead><tr>
          <th>Bagian</th><th class="num">Dibaca</th><th class="num">Baru</th>
          <th class="num">Diperbarui</th><th class="num">Sudah sama</th><th class="num">Dilewati</th>
        </tr></thead>
        <tbody>
        <?php foreach ($r['res']['sheets'] as $sheet => $s): ?>
          <tr>
            <td><?= e($sheet) ?></td>
            <td class="num"><?= num($s['read']) ?></td>
            <td class="num"><?= num($s['inserted']) ?></td>
            <td class="num"><?= num($s['updated']) ?></td>
            <td class="num"><?= num($s['unchanged']) ?></td>
            <td class="num"><?= num($s['skipped']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data" id="uploadform">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
    <div class="drop" id="drop">
      <div class="big">Klik di sini atau seret berkas ke area ini</div>
      <div class="muted">Bisa memilih beberapa berkas sekaligus (.xlsx)</div>
      <input type="file" name="files[]" id="files" multiple accept=".xlsx">
    </div>
    <div class="filelist" id="filelist"></div>

    <div class="filters" style="margin-top:16px">
      <div class="field">
        <label>Jenis berkas</label>
        <select name="dataset">
          <option value="">Deteksi otomatis (disarankan)</option>
          <option value="tokopedia|order">Tokopedia &mdash; Semua Pesanan</option>
          <option value="tokopedia|settlement">Tokopedia &mdash; Transaksi / Penghasilan</option>
          <option value="shopee|order">Shopee &mdash; Order</option>
          <option value="shopee|settlement">Shopee &mdash; Laporan Penghasilan</option>
        </select>
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <button class="btn" type="submit" id="submitbtn">Unggah &amp; Proses</button>
      </div>
    </div>
  </form>
</div>

<div class="grid2">
  <div class="card">
    <h2>Berkas yang didukung</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Berkas ekspor</th><th>Isi yang diambil</th></tr></thead>
        <tbody>
          <tr>
            <td><b>Tokopedia &mdash; Semua Pesanan</b><br><span class="muted">sheet OrderSKUList</span></td>
            <td>Pesanan + rincian SKU, status, alamat, ongkir, kurir</td>
          </tr>
          <tr>
            <td><b>Tokopedia &mdash; Transaksi</b><br><span class="muted">sheet Detail pesanan</span></td>
            <td>Settlement per pesanan, seluruh komponen biaya, riwayat penarikan dana</td>
          </tr>
          <tr>
            <td><b>Shopee &mdash; Order</b><br><span class="muted">sheet orders</span></td>
            <td>Pesanan + rincian produk, status, alamat, ongkir</td>
          </tr>
          <tr>
            <td><b>Shopee &mdash; Laporan Penghasilan</b><br><span class="muted">sheet Income</span></td>
            <td>Penghasilan per pesanan, biaya administrasi/layanan, rincian biaya layanan</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Cara kerja anti-dobel</h2>
    <ul class="help">
      <li>Setiap pesanan dikunci dengan <b>platform + nomor pesanan</b>, setiap baris produk dengan
        <b>nomor pesanan + SKU/varian</b>, dan setiap settlement dengan
        <b>nomor pesanan + jenis transaksi + tanggal dana dilepaskan</b>.</li>
      <li>Kunci tersebut dipasang <code class="k">UNIQUE</code> di database, jadi baris yang sama
        tidak mungkin masuk dua kali walau berkas diunggah berulang.</li>
      <li>Aplikasi menyimpan sidik jari (<i>hash</i>) tiap baris. Saat unggah ulang:
        isi sama &rarr; dilewati; isi berubah (mis. status jadi <i>Dibatalkan</i>) &rarr; baris diperbarui.</li>
      <li>Karena itu berkas periode panjang (mis. Januari&ndash;Juni) aman diunggah tiap minggu:
        yang lama tetap, yang baru bertambah, yang berubah ikut terkoreksi.</li>
    </ul>
  </div>
</div>
<?php render_foot(); ?>
