<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/_layout.php';

/**
 * Potongan halaman HPP yang perhitungannya berat (menelusuri seluruh baris
 * produk yang terjual). Diambil terpisah supaya halaman utama langsung tampil.
 */

if (Auth::user() === null) {
    http_response_code(403);
    echo '<p class="muted">Sesi berakhir. <a href="login.php">Masuk lagi</a>.</p>';
    exit;
}
if (!Auth::can('costs')) {
    http_response_code(403);
    echo '<p class="muted">Akun Anda tidak berhak membuka bagian ini.</p>';
    exit;
}

@set_time_limit(300);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$ym = q('ym');
if ($ym !== null && preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
    $ym = null;
}
$section = q('section');

if ($section === 'kurang') {
    $missing = Reports::missingCosts($ym, 300);
    ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Bulan</th><th>Produk</th><th>Variasi</th><th class="num">Qty terjual</th><th class="num">Nilai bersih</th></tr></thead>
        <tbody>
        <?php foreach ($missing as $m): ?>
          <tr>
            <td class="nowrap"><?= e($m['period_ym']) ?></td>
            <td class="trunc" title="<?= e($m['produk']) ?>"><?= e($m['produk']) ?></td>
            <td><?= e($m['variasi'] !== '' ? $m['variasi'] : '-') ?></td>
            <td class="num"><?= num($m['qty']) ?></td>
            <td class="num"><?= rp($m['nilai_bersih']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($missing === []): ?>
          <tr><td colspan="5" class="pos">Semua produk yang terjual sudah punya HPP.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    exit;
}

if ($section === 'cek') {
    $minPct = (float) (q('min') ?? Reports::MARJIN_MIN);
    $maxPct = (float) (q('max') ?? Reports::MARJIN_MAX);
    $badPct = (float) (q('bad') ?? 100);
    $minPct = max(0.0, min(100.0, $minPct));
    $maxPct = max($minPct, min(100.0, $maxPct));
    $badPct = max($maxPct, min(200.0, $badPct));

    $cek = Reports::costMarginCheck($ym, platformFilter(), $minPct, $maxPct, $badPct);
    $ringkas = ['wajar' => 0, 'tipis' => 0, 'periksa' => 0, 'parah' => 0, 'rugi' => 0];
    foreach ($cek as $c) {
        $ringkas[$c['status']]++;
    }
    ?>
    <div class="kpis" style="margin-bottom:14px">
      <div class="kpi ok">
        <div class="label">Wajar</div>
        <div class="value"><?= num($ringkas['wajar']) ?></div>
        <div class="hint">marjin <?= number_format($minPct, 0, ',', '.') ?>&ndash;<?= number_format($maxPct, 0, ',', '.') ?>%</div>
      </div>
      <div class="kpi <?= $ringkas['tipis'] > 0 ? 'bad' : '' ?>">
        <div class="label">Marjin tipis</div>
        <div class="value"><?= num($ringkas['tipis']) ?></div>
        <div class="hint">di bawah <?= number_format($minPct, 0, ',', '.') ?>%</div>
      </div>
      <div class="kpi <?= $ringkas['periksa'] > 0 ? 'bad' : '' ?>">
        <div class="label">Perlu dicek</div>
        <div class="value"><?= num($ringkas['periksa']) ?></div>
        <div class="hint">di atas <?= number_format($maxPct, 0, ',', '.') ?>%</div>
      </div>
      <div class="kpi <?= $ringkas['parah'] > 0 ? 'bad' : '' ?>">
        <div class="label">Sangat tidak wajar</div>
        <div class="value"><?= num($ringkas['parah']) ?></div>
        <div class="hint">marjin &ge; <?= number_format($badPct, 0, ',', '.') ?>%</div>
      </div>
      <div class="kpi <?= $ringkas['rugi'] > 0 ? 'bad' : '' ?>">
        <div class="label">Jual rugi</div>
        <div class="value"><?= num($ringkas['rugi']) ?></div>
        <div class="hint">HPP melebihi pendapatan</div>
      </div>
    </div>

    <form method="get" class="filters" style="margin-bottom:12px" action="costs.php">
      <?php if ($ym !== null): ?><input type="hidden" name="ym" value="<?= e($ym) ?>"><?php endif; ?>
      <div class="field">
        <label>Marjin wajar minimal (%)</label>
        <input type="number" name="min" value="<?= e((string) $minPct) ?>" min="0" max="100" step="1">
      </div>
      <div class="field">
        <label>Marjin wajar maksimal (%)</label>
        <input type="number" name="max" value="<?= e((string) $maxPct) ?>" min="0" max="100" step="1">
      </div>
      <div class="field">
        <label>Batas sangat tidak wajar (%)</label>
        <input type="number" name="bad" value="<?= e((string) $badPct) ?>" min="0" max="200" step="1">
      </div>
      <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Terapkan</button></div>
    </form>

    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Status</th><th>Bulan</th><th>Produk</th><th>Variasi</th>
          <th class="num">Qty</th><th class="num">HPP/unit</th><th class="num">Bersih/unit</th>
          <th class="num">Marjin</th><th>Catatan</th><th></th>
        </tr></thead>
        <tbody>
        <?php
        $badge = ['wajar' => 'ok', 'tipis' => 'warn', 'periksa' => 'warn', 'parah' => 'bad', 'rugi' => 'bad'];
        $teks  = ['wajar' => 'Wajar', 'tipis' => 'Marjin tipis', 'periksa' => 'Perlu dicek',
                  'parah' => 'Sangat tidak wajar', 'rugi' => 'Jual rugi'];
        foreach (array_slice($cek, 0, 300) as $c):
            $m = $c['marjin']; ?>
          <tr>
            <td><span class="badge <?= $badge[$c['status']] ?>"><?= $teks[$c['status']] ?></span></td>
            <td class="nowrap"><?= e($c['period_ym']) ?></td>
            <td class="trunc" title="<?= e($c['produk']) ?> &mdash; klik untuk melihat rinciannya">
              <a href="product.php?<?= e(http_build_query([
                  'key' => $c['cost_key'], 'ym' => $c['period_ym'], 'platform' => platformFilter(),
              ])) ?>"><?= e($c['produk']) ?></a>
            </td>
            <td><?= e($c['variasi'] !== '' ? $c['variasi'] : '-') ?></td>
            <td class="num"><?= num($c['qty']) ?></td>
            <td class="num"><?= rp($c['hpp_unit']) ?></td>
            <td class="num"><?= rp($c['bersih_unit']) ?></td>
            <td class="num <?= $c['status'] === 'wajar' ? '' : 'neg' ?>">
              <?= $m === null ? '-' : number_format($m, 1, ',', '.') . '%' ?>
            </td>
            <td class="muted"><?= e($c['alasan']) ?></td>
            <td class="nowrap">
              <?php if ($c['status'] !== 'wajar'): ?>
                <a class="btn ghost sm" target="_blank" rel="noopener"
                   href="simulasi.php?<?= e(http_build_query(array_filter([
                    'key' => $c['cost_key'], 'platform' => platformFilter(),
                ], static fn($v) => $v !== null && $v !== ''))) ?>">Simulasi harga</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($cek === []): ?>
          <tr><td colspan="10" class="muted">Belum ada produk yang HPP-nya terisi pada rentang ini.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($cek) > 300): ?>
      <p class="help" style="margin-top:10px">
        Menampilkan 300 dari <?= num(count($cek)) ?> baris. Gunakan Ekspor CSV untuk daftar lengkap.
      </p>
    <?php endif; ?>
    <?php
    exit;
}

http_response_code(400);
echo '<p class="muted">Bagian tidak dikenal.</p>';
