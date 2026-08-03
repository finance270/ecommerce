<?php
declare(strict_types=1);

/**
 * Mesin import berkas ekspor Tokopedia / Shopee.
 *
 * Prinsip anti-dobel:
 *   1. Setiap baris punya KUNCI ALAMI yang stabil antar ekspor:
 *        - pesanan        : platform + order_id
 *        - baris SKU      : platform + order_id + line_key
 *        - settlement     : platform + trx_key (order + jenis + waktu settle)
 *        - penarikan dana : platform + reference_id
 *      Kunci itu dipasang UNIQUE di database, jadi dobel mustahil terjadi
 *      walaupun berkas yang sama di-upload berkali-kali.
 *   2. Setiap baris menyimpan row_hash (SHA-1 seluruh nilai terpetakan).
 *      Saat upload ulang: hash sama -> dilewati (tidak menyentuh database),
 *      hash beda -> baris di-UPDATE. Jadi data ikut terkoreksi ketika status
 *      pesanan berubah (misal "Selesai" jadi "Pengembalian") pada ekspor
 *      minggu berikutnya.
 */
final class Importer
{
    private int $batchOrders = 300;
    private ?int $uploadId = null;
    private array $stats = [];
    private array $touchedOrderIds = [];
    private ?string $periodFrom = null;
    private ?string $periodTo = null;

    public function __construct()
    {
        $this->batchOrders = max(50, (int) Config::get('batch_size', 400));
    }

    /**
     * @return array{upload_id:int,platform:string,dataset:string,label:string,sheets:array,totals:array}
     */
    public function run(string $filePath, string $originalName, ?int $userId = null, ?string $forceDataset = null): array
    {
        $started = microtime(true);
        $hash = hash_file('sha256', $filePath) ?: '';
        $size = filesize($filePath) ?: 0;

        $reader = new XlsxReader($filePath);

        $info = null;
        if ($forceDataset !== null && str_contains($forceDataset, '|')) {
            [$p, $d] = explode('|', $forceDataset, 2);
            $info = ['platform' => $p, 'dataset' => $d, 'label' => "{$p} {$d}"];
        }
        $info ??= Profiles::detect($reader);

        if ($info === null) {
            throw new RuntimeException(
                'Jenis berkas tidak dikenali. Pastikan berkas adalah hasil ekspor asli dari '
                . 'Tokopedia (Semua Pesanan / Transaksi) atau Shopee (Order / Laporan Penghasilan).'
            );
        }

        $this->uploadId = $this->createUploadRow($originalName, $hash, $size, $info, $userId);
        $this->stats = [];
        $this->touchedOrderIds = [];

        try {
            if ($info['dataset'] === 'order') {
                $this->importOrders($reader, $info['platform']);
            } else {
                $this->importSettlements($reader, $info['platform']);
            }
        } catch (Throwable $e) {
            $this->finishUpload('failed', $e->getMessage(), (int) ((microtime(true) - $started) * 1000));
            throw $e;
        }

        // Tanggal & status pesanan disalin ke tabel settlement setelah impor,
        // apa pun jenis berkasnya. Dengan begitu urutan unggah tidak jadi soal:
        // berkas penghasilan boleh lebih dulu, salinannya menyusul saat berkas
        // pesanannya masuk - dan sebaliknya.
        $this->syncOrderRef($info['platform']);

        $totals = $this->totals();
        $status = $totals['skipped'] > 0 && $totals['read'] === $totals['skipped'] ? 'partial' : 'success';
        $this->finishUpload($status, null, (int) ((microtime(true) - $started) * 1000));

        return [
            'upload_id' => (int) $this->uploadId,
            'platform'  => $info['platform'],
            'dataset'   => $info['dataset'],
            'label'     => $info['label'],
            'sheets'    => $this->stats,
            'totals'    => $totals,
        ];
    }

    // =================================================================
    // PESANAN
    // =================================================================

    private function importOrders(XlsxReader $reader, string $platform): void
    {
        $sheet = $platform === 'tokopedia' ? 'OrderSKUList' : 'orders';
        if (!$reader->hasSheet($sheet)) {
            foreach ($reader->sheetNames() as $s) {
                $sheet = $s;
                break;
            }
        }

        $headerMap = Profiles::orderHeaderMap($platform);
        $itemMap   = Profiles::orderItemMap($platform);
        $keyCols   = Profiles::itemKeyColumns($platform);
        $idLabel   = $platform === 'tokopedia' ? 'order id' : 'no. pesanan';
        $idPattern = $platform === 'tokopedia' ? '/^\d{6,}$/' : '/^[A-Za-z0-9\-]{6,}$/';

        $hIdx = null;
        $stat = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $itemStat = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        /** @var array<string,array{header:array,items:array,seq:array}> $buffer */
        $buffer = [];

        foreach ($reader->rows($sheet) as $row) {
            if ($hIdx === null) {
                $candidate = Profiles::headerIndex($row);
                if (isset($candidate[$idLabel])) {
                    $hIdx = $candidate;
                }
                continue;
            }

            $orderId = Value::text($row[$hIdx[$idLabel]] ?? null, 64);
            if ($orderId === null || preg_match($idPattern, $orderId) !== 1) {
                // Baris keterangan kolom (baris ke-2 pada ekspor Tokopedia) atau baris kosong.
                $stat['skipped']++;
                continue;
            }

            $stat['read']++;

            if (!isset($buffer[$orderId])) {
                $buffer[$orderId] = [
                    'header' => $this->mapRow($row, $hIdx, $headerMap),
                    'raw'    => Config::get('keep_raw') ? $this->rawRow($row, $hIdx) : null,
                    'items'  => [],
                    'seq'    => [],
                ];
            }

            $item = $this->mapRow($row, $hIdx, $itemMap);
            $item = $this->finaliseItem($platform, $item);

            $keyParts = [];
            foreach ($keyCols as $col) {
                $keyParts[] = isset($hIdx[$col]) ? (string) ($row[$hIdx[$col]] ?? '') : '';
            }
            $base = sha1(implode("\x1f", $keyParts));
            // Bila satu pesanan memuat produk+variasi yang persis sama lebih dari
            // sekali, urutan kemunculan dipakai agar kunci barisnya tetap unik.
            $occ = ($buffer[$orderId]['seq'][$base] ?? 0) + 1;
            $buffer[$orderId]['seq'][$base] = $occ;
            $item['line_key'] = $base . ($occ > 1 ? '-' . $occ : '');
            $item['line_no']  = count($buffer[$orderId]['items']) + 1;

            $buffer[$orderId]['items'][] = $item;
            $itemStat['read']++;

            if (count($buffer) >= $this->batchOrders) {
                $last = array_key_last($buffer);
                $tail = [$last => $buffer[$last]];   // pesanan terakhir mungkin belum lengkap
                unset($buffer[$last]);
                $this->flushOrders($platform, $buffer, $stat, $itemStat);
                $buffer = $tail;
            }
        }

        if ($hIdx === null) {
            throw new RuntimeException("Baris judul kolom tidak ditemukan pada sheet '{$sheet}'.");
        }
        if ($buffer !== []) {
            $this->flushOrders($platform, $buffer, $stat, $itemStat);
        }

        $this->recomputeOrderAggregates($platform, array_keys($headerMap));

        $this->stats['Pesanan (' . $sheet . ')'] = $stat;
        $this->stats['Baris produk'] = $itemStat;
    }

    /** @param array<string,array> $buffer */
    private function flushOrders(string $platform, array $buffer, array &$stat, array &$itemStat): void
    {
        if ($buffer === []) {
            return;
        }
        $pdo = Db::conn();
        $orderIds = array_keys($buffer);

        $pdo->beginTransaction();
        try {
            // --- header pesanan ---------------------------------------
            $existing = $this->fetchHashes(
                'SELECT order_id AS k, row_hash FROM orders WHERE platform = ? AND order_id IN (%s)',
                $platform,
                $orderIds
            );

            $insert = [];
            $update = [];
            foreach ($buffer as $orderId => $pack) {
                $orderId = (string) $orderId;
                $rowData = $this->buildOrderRow($platform, $orderId, $pack);
                $hash = $rowData['row_hash'];
                if (!isset($existing[$orderId])) {
                    $insert[] = $rowData;
                } elseif ($existing[$orderId] !== $hash) {
                    $update[] = $rowData;
                } else {
                    $stat['unchanged']++;
                }
                $this->trackPeriod($rowData['order_date'] ?? null);
            }

            if ($insert !== []) {
                $this->bulkUpsert('orders', $insert);
                $stat['inserted'] += count($insert);
            }
            if ($update !== []) {
                $this->bulkUpsert('orders', $update);
                $stat['updated'] += count($update);
            }

            // --- id pesanan untuk relasi baris produk ------------------
            $pkMap = [];
            foreach (array_chunk($orderIds, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare("SELECT id, order_id FROM orders WHERE platform = ? AND order_id IN ({$ph})");
                $st->execute(array_merge([$platform], $chunk));
                foreach ($st as $r) {
                    $pkMap[$r['order_id']] = (int) $r['id'];
                }
            }

            // --- arsip baris asli pesanan ------------------------------
            $rawRows = [];
            foreach ($buffer as $orderId => $pack) {
                $pk = $pkMap[(string) $orderId] ?? null;
                if ($pk !== null && $pack['raw'] !== null) {
                    $rawRows[] = [
                        'order_pk' => $pk,
                        'raw_json' => json_encode($pack['raw'], JSON_UNESCAPED_UNICODE),
                    ];
                }
            }
            foreach (array_chunk($rawRows, 200) as $chunk) {
                $this->bulkUpsert('order_raw', $chunk);
            }

            // --- baris produk -----------------------------------------
            $existingItems = [];
            foreach (array_chunk($orderIds, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare(
                    "SELECT order_id, line_key, row_hash FROM order_items WHERE platform = ? AND order_id IN ({$ph})"
                );
                $st->execute(array_merge([$platform], $chunk));
                foreach ($st as $r) {
                    $existingItems[$r['order_id'] . "\x1f" . $r['line_key']] = $r['row_hash'];
                }
            }

            $iIns = [];
            $iUpd = [];
            foreach ($buffer as $orderId => $pack) {
                $orderId = (string) $orderId;
                $pk = $pkMap[$orderId] ?? null;
                if ($pk === null) {
                    continue;
                }
                foreach ($pack['items'] as $item) {
                    $rowData = $this->buildItemRow($platform, $orderId, $pk, $pack['header'], $item);
                    $k = $orderId . "\x1f" . $item['line_key'];
                    if (!isset($existingItems[$k])) {
                        $iIns[] = $rowData;
                    } elseif ($existingItems[$k] !== $rowData['row_hash']) {
                        $iUpd[] = $rowData;
                    } else {
                        $itemStat['unchanged']++;
                    }
                }
            }

            foreach (array_chunk($iIns, 400) as $chunk) {
                $this->bulkUpsert('order_items', $chunk);
            }
            foreach (array_chunk($iUpd, 400) as $chunk) {
                $this->bulkUpsert('order_items', $chunk);
            }
            $itemStat['inserted'] += count($iIns);
            $itemStat['updated'] += count($iUpd);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($orderIds as $id) {
            $this->touchedOrderIds[(string) $id] = true;
        }
    }

    private function buildOrderRow(string $platform, string $orderId, array $pack): array
    {
        $h = $pack['header'];
        $statusRaw = $h['status_raw'] ?? null;

        $row = [
            'platform'    => $platform,
            'order_id'    => $orderId,
            'status_norm' => Profiles::normStatus((string) ($statusRaw ?? '')),
        ];

        foreach (self::ORDER_FIELDS as $f) {
            if (array_key_exists($f, $h)) {
                $row[$f] = $h[$f];
            }
        }

        $row['order_date'] = isset($h['created_at_pf']) && $h['created_at_pf'] !== null
            ? substr((string) $h['created_at_pf'], 0, 10)
            : null;

        if ($platform === 'tokopedia' && empty($row['channel'])) {
            $row['channel'] = 'Tokopedia';
        }
        if ($platform === 'shopee') {
            $row['channel'] = 'Shopee';
        }

        // Platform sesekali memperpanjang teksnya tanpa pemberitahuan - Shopee
        // pernah mengubah "Selesai" menjadi satu kalimat penuh. Dipotong di
        // sini supaya satu nilai kepanjangan tidak menggagalkan seluruh
        // berkas; penggolongan statusnya sendiri sudah dihitung di atas dari
        // teks yang utuh.
        foreach (self::PANJANG_TEKS as $f => $maks) {
            if (isset($row[$f]) && is_string($row[$f]) && mb_strlen($row[$f]) > $maks) {
                $row[$f] = mb_substr($row[$f], 0, $maks);
            }
        }

        $row['row_hash'] = sha1(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '');
        $row['upload_id'] = $this->uploadId;
        // Baris aslinya disimpan terpisah di tabel order_raw supaya tabel
        // orders tetap ramping saat laporan menjumlah ratusan ribu baris.
        return $row;
    }

    /** Batas panjang kolom teks pada tabel orders. */
    private const PANJANG_TEKS = [
        'status_raw'    => 255,
        'substatus'     => 64,
        'order_type'    => 32,
        'return_status' => 64,
        'cancel_by'     => 64,
        'cancel_reason' => 255,
        'channel'       => 32,
    ];

    private const ORDER_FIELDS = [
        'channel', 'status_raw', 'substatus', 'order_type', 'return_status', 'cancel_by', 'cancel_reason',
        'created_at_pf', 'paid_at', 'rts_at', 'shipped_at', 'delivered_at', 'completed_at', 'cancelled_at',
        'order_amount', 'discount_platform', 'voucher_seller', 'voucher_platform', 'coin_cashback',
        'shipping_fee_original', 'shipping_fee_after_disc', 'shipping_disc_seller', 'shipping_disc_platform',
        'shipping_paid_buyer', 'shipping_return_fee', 'handling_fee',
        'total_qty', 'total_qty_returned', 'weight_gram',
        'payment_method', 'shipping_provider', 'shipping_option', 'tracking_no', 'fulfillment_type',
        'warehouse_name', 'package_id', 'invoice_no',
        'buyer_username', 'recipient', 'phone', 'province', 'city', 'district', 'village', 'postal_code',
        'address', 'buyer_message', 'seller_note',
    ];

    private function buildItemRow(string $platform, string $orderId, int $pk, array $header, array $item): array
    {
        $row = [
            'order_pk'    => $pk,
            'platform'    => $platform,
            'order_id'    => $orderId,
            'line_key'    => $item['line_key'],
            'line_no'     => $item['line_no'],
            'order_date'  => isset($header['created_at_pf']) && $header['created_at_pf'] !== null
                ? substr((string) $header['created_at_pf'], 0, 10) : null,
            'status_norm' => Profiles::normStatus((string) ($header['status_raw'] ?? '')),
        ];

        foreach (self::ITEM_FIELDS as $f) {
            if (array_key_exists($f, $item)) {
                $row[$f] = $item[$f];
            }
        }

        $hashSrc = $row;
        unset($hashSrc['order_pk']);
        $row['row_hash'] = sha1(json_encode($hashSrc, JSON_UNESCAPED_UNICODE) ?: '');
        $row['upload_id'] = $this->uploadId;

        // Sengaja ditambahkan SETELAH row_hash: kunci ini hanya turunan dari
        // nama produk + variasi yang sudah ikut di-hash. Kalau ikut dihitung,
        // seluruh baris lama akan dianggap berubah saat aplikasi diperbarui.
        $row['cost_key'] = Value::costKey($row['product_name'] ?? null, $row['variation'] ?? null);
        return $row;
    }

    private const ITEM_FIELDS = [
        'sku_id', 'seller_sku', 'parent_sku', 'product_name', 'variation', 'category',
        'qty', 'qty_returned', 'unit_price', 'unit_price_after_disc',
        'subtotal_before_disc', 'discount_platform', 'discount_seller', 'subtotal_after_disc',
        'refund_amount', 'buyer_service_fee', 'insurance_fee', 'weight_gram',
    ];

    /** Lengkapi kolom turunan yang tidak disediakan platform. */
    private function finaliseItem(string $platform, array $item): array
    {
        $qty = (int) ($item['qty'] ?? 0);

        if ($platform === 'shopee') {
            $unit = (float) ($item['unit_price'] ?? 0);
            $item['subtotal_before_disc'] = $unit * max($qty, 0);
            $totalDisc = (float) ($item['_total_discount'] ?? 0);
            $seller = (float) ($item['discount_seller'] ?? 0);
            $item['discount_platform'] = max(0.0, $totalDisc - $seller);
            unset($item['_total_discount']);
            // Berat Shopee tercantum per satuan produk.
            if (isset($item['weight_gram'])) {
                $item['weight_gram'] = (int) $item['weight_gram'] * max($qty, 1);
            }
        } else {
            $after = (float) ($item['subtotal_after_disc'] ?? 0);
            $item['unit_price_after_disc'] = $qty > 0 ? round($after / $qty, 2) : $after;
            if (isset($item['weight_gram'])) {
                $item['weight_gram'] = (int) $item['weight_gram'];
            }
        }

        return $item;
    }

    /**
     * Hitung ulang nilai agregat pada tabel orders dari baris produknya.
     * Dilakukan set-based agar hasilnya benar walau urutan baris pada berkas
     * acak, dan tetap benar saat file lama di-upload ulang.
     */
    private function recomputeOrderAggregates(string $platform, array $headerLabels): void
    {
        if ($this->touchedOrderIds === []) {
            return;
        }

        $headerFields = [];
        foreach (Profiles::orderHeaderMap($platform) as $spec) {
            $headerFields[$spec[0]] = true;
        }

        $agg = [
            'items_subtotal_before' => 'COALESCE(SUM(i.subtotal_before_disc),0)',
            'items_subtotal_after'  => 'COALESCE(SUM(i.subtotal_after_disc),0)',
            'discount_seller'       => 'COALESCE(SUM(i.discount_seller),0)',
            'discount_platform'     => 'COALESCE(SUM(i.discount_platform),0)',
            'total_qty'             => 'COALESCE(SUM(i.qty),0)',
            'total_qty_returned'    => 'COALESCE(SUM(i.qty_returned),0)',
            'refund_amount'         => 'COALESCE(SUM(i.refund_amount),0)',
            'buyer_service_fee'     => 'COALESCE(SUM(i.buyer_service_fee),0)',
            'insurance_fee'         => 'COALESCE(SUM(i.insurance_fee),0)',
            'weight_gram'           => 'COALESCE(SUM(i.weight_gram),0)',
            'line_count'            => 'COUNT(*)',
        ];

        // Kolom yang sudah disediakan platform di level pesanan tidak ditimpa.
        $sets = [];
        foreach ($agg as $field => $expr) {
            if ($field !== 'line_count' && isset($headerFields[$field])) {
                continue;
            }
            $sets[] = "o.{$field} = a.{$field}";
        }
        if ($sets === []) {
            return;
        }

        $selects = [];
        foreach ($agg as $field => $expr) {
            $selects[] = "{$expr} AS {$field}";
        }
        $selectSql = implode(', ', $selects);

        $pdo = Db::conn();
        foreach (array_chunk(array_keys($this->touchedOrderIds), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "UPDATE orders o
                    JOIN (
                        SELECT i.order_id, {$selectSql}
                        FROM order_items i
                        WHERE i.platform = ? AND i.order_id IN ({$ph})
                        GROUP BY i.order_id
                    ) a ON a.order_id = o.order_id
                    SET " . implode(', ', $sets) . "
                    WHERE o.platform = ?";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$platform], $chunk, [$platform]));
        }
    }

    /**
     * Menyalin tanggal & status pesanan ke tabel settlement.
     *
     * Dipakai Laporan Laba & Biaya yang berbasis TANGGAL PESANAN dan hanya
     * menghitung pesanan selesai. Disalin, bukan di-join saat melapor, karena
     * tabel settlement inilah yang paling sering dijumlah - menempelkannya ke
     * tabel orders pada setiap agregasi membuat seluruh halaman melambat.
     *
     * Yang disegarkan hanya baris yang salinannya belum sesuai, sehingga
     * menjalankan ini berulang kali tidak menambah beban.
     */
    public function syncOrderRef(string $platform): int
    {
        $n = (int) Db::q(
            "UPDATE settlements s
                JOIN orders o ON o.platform = s.platform AND o.order_id = s.order_id
                SET s.ord_date = o.order_date, s.ord_status = o.status_norm,
                    s.ord_ada_produk = (o.items_subtotal_before > 0)
              WHERE s.platform = ?
                AND ((s.ord_date <=> o.order_date) = 0
                     OR (s.ord_status <=> o.status_norm) = 0
                     OR (s.ord_ada_produk <=> (o.items_subtotal_before > 0)) = 0)",
            [$platform]
        )->rowCount();

        // Pulihkan pendapatan kotor & diskon untuk baris format Shopee terbaru.
        //
        // Berkasnya hanya memberi harga SESUDAH diskon tanpa menyebut diskonnya,
        // sedangkan berkas pesanan masih memuat harga sebelum diskon. Selisih
        // keduanya itulah diskon yang hilang, jadi keduanya dikembalikan dari
        // sana - dan karena yang dipindahkan hanya antara "kotor" dan
        // "potongan", dana diterima maupun jembatan angkanya tidak berubah.
        //
        // Satu pesanan bisa punya beberapa baris settlement, jadi nilainya
        // dibagi menurut porsi tiap baris - bukan diberikan penuh ke masing
        // masing, yang akan melipatgandakan pendapatan kotornya.
        $n += (int) Db::q(
            "UPDATE settlements s
                JOIN (SELECT platform, order_id, SUM(gross_amount) AS kotor
                        FROM settlements
                       WHERE kotor_neto = 1 AND platform = ?
                       GROUP BY platform, order_id) t
                  ON t.platform = s.platform AND t.order_id = s.order_id
                JOIN orders o
                  ON o.platform = s.platform AND o.order_id = s.order_id
                SET s.total_potongan = s.total_potongan
                        + (s.gross_amount - o.items_subtotal_before * s.gross_amount / t.kotor),
                    s.gross_amount   = o.items_subtotal_before * s.gross_amount / t.kotor,
                    s.kotor_neto     = 0
              WHERE s.kotor_neto = 1 AND s.platform = ?
                AND o.items_subtotal_before > 0 AND t.kotor <> 0",
            [$platform, $platform]
        )->rowCount();

        // Rincian biayanya menyimpan salinan yang sama supaya laporan komponen
        // biaya tidak perlu menempel ke tabel settlement - tabel ini yang
        // paling banyak barisnya.
        $n += (int) Db::q(
            "UPDATE settlement_fees f
                JOIN settlements s ON s.id = f.settlement_id
                SET f.ord_date = s.ord_date, f.ord_status = s.ord_status,
                    f.ord_ada_produk = s.ord_ada_produk
              WHERE f.platform = ?
                AND ((f.ord_date <=> s.ord_date) = 0
                     OR (f.ord_status <=> s.ord_status) = 0
                     OR (f.ord_ada_produk <=> s.ord_ada_produk) = 0)",
            [$platform]
        )->rowCount();

        return $n;
    }

    // =================================================================
    // SETTLEMENT / PENGHASILAN
    // =================================================================

    private function importSettlements(XlsxReader $reader, string $platform): void
    {
        if ($platform === 'tokopedia') {
            if ($reader->hasSheet('Detail pesanan')) {
                $this->importSettlementSheet($reader, $platform, 'Detail pesanan', 'id pesanan/penyesuaian');
            }
            if ($reader->hasSheet('Riwayat penarikan')) {
                $this->importWithdrawals($reader, $platform, 'Riwayat penarikan');
            }
            return;
        }

        if ($reader->hasSheet('Income')) {
            $this->importSettlementSheet($reader, $platform, 'Income', 'no. pesanan');
        }
        // Nama baru sheet Income pada ekspor berbahasa Indonesia. Isinya
        // berbeda susunan, jadi kolom keterangannya perlu dikecualikan.
        if ($reader->hasSheet('Penghasilan')) {
            $this->importSettlementSheet(
                $reader,
                $platform,
                'Penghasilan',
                'no. pesanan',
                Profiles::SHOPEE_KOLOM_KETERANGAN
            );
        }
        foreach (['Service Fee Details', 'Seller Fee'] as $sheet) {
            if ($reader->hasSheet($sheet)) {
                $this->importServiceFeeDetails($reader, $platform, $sheet);
            }
        }
    }

    /**
     * @param string[] $skipExtra kolom yang khusus sheet ini tidak boleh
     *                            dihitung sebagai biaya
     */
    private function importSettlementSheet(
        XlsxReader $reader,
        string $platform,
        string $sheet,
        string $idLabel,
        array $skipExtra = []
    ): void {
        $map  = Profiles::settlementMap($platform);
        $skip = array_flip(array_merge(Profiles::settlementSkipColumns($platform), $skipExtra));

        $hIdx = null;
        $headerLabels = [];
        $stat = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $buffer = [];

        foreach ($reader->rows($sheet) as $row) {
            if ($hIdx === null) {
                $cand = Profiles::headerIndex($row);
                if (isset($cand[$idLabel])) {
                    $hIdx = $cand;
                    foreach ($hIdx as $label => $i) {
                        $headerLabels[$i] = $label;
                    }
                }
                continue;
            }

            $orderId = Value::text($row[$hIdx[$idLabel]] ?? null, 64);
            if ($orderId === null || preg_match('/^[A-Za-z0-9\-_]{6,}$/', $orderId) !== 1) {
                $stat['skipped']++;
                continue;
            }

            // Format Shopee terbaru menulis pesanan yang sama dua kali: satu
            // baris untuk seluruh pesanan ("Order"), lalu baris-baris pecahan
            // per SKU ("Sku") yang jumlahnya sama. Hanya baris pesanan yang
            // diambil - kalau keduanya masuk, seluruh nilai jadi dobel.
            if (isset($hIdx['lihat berdasarkan'])) {
                $lihat = mb_strtolower(trim((string) ($row[$hIdx['lihat berdasarkan']] ?? '')));
                if ($lihat !== '' && $lihat !== 'order') {
                    $stat['skipped']++;
                    continue;
                }
            }
            $stat['read']++;

            $data = $this->mapRow($row, $hIdx, $map);
            $rec  = $this->buildSettlementRow($platform, $orderId, $data);

            // Rincian biaya: semua kolom angka yang belum jadi kolom tersendiri.
            $fees = [];
            $used = [];
            $bucket = array_fill_keys(Profiles::FEE_CATEGORIES, 0.0);
            $feeTotal = 0.0;
            $potonganTotal = 0.0;

            foreach ($headerLabels as $colIdx => $label) {
                if (isset($skip[$label])) {
                    continue;
                }
                $raw = $row[$colIdx] ?? null;
                if ($raw === null || trim((string) $raw) === '') {
                    continue;
                }
                $amount = Value::money($raw);
                if ($amount === 0.0) {
                    continue;
                }
                $cat = Profiles::feeCategory($platform, $label);
                if (in_array($cat, Profiles::FEE_CATEGORIES, true)) {
                    $bucket[$cat] += $amount;
                    $feeTotal += $amount;
                } elseif ($cat === 'potongan') {
                    $potonganTotal += $amount;
                }
                $code = Value::slug($label);
                if (isset($used[$code])) {
                    $code .= '_' . (++$used[$code]);
                } else {
                    $used[$code] = 1;
                }
                $fees[] = [
                    'fee_code'     => $code,
                    'fee_label'    => mb_substr($label, 0, 255),
                    'fee_category' => $cat,
                    'amount'       => $amount,
                ];
            }

            foreach ($bucket as $cat => $sum) {
                $rec['fee_' . $cat] = round($sum, 2);
            }
            $rec['total_potongan'] = round($potonganTotal, 2);
            if ($platform === 'shopee') {
                $rec['total_fee'] = round($feeTotal, 2);

                // Format lama punya kolom "Total Diskon Produk" dan harganya
                // SEBELUM diskon. Format baru menghapus kolom itu, dan
                // "Harga Produk"-nya sudah SESUDAH diskon - terbukti sama
                // persis dengan "Subtotal Pesanan" pada berkas pesanan.
                //
                // Tanpa penanda ini, bulan yang dilaporkan format baru akan
                // tampak berdiskon nol dan pendapatan kotornya lebih rendah
                // daripada bulan sebelumnya, padahal diskonnya tetap ada.
                // Nilainya dipulihkan dari berkas pesanan di syncOrderRef().
                $rec['kotor_neto'] = isset($hIdx['total diskon produk']) ? 0 : 1;

                // Format terbaru tidak lagi punya kolom "Total Penghasilan",
                // jadi dana yang dilepas dihitung dari komponennya. Hasilnya
                // sama persis dengan "Total yang Dilepas" pada sheet Summary -
                // itulah yang dipakai untuk memastikan tidak ada komponen yang
                // terlewat atau terhitung dua kali.
                if (!isset($hIdx['total penghasilan'])) {
                    $rec['net_amount'] = round(
                        $rec['gross_amount'] + $rec['refund_amount']
                        + $rec['total_potongan'] + $feeTotal,
                        2
                    );
                }
            }

            $rec['row_hash'] = sha1(json_encode([$rec, $fees], JSON_UNESCAPED_UNICODE) ?: '');
            $rec['upload_id'] = $this->uploadId;

            $buffer[] = [
                'rec'  => $rec,
                'fees' => $fees,
                'raw'  => Config::get('keep_raw')
                    ? json_encode($this->rawRow($row, $hIdx), JSON_UNESCAPED_UNICODE) : null,
            ];
            $this->trackPeriod($rec['settlement_date'] ?? null);

            if (count($buffer) >= $this->batchOrders) {
                $this->flushSettlements($platform, $buffer, $stat);
                $buffer = [];
            }
        }

        if ($hIdx === null) {
            throw new RuntimeException("Baris judul kolom tidak ditemukan pada sheet '{$sheet}'.");
        }
        if ($buffer !== []) {
            $this->flushSettlements($platform, $buffer, $stat);
        }

        $this->stats['Settlement (' . $sheet . ')'] = $stat;
    }

    private function buildSettlementRow(string $platform, string $orderId, array $data): array
    {
        $settlementTime = $data['settlement_time'] ?? null;

        $rec = [
            'platform'          => $platform,
            'order_id'          => $orderId,
            'trx_type'          => $data['trx_type'] ?? ($platform === 'shopee' ? 'Income' : 'Pesanan'),
            'related_order_id'  => $data['related_order_id'] ?? null,
            'source_channel'    => $data['source_channel'] ?? ($platform === 'shopee' ? 'Shopee' : null),
            'currency'          => substr((string) ($data['currency'] ?? 'IDR'), 0, 3) ?: 'IDR',
            'order_time'        => $data['order_time'] ?? null,
            'settlement_time'   => $settlementTime,
            'settlement_date'   => $settlementTime !== null ? substr((string) $settlementTime, 0, 10) : null,
            'gross_amount'      => (float) ($data['gross_amount'] ?? 0),
            'discount_seller'   => (float) ($data['discount_seller'] ?? 0),
            'refund_amount'     => (float) ($data['refund_amount'] ?? 0),
            'buyer_payment'     => (float) ($data['buyer_payment'] ?? 0),
            'adjustment_amount' => (float) ($data['adjustment_amount'] ?? 0),
            'total_fee'         => (float) ($data['total_fee'] ?? 0),
            'net_amount'        => (float) ($data['net_amount'] ?? 0),
            'payment_method'    => $data['payment_method'] ?? null,
            'courier'           => $data['courier'] ?? null,
            'buyer_username'    => $data['buyer_username'] ?? null,
            'weight_gram'       => (int) ($data['weight_gram'] ?? 0),
        ];

        // Kunci alami: satu pesanan bisa punya beberapa baris settlement
        // (pembayaran awal + pembalikan/refund pada tanggal berbeda).
        $rec['trx_key'] = mb_substr(
            $orderId . '|' . mb_strtolower((string) $rec['trx_type']) . '|' . ($rec['settlement_time'] ?? '-'),
            0,
            160
        );

        return $rec;
    }

    private function flushSettlements(string $platform, array $buffer, array &$stat): void
    {
        $pdo = Db::conn();
        $keys = array_map(static fn(array $b): string => $b['rec']['trx_key'], $buffer);

        $pdo->beginTransaction();
        try {
            $existing = $this->fetchHashes(
                'SELECT trx_key AS k, row_hash FROM settlements WHERE platform = ? AND trx_key IN (%s)',
                $platform,
                $keys
            );

            $write = [];
            $needFees = [];
            $rawByKey = [];
            foreach ($buffer as $b) {
                $key = $b['rec']['trx_key'];
                if (!isset($existing[$key])) {
                    $write[] = $b['rec'];
                    $needFees[$key] = $b['fees'];
                    $rawByKey[$key] = $b['raw'];
                    $stat['inserted']++;
                } elseif ($existing[$key] !== $b['rec']['row_hash']) {
                    $write[] = $b['rec'];
                    $needFees[$key] = $b['fees'];
                    $rawByKey[$key] = $b['raw'];
                    $stat['updated']++;
                } else {
                    $stat['unchanged']++;
                }
            }

            foreach (array_chunk($write, 200) as $chunk) {
                $this->bulkUpsert('settlements', $chunk);
            }

            if ($needFees !== []) {
                $idMap = [];
                foreach (array_chunk(array_keys($needFees), 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $st = $pdo->prepare(
                        "SELECT id, trx_key, order_id, settlement_date FROM settlements WHERE platform = ? AND trx_key IN ({$ph})"
                    );
                    $st->execute(array_merge([$platform], $chunk));
                    foreach ($st as $r) {
                        $idMap[$r['trx_key']] = $r;
                    }
                }

                $ids = [];
                $feeRows = [];
                $rawRows = [];
                foreach ($needFees as $key => $fees) {
                    $meta = $idMap[$key] ?? null;
                    if ($meta === null) {
                        continue;
                    }
                    $ids[] = (int) $meta['id'];
                    if (($rawByKey[$key] ?? null) !== null) {
                        $rawRows[] = ['settlement_id' => (int) $meta['id'], 'raw_json' => $rawByKey[$key]];
                    }
                    foreach ($fees as $f) {
                        $feeRows[] = [
                            'settlement_id'   => (int) $meta['id'],
                            'platform'        => $platform,
                            'order_id'        => $meta['order_id'],
                            'settlement_date' => $meta['settlement_date'],
                            'fee_code'        => $f['fee_code'],
                            'fee_label'       => $f['fee_label'],
                            'fee_category'    => $f['fee_category'],
                            'amount'          => $f['amount'],
                        ];
                    }
                }

                // Ganti total rincian biaya milik settlement yang berubah,
                // supaya komponen yang hilang tidak tertinggal jadi data basi.
                foreach (array_chunk($ids, 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $pdo->prepare("DELETE FROM settlement_fees WHERE settlement_id IN ({$ph})")->execute($chunk);
                }
                foreach (array_chunk($feeRows, 500) as $chunk) {
                    $this->bulkUpsert('settlement_fees', $chunk);
                }
                foreach (array_chunk($rawRows, 200) as $chunk) {
                    $this->bulkUpsert('settlement_raw', $chunk);
                }
                $this->syncFeeDictionary($platform, $feeRows);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Sheet "Service Fee Details" Shopee memecah kolom "Biaya Layanan".
     * Disimpan dengan kategori 'rincian' supaya TIDAK ikut dijumlahkan lagi
     * ke fee_layanan (kalau ikut, biaya layanan akan terhitung dua kali).
     */
    private function importServiceFeeDetails(XlsxReader $reader, string $platform, string $sheet): void
    {
        $hIdx = null;
        $labels = [];
        $stat = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $pending = [];

        foreach ($reader->rows($sheet) as $row) {
            if ($hIdx === null) {
                $cand = Profiles::headerIndex($row);
                if (isset($cand['no. pesanan'])) {
                    $hIdx = $cand;
                    foreach ($hIdx as $label => $i) {
                        $labels[$i] = $label;
                    }
                }
                continue;
            }
            $orderId = Value::text($row[$hIdx['no. pesanan']] ?? null, 64);
            if ($orderId === null) {
                $stat['skipped']++;
                continue;
            }
            $stat['read']++;
            $pending[$orderId] = $row;

            if (count($pending) >= 400) {
                $this->flushServiceFees($platform, $pending, $labels, $hIdx, $stat);
                $pending = [];
            }
        }

        if ($pending !== []) {
            $this->flushServiceFees($platform, $pending, $labels, $hIdx ?? [], $stat);
        }
        $stat['skipped'] = max(0, $stat['read'] - $stat['updated'] - $stat['inserted'] - $stat['unchanged']);
        if ($hIdx !== null) {
            $this->stats['Rincian biaya layanan'] = $stat;
        }
    }

    private function flushServiceFees(string $platform, array $pending, array $labels, array $hIdx, array &$stat): void
    {
        if ($pending === [] || $hIdx === []) {
            return;
        }
        $pdo = Db::conn();
        $orderIds = array_keys($pending);
        $idMap = [];
        foreach (array_chunk($orderIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare(
                "SELECT id, order_id, settlement_date FROM settlements WHERE platform = ? AND order_id IN ({$ph})"
            );
            $st->execute(array_merge([$platform], $chunk));
            foreach ($st as $r) {
                $idMap[$r['order_id']] = $r;
            }
        }

        // Rincian yang sudah tersimpan, untuk membedakan "berubah" dan "sudah sama".
        $existing = [];
        if ($idMap !== []) {
            $sids = array_map(static fn(array $m): int => (int) $m['id'], $idMap);
            foreach (array_chunk($sids, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare(
                    "SELECT settlement_id, fee_code, amount FROM settlement_fees
                     WHERE fee_category = 'rincian' AND settlement_id IN ({$ph})"
                );
                $st->execute($chunk);
                foreach ($st as $r) {
                    $existing[(int) $r['settlement_id']][$r['fee_code']] = number_format((float) $r['amount'], 2, '.', '');
                }
            }
        }

        $rows = [];
        $staleIds = [];
        foreach ($pending as $orderId => $row) {
            $orderId = (string) $orderId;
            $meta = $idMap[$orderId] ?? null;
            if ($meta === null) {
                continue;  // settlement-nya belum ada; akan terisi saat file Income diimpor
            }
            $sid = (int) $meta['id'];

            $mine = [];
            foreach ($labels as $idx => $label) {
                if ($label === 'no.' || $label === 'no. pesanan') {
                    continue;
                }
                $amount = Value::money($row[$idx] ?? null);
                if ($amount === 0.0) {
                    continue;
                }
                $mine['rincian_' . Value::slug($label, 100)] = [
                    'label'  => mb_substr('Rincian: ' . $label, 0, 255),
                    'amount' => $amount,
                ];
            }

            $before = $existing[$sid] ?? [];
            $after = [];
            foreach ($mine as $code => $m) {
                $after[$code] = number_format($m['amount'], 2, '.', '');
            }
            ksort($before);
            ksort($after);
            if ($before === $after) {
                $stat['unchanged']++;
                continue;
            }
            if ($before === []) {
                $stat['inserted']++;
            } else {
                $stat['updated']++;
                $staleIds[] = $sid;   // buang kode lama yang tidak muncul lagi
            }

            foreach ($mine as $code => $m) {
                $rows[] = [
                    'settlement_id'   => $sid,
                    'platform'        => $platform,
                    'order_id'        => $orderId,
                    'settlement_date' => $meta['settlement_date'],
                    'fee_code'        => $code,
                    'fee_label'       => $m['label'],
                    'fee_category'    => 'rincian',
                    'amount'          => $m['amount'],
                ];
            }
        }

        foreach (array_chunk($staleIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare(
                "DELETE FROM settlement_fees WHERE fee_category = 'rincian' AND settlement_id IN ({$ph})"
            )->execute($chunk);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->bulkUpsert('settlement_fees', $chunk);
        }
    }

    private function importWithdrawals(XlsxReader $reader, string $platform, string $sheet): void
    {
        $hIdx = null;
        $stat = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $buffer = [];

        foreach ($reader->rows($sheet) as $row) {
            if ($hIdx === null) {
                $cand = Profiles::headerIndex($row);
                if (isset($cand['id referensi'])) {
                    $hIdx = $cand;
                }
                continue;
            }
            $ref = Value::text($row[$hIdx['id referensi']] ?? null, 64);
            if ($ref === null) {
                $stat['skipped']++;
                continue;
            }
            $stat['read']++;

            $requested = Value::dateTime($row[$hIdx['waktu permintaan'] ?? -1] ?? null);
            $succeeded = Value::dateTime($row[$hIdx['waktu keberhasilan'] ?? -1] ?? null);
            $rec = [
                'platform'      => $platform,
                'reference_id'  => $ref,
                'trx_type'      => Value::text($row[$hIdx['jenis transaksi'] ?? -1] ?? null, 64),
                'requested_at'  => $requested,
                'succeeded_at'  => $succeeded,
                'withdraw_date' => $succeeded !== null ? substr($succeeded, 0, 10)
                    : ($requested !== null ? substr($requested, 0, 10) : null),
                'amount'        => Value::money($row[$hIdx['total'] ?? -1] ?? null),
                'status'        => Value::text($row[$hIdx['status'] ?? -1] ?? null, 48),
                'bank_account'  => Value::text($row[$hIdx['rekening bank'] ?? -1] ?? null, 128),
            ];
            $rec['row_hash'] = sha1(json_encode($rec, JSON_UNESCAPED_UNICODE) ?: '');
            $rec['upload_id'] = $this->uploadId;
            $buffer[] = $rec;
        }

        if ($hIdx === null || $buffer === []) {
            return;
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            $existing = $this->fetchHashes(
                'SELECT reference_id AS k, row_hash FROM withdrawals WHERE platform = ? AND reference_id IN (%s)',
                $platform,
                array_column($buffer, 'reference_id')
            );
            $write = [];
            foreach ($buffer as $rec) {
                $k = $rec['reference_id'];
                if (!isset($existing[$k])) {
                    $write[] = $rec;
                    $stat['inserted']++;
                } elseif ($existing[$k] !== $rec['row_hash']) {
                    $write[] = $rec;
                    $stat['updated']++;
                } else {
                    $stat['unchanged']++;
                }
            }
            foreach (array_chunk($write, 300) as $chunk) {
                $this->bulkUpsert('withdrawals', $chunk);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->stats['Penarikan dana'] = $stat;
    }

    // =================================================================
    // Util database
    // =================================================================

    /** @return array<string,string> kunci => row_hash */
    private function fetchHashes(string $sqlTemplate, string $platform, array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = Db::conn()->prepare(sprintf($sqlTemplate, $ph));
            $st->execute(array_merge([$platform], $chunk));
            foreach ($st as $r) {
                $out[$r['k']] = $r['row_hash'];
            }
        }
        return $out;
    }

    /**
     * INSERT banyak baris sekaligus; bila kunci unik sudah ada, kolomnya
     * di-update. Inilah lapisan terakhir yang menjamin tidak ada data dobel.
     */
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
            if ($c === 'first_seen_at') {
                continue;
            }
            $updates[] = "`{$c}` = VALUES(`{$c}`)";
        }

        $sql = "INSERT INTO `{$table}` ({$colSql}) VALUES "
            . implode(',', array_fill(0, count($rows), $rowPh))
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        Db::conn()->prepare($sql)->execute($params);
    }

    private function syncFeeDictionary(string $platform, array $feeRows): void
    {
        static $known = [];
        $new = [];
        foreach ($feeRows as $f) {
            $k = $platform . '|' . $f['fee_code'];
            if (isset($known[$k])) {
                continue;
            }
            $known[$k] = true;
            $new[$f['fee_code']] = [
                'platform'  => $platform,
                'fee_code'  => $f['fee_code'],
                'fee_label' => $f['fee_label'],
                'category'  => $f['fee_category'],
            ];
        }
        if ($new === []) {
            return;
        }
        // Kategori yang sudah diubah manual (is_locked=1) tidak ditimpa.
        $sql = 'INSERT INTO fee_dictionary (platform, fee_code, fee_label, category) VALUES ';
        $sql .= implode(',', array_fill(0, count($new), '(?,?,?,?)'));
        $sql .= ' ON DUPLICATE KEY UPDATE fee_label = VALUES(fee_label),
                  category = IF(is_locked = 1, category, VALUES(category))';
        $params = [];
        foreach ($new as $n) {
            array_push($params, $n['platform'], $n['fee_code'], $n['fee_label'], $n['category']);
        }
        Db::conn()->prepare($sql)->execute($params);
    }

    // =================================================================
    // Pemetaan baris
    // =================================================================

    private function mapRow(array $row, array $hIdx, array $map): array
    {
        $out = [];
        foreach ($map as $label => [$field, $type]) {
            if (!isset($hIdx[$label])) {
                continue;
            }
            $raw = $row[$hIdx[$label]] ?? null;
            $out[$field] = match ($type) {
                'money'    => Value::money($raw),
                'int'      => Value::int($raw),
                'datetime' => Value::dateTime($raw),
                'date'     => Value::dateOnly($raw),
                'gram'     => Value::grams($raw, 'g'),
                'kg'       => Value::grams($raw, 'kg'),
                default    => Value::text($raw, 500),
            };
        }
        return $out;
    }

    private function rawRow(array $row, array $hIdx): array
    {
        $out = [];
        foreach ($hIdx as $label => $idx) {
            $v = $row[$idx] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                $out[$label] = $v;
            }
        }
        return $out;
    }

    private function trackPeriod(?string $date): void
    {
        if ($date === null || strlen($date) < 10) {
            return;
        }
        $d = substr($date, 0, 10);
        if ($this->periodFrom === null || $d < $this->periodFrom) {
            $this->periodFrom = $d;
        }
        if ($this->periodTo === null || $d > $this->periodTo) {
            $this->periodTo = $d;
        }
    }

    // =================================================================
    // Catatan upload
    // =================================================================

    private function createUploadRow(string $name, string $hash, int $size, array $info, ?int $userId): int
    {
        Db::q(
            'INSERT INTO uploads (original_name, file_hash, size_bytes, platform, dataset, status, uploaded_by)
             VALUES (?,?,?,?,?,?,?)',
            [$name, $hash, $size, $info['platform'], $info['dataset'], 'pending', $userId]
        );
        return (int) Db::conn()->lastInsertId();
    }

    private function totals(): array
    {
        $t = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        foreach ($this->stats as $s) {
            foreach ($t as $k => $_) {
                $t[$k] += $s[$k] ?? 0;
            }
        }
        return $t;
    }

    private function finishUpload(string $status, ?string $message, int $ms): void
    {
        if ($this->uploadId === null) {
            return;
        }
        $t = $this->totals();
        Db::q(
            'UPDATE uploads SET status=?, rows_read=?, rows_inserted=?, rows_updated=?, rows_unchanged=?,
             rows_skipped=?, duration_ms=?, message=?, detail_json=?, period_from=?, period_to=?, finished_at=NOW()
             WHERE id=?',
            [
                $status, $t['read'], $t['inserted'], $t['updated'], $t['unchanged'], $t['skipped'], $ms,
                $message !== null ? mb_substr($message, 0, 2000) : null,
                json_encode($this->stats, JSON_UNESCAPED_UNICODE),
                $this->periodFrom, $this->periodTo, $this->uploadId,
            ]
        );
    }
}
