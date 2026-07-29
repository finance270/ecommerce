<?php
declare(strict_types=1);

/**
 * Impor HPP (harga pokok penjualan) per produk per bulan, dan beban
 * operasional per bulan.
 *
 * Menerima berkas .xlsx maupun .csv. Sama seperti impor berkas platform,
 * proses ini idempotent: baris dikunci UNIQUE pada (periode + kunci baris)
 * dan dibandingkan lewat row_hash, jadi berkas yang sama boleh diunggah
 * berulang tanpa membuat data dobel.
 */
final class CostImporter
{
    /** Nama kolom yang diterima untuk tiap field (huruf kecil). */
    private const COST_COLUMNS = [
        'period_ym'     => ['periode', 'periode (yyyy-mm)', 'period', 'bulan', 'month'],
        'product_name'  => ['nama produk', 'produk', 'product name', 'product', 'nama'],
        'variation'     => ['variasi', 'variation', 'varian'],
        'sku'           => ['sku', 'kode sku', 'seller sku'],
        'cost_per_unit' => ['hpp per unit', 'hpp', 'harga pokok', 'harga pokok per unit', 'cost', 'cost per unit', 'modal'],
        'note'          => ['catatan', 'note', 'keterangan'],
    ];

    private const EXPENSE_COLUMNS = [
        'period_ym'   => ['periode', 'periode (yyyy-mm)', 'period', 'bulan', 'month'],
        'category'    => ['kategori', 'category', 'jenis', 'jenis beban'],
        'description' => ['keterangan', 'deskripsi', 'description', 'rincian'],
        'amount'      => ['jumlah', 'nominal', 'amount', 'biaya', 'nilai'],
    ];

    /** @return array{upload_id:int,label:string,totals:array,problems:string[]} */
    public function importCost(string $path, string $originalName, ?int $userId = null): array
    {
        return $this->run($path, $originalName, $userId, 'hpp');
    }

    /** @return array{upload_id:int,label:string,totals:array,problems:string[]} */
    public function importExpense(string $path, string $originalName, ?int $userId = null): array
    {
        return $this->run($path, $originalName, $userId, 'beban');
    }

    private function run(string $path, string $originalName, ?int $userId, string $kind): array
    {
        $started = microtime(true);
        $label = $kind === 'hpp' ? 'HPP per produk' : 'Beban operasional';

        $uploadId = $this->createUpload($originalName, $path, $kind, $userId);

        try {
            $rows = $this->readRows($path);
            $map  = $kind === 'hpp' ? self::COST_COLUMNS : self::EXPENSE_COLUMNS;
            [$hIdx, $startRow] = $this->findHeader($rows, $map, $kind === 'hpp' ? 'cost_per_unit' : 'amount');

            $result = $kind === 'hpp'
                ? $this->loadCosts($rows, $hIdx, $startRow, $uploadId)
                : $this->loadExpenses($rows, $hIdx, $startRow, $uploadId);
        } catch (Throwable $e) {
            $this->finishUpload($uploadId, 'failed', ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0], $e->getMessage(), (int) ((microtime(true) - $started) * 1000));
            throw $e;
        }

        $this->finishUpload(
            $uploadId,
            'success',
            $result['totals'],
            $result['problems'] === [] ? null : implode(' | ', array_slice($result['problems'], 0, 20)),
            (int) ((microtime(true) - $started) * 1000)
        );

        return [
            'upload_id' => $uploadId,
            'label'     => $label,
            'totals'    => $result['totals'],
            'problems'  => $result['problems'],
        ];
    }

    // -----------------------------------------------------------------
    // Membaca berkas
    // -----------------------------------------------------------------

    /** @return array<int,array<int,string>> */
    private function readRows(string $path): array
    {
        if (preg_match('/\.csv$/i', $path) === 1 || $this->looksLikeCsv($path)) {
            return $this->readCsv($path);
        }

        $reader = new XlsxReader($path);
        $names = $reader->sheetNames();
        if ($names === []) {
            throw new RuntimeException('Berkas tidak memuat lembar kerja.');
        }
        $out = [];
        foreach ($reader->rows($names[0]) as $n => $row) {
            $out[$n] = $row;
        }
        return $out;
    }

    private function looksLikeCsv(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 4);
        fclose($fh);
        return $head !== '' && substr($head, 0, 2) !== 'PK'; // xlsx selalu diawali 'PK'
    }

    /** @return array<int,array<int,string>> */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Berkas CSV tidak dapat dibaca.');
        }
        $first = (string) fgets($fh);
        // Buang BOM UTF-8 agar judul kolom pertama tetap cocok.
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($fh);

        $rows = [];
        $n = 0;
        while (($cells = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $n++;
            if ($n === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? $cells[0];
            }
            $vals = [];
            foreach ($cells as $i => $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $vals[$i] = $c;
                }
            }
            if ($vals !== []) {
                $rows[$n] = $vals;
            }
        }
        fclose($fh);
        return $rows;
    }

    /**
     * @param array<int,array<int,string>> $rows
     * @return array{0:array<string,int>,1:int} peta field=>index kolom, nomor baris judul
     */
    private function findHeader(array $rows, array $map, string $requiredField): array
    {
        foreach ($rows as $n => $row) {
            $found = [];
            foreach ($row as $idx => $label) {
                $key = Profiles::normLabel((string) $label);
                foreach ($map as $field => $aliases) {
                    if (!isset($found[$field]) && in_array($key, $aliases, true)) {
                        $found[$field] = $idx;
                    }
                }
            }
            if (isset($found['period_ym'], $found[$requiredField])) {
                return [$found, $n];
            }
        }

        $needed = implode(', ', array_map(
            static fn(array $a): string => $a[0],
            [$map['period_ym'], $map[$requiredField]]
        ));
        throw new RuntimeException(
            "Judul kolom tidak ditemukan. Berkas wajib memuat kolom: {$needed}. "
            . 'Gunakan template yang disediakan agar formatnya pasti cocok.'
        );
    }

    // -----------------------------------------------------------------
    // HPP
    // -----------------------------------------------------------------

    private function loadCosts(array $rows, array $hIdx, int $headerRow, int $uploadId): array
    {
        $totals = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $problems = [];
        $batch = [];

        foreach ($rows as $n => $row) {
            if ($n <= $headerRow) {
                continue;
            }
            $get = fn(string $f): ?string => isset($hIdx[$f]) ? Value::text($row[$hIdx[$f]] ?? null, 512) : null;

            $period = Value::periodYm($get('period_ym'));
            $name   = $get('product_name');
            $rawCost = isset($hIdx['cost_per_unit']) ? ($row[$hIdx['cost_per_unit']] ?? null) : null;

            if ($period === null && $name === null && ($rawCost === null || trim((string) $rawCost) === '')) {
                continue; // baris kosong / keterangan
            }
            $totals['read']++;

            if ($period === null) {
                $totals['skipped']++;
                $problems[] = "Baris {$n}: periode kosong atau tidak dikenali";
                continue;
            }
            if ($name === null) {
                $totals['skipped']++;
                $problems[] = "Baris {$n}: nama produk kosong";
                continue;
            }
            if ($rawCost === null || trim((string) $rawCost) === '') {
                $totals['skipped']++;
                continue; // belum diisi, wajar untuk template
            }

            $cost = Value::money($rawCost);
            if ($cost < 0) {
                $totals['skipped']++;
                $problems[] = "Baris {$n}: HPP negatif ({$rawCost})";
                continue;
            }

            $variation = $get('variation');
            $rec = [
                'period_ym'     => $period,
                'cost_key'      => Value::costKey($name, $variation),
                'product_name'  => mb_substr($name, 0, 512),
                'variation'     => $variation !== null ? mb_substr($variation, 0, 255) : null,
                'sku'           => $get('sku') !== null ? mb_substr((string) $get('sku'), 0, 128) : null,
                'cost_per_unit' => round($cost, 2),
                'note'          => $get('note') !== null ? mb_substr((string) $get('note'), 0, 255) : null,
            ];
            $rec['row_hash'] = sha1(json_encode($rec, JSON_UNESCAPED_UNICODE) ?: '');
            $rec['upload_id'] = $uploadId;
            $batch[$period . '|' . $rec['cost_key']] = $rec;  // baris terakhir menang bila kembar
        }

        $this->flush('product_cost', $batch, 'period_ym', 'cost_key', $totals);
        return ['totals' => $totals, 'problems' => $problems];
    }

    // -----------------------------------------------------------------
    // Beban operasional
    // -----------------------------------------------------------------

    private function loadExpenses(array $rows, array $hIdx, int $headerRow, int $uploadId): array
    {
        $totals = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $problems = [];
        $batch = [];

        foreach ($rows as $n => $row) {
            if ($n <= $headerRow) {
                continue;
            }
            $get = fn(string $f): ?string => isset($hIdx[$f]) ? Value::text($row[$hIdx[$f]] ?? null, 255) : null;

            $period = Value::periodYm($get('period_ym'));
            $cat    = $get('category');
            $rawAmt = isset($hIdx['amount']) ? ($row[$hIdx['amount']] ?? null) : null;

            if ($period === null && $cat === null && ($rawAmt === null || trim((string) $rawAmt) === '')) {
                continue;
            }
            $totals['read']++;

            if ($period === null) {
                $totals['skipped']++;
                $problems[] = "Baris {$n}: periode kosong atau tidak dikenali";
                continue;
            }
            if ($cat === null) {
                $totals['skipped']++;
                $problems[] = "Baris {$n}: kategori kosong";
                continue;
            }
            if ($rawAmt === null || trim((string) $rawAmt) === '') {
                $totals['skipped']++;
                continue;
            }

            $desc = $get('description') ?? '';
            $rec = [
                'period_ym'   => $period,
                'category'    => mb_substr($cat, 0, 64),
                'description' => mb_substr($desc, 0, 255),
                'amount'      => round(abs(Value::money($rawAmt)), 2),  // beban selalu dicatat positif
                'expense_key' => Value::expenseKey($cat, $desc),
            ];
            $rec['row_hash'] = sha1(json_encode($rec, JSON_UNESCAPED_UNICODE) ?: '');
            $rec['upload_id'] = $uploadId;
            $batch[$period . '|' . $rec['expense_key']] = $rec;
        }

        $this->flush('operating_expense', $batch, 'period_ym', 'expense_key', $totals);
        return ['totals' => $totals, 'problems' => $problems];
    }

    // -----------------------------------------------------------------

    /** Bandingkan row_hash lalu tulis hanya yang baru/berubah. */
    private function flush(string $table, array $batch, string $col1, string $col2, array &$totals): void
    {
        if ($batch === []) {
            return;
        }
        $pdo = Db::conn();

        $existing = [];
        foreach (array_chunk(array_keys($batch), 400) as $chunk) {
            $conds = implode(' OR ', array_fill(0, count($chunk), "({$col1} = ? AND {$col2} = ?)"));
            $args = [];
            foreach ($chunk as $k) {
                [$a, $b] = explode('|', $k, 2);
                array_push($args, $a, $b);
            }
            $st = $pdo->prepare("SELECT {$col1} AS a, {$col2} AS b, row_hash FROM `{$table}` WHERE {$conds}");
            $st->execute($args);
            foreach ($st as $r) {
                $existing[$r['a'] . '|' . $r['b']] = $r['row_hash'];
            }
        }

        $write = [];
        foreach ($batch as $key => $rec) {
            if (!isset($existing[$key])) {
                $write[] = $rec;
                $totals['inserted']++;
            } elseif ($existing[$key] !== $rec['row_hash']) {
                $write[] = $rec;
                $totals['updated']++;
            } else {
                $totals['unchanged']++;
            }
        }

        $pdo->beginTransaction();
        try {
            foreach (array_chunk($write, 300) as $chunk) {
                $this->bulkUpsert($table, $chunk);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function bulkUpsert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $cols = array_keys($rows[0]);
        $colSql = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $cols));
        $rowPh = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

        $params = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $params[] = $r[$c] ?? null;
            }
        }
        $updates = [];
        foreach ($cols as $c) {
            if ($c !== 'first_seen_at') {
                $updates[] = "`{$c}` = VALUES(`{$c}`)";
            }
        }

        Db::conn()->prepare(
            "INSERT INTO `{$table}` ({$colSql}) VALUES "
            . implode(',', array_fill(0, count($rows), $rowPh))
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        )->execute($params);
    }

    private function createUpload(string $name, string $path, string $kind, ?int $userId): int
    {
        Db::q(
            'INSERT INTO uploads (original_name, file_hash, size_bytes, platform, dataset, status, uploaded_by)
             VALUES (?,?,?,?,?,?,?)',
            [$name, hash_file('sha256', $path) ?: '', filesize($path) ?: 0, null, $kind, 'pending', $userId]
        );
        return (int) Db::conn()->lastInsertId();
    }

    private function finishUpload(int $id, string $status, array $t, ?string $message, int $ms): void
    {
        Db::q(
            'UPDATE uploads SET status=?, rows_read=?, rows_inserted=?, rows_updated=?, rows_unchanged=?,
             rows_skipped=?, duration_ms=?, message=?, finished_at=NOW() WHERE id=?',
            [
                $status, $t['read'], $t['inserted'], $t['updated'], $t['unchanged'],
                $t['skipped'], $ms, $message !== null ? mb_substr($message, 0, 2000) : null, $id,
            ]
        );
    }
}
