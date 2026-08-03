<?php
declare(strict_types=1);

/**
 * Definisi setiap jenis berkas ekspor + pemetaan kolom.
 *
 * Pemetaan memakai NAMA KOLOM (bukan nomor kolom) supaya tetap jalan ketika
 * Tokopedia/Shopee menambah, menghapus, atau menggeser kolom pada ekspor
 * berikutnya - hal yang pasti terjadi kalau file di-upload tiap minggu.
 * Kolom baru yang belum dikenal tidak akan hilang: kolom angka otomatis
 * tersimpan di tabel settlement_fees dan dikategorikan lewat aturan regex.
 */
final class Profiles
{
    /** Kategori yang dijumlahkan ke kolom fee_* pada tabel settlements. */
    public const FEE_CATEGORIES = [
        'komisi', 'layanan', 'administrasi', 'pembayaran', 'proses', 'pengiriman',
        'afiliasi', 'iklan', 'kampanye', 'pajak', 'asuransi', 'fulfillment',
        'promosi', 'lainnya',
    ];

    /**
     * Kategori non-biaya (tidak masuk fee_*).
     *   total     = kolom total bawaan platform
     *   potongan  = pengurang pendapatan (diskon & voucher yang ditanggung penjual)
     *   refund    = pengembalian dana
     *   rincian   = pecahan dari kolom lain, jangan dijumlah lagi
     *   informasi = kolom keterangan (mis. rincian pembayaran pembeli)
     */
    public const NON_FEE_CATEGORIES = ['total', 'potongan', 'refund', 'penyesuaian', 'rincian', 'informasi'];

    public const LABELS = [
        'komisi'       => 'Biaya komisi',
        'layanan'      => 'Biaya layanan',
        'administrasi' => 'Biaya administrasi',
        'pembayaran'   => 'Biaya pembayaran/transaksi',
        'proses'       => 'Biaya proses pesanan',
        'pengiriman'   => 'Biaya & subsidi pengiriman',
        'afiliasi'     => 'Komisi afiliasi',
        'iklan'        => 'Biaya iklan',
        'kampanye'     => 'Biaya kampanye/promo program',
        'pajak'        => 'Pajak & bea',
        'asuransi'     => 'Asuransi',
        'fulfillment'  => 'Biaya fulfillment',
        'promosi'      => 'Biaya program promo & cashback',
        'lainnya'      => 'Biaya lainnya',
        'total'        => 'Kolom total',
        'potongan'     => 'Potongan pendapatan (diskon & voucher penjual)',
        'refund'       => 'Pengembalian dana',
        'penyesuaian'  => 'Penyesuaian',
        'rincian'      => 'Rincian (bagian dari kolom lain)',
        'informasi'    => 'Keterangan (tidak dijumlah)',
    ];

    // =================================================================
    // Deteksi jenis berkas
    // =================================================================

    /** @return array{platform:string,dataset:string,label:string}|null */
    public static function detect(XlsxReader $reader): ?array
    {
        $sheets = $reader->sheetNames();
        $has = static fn(string $n): bool => in_array($n, $sheets, true);

        if ($has('OrderSKUList')) {
            return self::info('tokopedia', 'order');
        }
        if ($has('Detail pesanan') || $has('Riwayat penarikan')) {
            return self::info('tokopedia', 'settlement');
        }
        // 'Penghasilan' adalah nama baru sheet 'Income' pada ekspor Shopee
        // berbahasa Indonesia. Keduanya dilayani supaya berkas lama tetap bisa
        // diunggah ulang.
        if ($has('Income') || $has('Penghasilan')) {
            return self::info('shopee', 'settlement');
        }
        if ($has('orders')) {
            return self::info('shopee', 'order');
        }

        // Cadangan: tebak dari judul kolom pada sheet pertama.
        foreach ($sheets as $sheet) {
            $rows = $reader->peek($sheet, 12);
            foreach ($rows as $row) {
                $join = mb_strtolower(implode('|', $row));
                if (str_contains($join, 'order id') && str_contains($join, 'sku id')) {
                    return self::info('tokopedia', 'order');
                }
                if (str_contains($join, 'id pesanan/penyesuaian')) {
                    return self::info('tokopedia', 'settlement');
                }
                if (str_contains($join, 'no. pesanan') && str_contains($join, 'status pesanan')) {
                    return self::info('shopee', 'order');
                }
                // Kolom "Total Penghasilan" hilang pada format Shopee yang baru;
                // penanda yang tersisa adalah kolom "Lihat berdasarkan" yang
                // memisahkan baris tingkat pesanan dari baris tingkat SKU.
                if (str_contains($join, 'no. pesanan')
                    && (str_contains($join, 'total penghasilan')
                        || str_contains($join, 'tanggal dana dilepaskan')
                        || str_contains($join, 'lihat berdasarkan'))) {
                    return self::info('shopee', 'settlement');
                }
            }
        }

        return null;
    }

    private static function info(string $platform, string $dataset): array
    {
        $labels = [
            'tokopedia|order'      => 'Tokopedia - Semua Pesanan',
            'tokopedia|settlement' => 'Tokopedia - Transaksi / Penghasilan',
            'shopee|order'         => 'Shopee - Order (Semua Pesanan)',
            'shopee|settlement'    => 'Shopee - Laporan Penghasilan (Income)',
        ];
        return [
            'platform' => $platform,
            'dataset'  => $dataset,
            'label'    => $labels["{$platform}|{$dataset}"] ?? "{$platform} {$dataset}",
        ];
    }

    // =================================================================
    // Pemetaan kolom - PESANAN
    // =================================================================

    /** Kolom level pesanan (nilainya berulang di setiap baris SKU). */
    public static function orderHeaderMap(string $platform): array
    {
        if ($platform === 'tokopedia') {
            return [
                'order id'                       => ['order_id', 'text'],
                'order status'                   => ['status_raw', 'text'],
                'order substatus'                => ['substatus', 'text'],
                'normal or pre-order'            => ['order_type', 'text'],
                'purchase channel'               => ['channel', 'text'],
                'created time'                   => ['created_at_pf', 'datetime'],
                'paid time'                      => ['paid_at', 'datetime'],
                'rts time'                       => ['rts_at', 'datetime'],
                'shipped time'                   => ['shipped_at', 'datetime'],
                'delivered time'                 => ['delivered_at', 'datetime'],
                'cancelled time'                 => ['cancelled_at', 'datetime'],
                'cancel by'                      => ['cancel_by', 'text'],
                'cancel reason'                  => ['cancel_reason', 'text'],
                'cancelation/return type'        => ['return_status', 'text'],
                'order amount'                   => ['order_amount', 'money'],
                'original shipping fee'          => ['shipping_fee_original', 'money'],
                'shipping fee after discount'    => ['shipping_fee_after_disc', 'money'],
                'shipping fee seller discount'   => ['shipping_disc_seller', 'money'],
                'shipping fee platform discount' => ['shipping_disc_platform', 'money'],
                'payment platform discount'      => ['voucher_platform', 'money'],
                'handling fee'                   => ['handling_fee', 'money'],
                'payment method'                 => ['payment_method', 'text'],
                'shipping provider name'         => ['shipping_provider', 'text'],
                'delivery option'                => ['shipping_option', 'text'],
                'tracking id'                    => ['tracking_no', 'text'],
                'fulfillment type'               => ['fulfillment_type', 'text'],
                'warehouse name'                 => ['warehouse_name', 'text'],
                'package id'                     => ['package_id', 'text'],
                'tokopedia invoice number'       => ['invoice_no', 'text'],
                'buyer username'                 => ['buyer_username', 'text'],
                'recipient'                      => ['recipient', 'text'],
                'phone #'                        => ['phone', 'text'],
                'province'                       => ['province', 'text'],
                'regency and city'               => ['city', 'text'],
                'districts'                      => ['district', 'text'],
                'villages'                       => ['village', 'text'],
                'zipcode'                        => ['postal_code', 'text'],
                'detail address'                 => ['address', 'text'],
                'buyer message'                  => ['buyer_message', 'text'],
                'seller note'                    => ['seller_note', 'text'],
            ];
        }

        return [ // shopee
            'no. pesanan'                        => ['order_id', 'text'],
            'status pesanan'                     => ['status_raw', 'text'],
            'status pembatalan/ pengembalian'    => ['return_status', 'text'],
            'alasan pembatalan'                  => ['cancel_reason', 'text'],
            'waktu pesanan dibuat'               => ['created_at_pf', 'datetime'],
            'waktu pembayaran dilakukan'         => ['paid_at', 'datetime'],
            'waktu pengiriman diatur'            => ['shipped_at', 'datetime'],
            'waktu pesanan selesai'              => ['completed_at', 'datetime'],
            'total pembayaran'                   => ['order_amount', 'money'],
            'perkiraan ongkos kirim'             => ['shipping_fee_original', 'money'],
            'estimasi potongan biaya pengiriman' => ['shipping_disc_platform', 'money'],
            'ongkos kirim dibayar oleh pembeli'  => ['shipping_paid_buyer', 'money'],
            'ongkos kirim pengembalian barang'   => ['shipping_return_fee', 'money'],
            'diskon dari shopee'                 => ['discount_platform', 'money'],
            'voucher ditanggung penjual'         => ['voucher_seller', 'money'],
            'voucher ditanggung shopee'          => ['voucher_platform', 'money'],
            'cashback koin'                      => ['coin_cashback', 'money'],
            'jumlah produk di pesan'             => ['total_qty', 'int'],
            'returned quantity'                  => ['total_qty_returned', 'int'],
            'total berat'                        => ['weight_gram', 'gram'],
            'metode pembayaran'                  => ['payment_method', 'text'],
            'opsi pengiriman'                    => ['shipping_option', 'text'],
            'no. resi'                           => ['tracking_no', 'text'],
            'username (pembeli)'                 => ['buyer_username', 'text'],
            'nama penerima'                      => ['recipient', 'text'],
            'no. telepon'                        => ['phone', 'text'],
            'provinsi'                           => ['province', 'text'],
            'kota/kabupaten'                     => ['city', 'text'],
            'alamat pengiriman'                  => ['address', 'text'],
            'catatan dari pembeli'               => ['buyer_message', 'text'],
            'catatan'                            => ['seller_note', 'text'],
        ];
    }

    /** Kolom level SKU/baris produk. */
    public static function orderItemMap(string $platform): array
    {
        if ($platform === 'tokopedia') {
            return [
                'sku id'                       => ['sku_id', 'text'],
                'seller sku'                   => ['seller_sku', 'text'],
                'product name'                 => ['product_name', 'text'],
                'variation'                    => ['variation', 'text'],
                'product category'             => ['category', 'text'],
                'quantity'                     => ['qty', 'int'],
                'sku quantity of return'       => ['qty_returned', 'int'],
                'sku unit original price'      => ['unit_price', 'money'],
                'sku subtotal before discount' => ['subtotal_before_disc', 'money'],
                'sku platform discount'        => ['discount_platform', 'money'],
                'sku seller discount'          => ['discount_seller', 'money'],
                'sku subtotal after discount'  => ['subtotal_after_disc', 'money'],
                'order refund amount'          => ['refund_amount', 'money'],
                'buyer service fee'            => ['buyer_service_fee', 'money'],
                'shipping insurance'           => ['insurance_fee', 'money'],
                'weight(kg)'                   => ['weight_gram', 'kg'],
            ];
        }

        return [ // shopee
            'sku induk'            => ['parent_sku', 'text'],
            'nomor referensi sku'  => ['seller_sku', 'text'],
            'nama produk'          => ['product_name', 'text'],
            'nama variasi'         => ['variation', 'text'],
            'jumlah'               => ['qty', 'int'],
            'harga awal'           => ['unit_price', 'money'],
            'harga setelah diskon' => ['unit_price_after_disc', 'money'],
            'subtotal pesanan'     => ['subtotal_after_disc', 'money'],
            'diskon dari penjual'  => ['discount_seller', 'money'],
            'total diskon'         => ['_total_discount', 'money'],
            'berat produk'         => ['weight_gram', 'gram'],
        ];
    }

    /** Kolom yang dipakai membentuk kunci baris SKU (agar stabil antar upload). */
    public static function itemKeyColumns(string $platform): array
    {
        return $platform === 'tokopedia'
            ? ['sku id', 'variation']
            : ['nomor referensi sku', 'nama produk', 'nama variasi'];
    }

    // =================================================================
    // Pemetaan kolom - SETTLEMENT
    // =================================================================

    public static function settlementMap(string $platform): array
    {
        if ($platform === 'tokopedia') {
            return [
                'id pesanan/penyesuaian'         => ['order_id', 'text'],
                'jenis transaksi'                => ['trx_type', 'text'],
                'waktu pemesanan'                => ['order_time', 'datetime'],
                'waktu pembayaran pesanan'       => ['settlement_time', 'datetime'],
                'mata uang'                      => ['currency', 'text'],
                'jumlah penyelesaian pembayaran' => ['net_amount', 'money'],
                // Pendapatan kotor memakai "Subtotal sebelum diskon", BUKAN
                // "Total Pendapatan". Kolom "Total Pendapatan" milik Tokopedia
                // sudah dikurangi diskon penjual dan pengembalian dana, terbukti:
                //   subtotal sebelum diskon + diskon penjual
                //   + subtotal pengembalian dana setelah diskon = total pendapatan
                // Dengan memakai subtotal sebelum diskon, angka "kotor" Tokopedia
                // setara dengan "Harga Asli Produk" milik Shopee.
                'subtotal sebelum diskon'        => ['gross_amount', 'money'],
                'total biaya'                    => ['total_fee', 'money'],
                'diskon penjual'                 => ['discount_seller', 'money'],
                'jumlah penyesuaian'             => ['adjustment_amount', 'money'],
                'id pesanan terkait'             => ['related_order_id', 'text'],
                'pembayaran oleh pembeli'        => ['buyer_payment', 'money'],
                'subtotal pengembalian dana setelah diskon penjual' => ['refund_amount', 'money'],
                'sumber pesanan'                 => ['source_channel', 'text'],
                'perkiraan berat paket'          => ['weight_gram', 'gram'],
            ];
        }

        return [ // shopee
            'no. pesanan'                         => ['order_id', 'text'],
            'no. pengajuan'                       => ['related_order_id', 'text'],
            'username (pembeli)'                  => ['buyer_username', 'text'],
            'waktu pesanan dibuat'                => ['order_time', 'datetime'],
            'tanggal dana dilepaskan'             => ['settlement_time', 'datetime'],
            'metode pembayaran pembeli'           => ['payment_method', 'text'],
            'nama kurir'                          => ['courier', 'text'],
            'harga asli produk'                   => ['gross_amount', 'money'],
            // Nama baru untuk kolom yang sama; keduanya dipetakan supaya berkas
            // lama maupun baru sama-sama terbaca.
            'harga produk'                        => ['gross_amount', 'money'],
            'total penghasilan'                   => ['net_amount', 'money'],
            'jumlah dibayar pembeli'              => ['buyer_payment', 'money'],
            'jumlah pengembalian dana ke pembeli' => ['refund_amount', 'money'],
            'total diskon produk'                 => ['discount_seller', 'money'],
        ];
    }

    /**
     * Kolom keterangan pada sheet Penghasilan (format Shopee terbaru).
     *
     * Shopee memisahkan lembarnya menjadi beberapa kelompok; hanya kelompok
     * "Rincian Jumlah Pelepasan Dana" yang membentuk dana yang dilepas.
     * Kolom di bawah ini ada di kelompok "Buyer Info" dan "Informasi
     * Referensi" - angkanya nyata, tetapi bukan bagian dari perhitungan.
     * Kalau ikut dijumlah, dana yang dilepas jadi tidak cocok dengan
     * ringkasan resmi Shopee.
     *
     * Daftar ini khusus sheet tersebut, bukan untuk seluruh berkas Shopee:
     * pada format lama sebagian nama yang sama berada di dalam perhitungan.
     */
    public const SHOPEE_KOLOM_KETERANGAN = [
        'lihat berdasarkan',
        'id produk',
        'nama produk',
        'metode pelepasan dana',
        'tipe pesanan',
        'jumlah dibayar pembeli',
        'transaction fee rate (%)',
        'rincian metode pembayaran',
        'rencana cicilan (jika berlaku)',
        'promo gratis ongkir dari penjual',
        'kompensasi',
    ];

    /** Kolom yang bukan angka biaya sehingga tidak ikut ke settlement_fees. */
    public static function settlementSkipColumns(string $platform): array
    {
        $common = ['no.', 'kode voucher', 'jasa kirim'];
        if ($platform === 'tokopedia') {
            return array_merge($common, [
                'id pesanan/penyesuaian', 'jenis transaksi', 'waktu pemesanan',
                'waktu pembayaran pesanan', 'mata uang', 'id pesanan terkait',
                'detail produk terjual', 'sumber pesanan', 'perkiraan berat paket',
                'berat paket yang bisa dikenai biaya',
            ]);
        }
        return array_merge($common, [
            'no. pesanan', 'no. pengajuan', 'username (pembeli)', 'waktu pesanan dibuat',
            'metode pembayaran pembeli', 'tanggal dana dilepaskan', 'nama kurir',
        ]);
    }

    /**
     * Kategori akuntansi per nama kolom.
     * Daftar eksplisit dulu (akurat untuk kolom yang sudah dikenal), lalu
     * aturan regex sebagai cadangan untuk kolom baru dari platform.
     */
    public static function feeCategory(string $platform, string $label): string
    {
        $l = self::normLabel($label);

        $explicit = self::categoryOverrides()[$l] ?? null;
        if ($explicit !== null) {
            return $explicit;
        }

        $rules = [
            'afiliasi'     => '/afilia/',
            'iklan'        => '/iklan|gmv max|advertis/',
            'kampanye'     => '/kampanye|campaign|flash sale|crazy deal|khusus live|live xtra|promo xtra/',
            'administrasi' => '/administrasi/',
            'pajak'        => '/pph|pajak|bea masuk|\bppn\b/',
            'komisi'       => '/komisi/',
            'fulfillment'  => '/dilayani tokopedia|fulfillment|gudang/',
            'proses'       => '/pemrosesan pesanan|proses pesanan|biaya per pesanan/',
            'pengiriman'   => '/ongkir|ongkos kirim|pengiriman|logistik|biaya kirim/',
            'asuransi'     => '/asuransi|premi/',
            'pembayaran'   => '/pembayaran|paylater|payment|installment|transaksi/',
            'promosi'      => '/voucher|cashback|koin|diskon|subsidi|kompensasi/',
            'refund'       => '/pengembalian|refund|retur/',
            'layanan'      => '/layanan|akses keuntungan/',
            'penyesuaian'  => '/penyesuaian/',
        ];
        foreach ($rules as $cat => $re) {
            if (preg_match($re, $l) === 1) {
                return $cat;
            }
        }
        return 'lainnya';
    }

    /** @return array<string,string> */
    private static function categoryOverrides(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [
            // ---- Tokopedia: kolom total & pendapatan -------------------
            'jumlah penyelesaian pembayaran' => 'total',
            'total biaya'                    => 'total',
            'total penghasilan'              => 'total',
            // "Subtotal sebelum diskon" dipakai sebagai pendapatan kotor, dan
            // "Total Pendapatan" adalah hasil akhir setelah diskon+pengembalian.
            // Keduanya kolom total, bukan komponen yang boleh dijumlah lagi.
            'subtotal sebelum diskon'        => 'total',
            'total pendapatan'               => 'total',
            // Subtotal setelah diskon = sebelum diskon + diskon penjual.
            'subtotal setelah diskon penjual' => 'rincian',
            'diskon penjual'                  => 'potongan',
            'jumlah penyesuaian'              => 'penyesuaian',

            // Pengembalian dana: hanya versi "setelah diskon" yang dipakai.
            // Versi "sebelum diskon" + "pengembalian dana diskon penjual"
            // adalah pecahannya, jadi ditandai rincian agar tidak dobel.
            'subtotal pengembalian dana setelah diskon penjual' => 'refund',
            'subtotal pengembalian dana sebelum diskon penjual' => 'rincian',
            'pengembalian dana diskon penjual'                  => 'rincian',

            // Blok rincian pembayaran pembeli: keterangan cara pembeli membayar,
            // bukan bagian dari perhitungan settlement penjual.
            'pembayaran oleh pembeli'                                  => 'informasi',
            'pengembalian dana pembeli'                                => 'informasi',
            'diskon voucher yang ditanggung penjual'                   => 'informasi',
            'diskon platform'                                          => 'informasi',
            'diskon voucher yang ditanggung platform'                  => 'informasi',
            'diskon ongkir dari penjual'                               => 'informasi',
            'pengembalian dana diskon voucher yang ditanggung penjual' => 'informasi',
            'pengembalian dana diskon platform'                        => 'informasi',
            'pengembalian dana diskon voucher yang ditanggung platform' => 'informasi',
            // biaya
            'biaya komisi platform'          => 'komisi',
            'komisi dinamis'                 => 'komisi',
            // Tiga kolom berikut adalah rincian pembentuk "Biaya komisi platform",
            // bukan biaya tambahan. Diberi kategori 'rincian' supaya tidak
            // terhitung dua kali saat menjumlah biaya.
            'biaya komisi sebelum diskon'    => 'rincian',
            'diskon (dari belanja iklan)'    => 'rincian',
            'diskon komisi lainnya'          => 'rincian',
            'biaya layanan pre-order'        => 'layanan',
            'biaya layanan mall'             => 'layanan',
            'biaya akses keuntungan eksklusif' => 'layanan',
            'biaya layanan program eams'     => 'layanan',
            'biaya layanan penginstalan'     => 'layanan',
            'biaya layanan khusus platform'  => 'layanan',
            'program layanan terkelola (biaya per pesanan)' => 'layanan',
            'biaya pembayaran'               => 'pembayaran',
            'credit card installment - handling fee' => 'pembayaran',
            'biaya program paylater'         => 'pembayaran',
            'biaya transaksi'                => 'pembayaran',
            'biaya pemrosesan pesanan'       => 'proses',
            'biaya proses pesanan'           => 'proses',
            'biaya layanan cashback bonus'   => 'promosi',
            'biaya layanan khusus live'      => 'kampanye',
            'biaya layanan brands crazy deal/flash sale' => 'kampanye',
            'biaya sumber daya campaign'     => 'kampanye',
            'biaya kampanye'                 => 'kampanye',
            'biaya dilayani tokopedia'       => 'fulfillment',
            'biaya penanganan dilayani tokopedia' => 'fulfillment',
            'pph pasal 22 dipungut'          => 'pajak',
            'pajak penjualan atas voucher gmv max' => 'pajak',
            'program layanan terkelola (pajak penjualan)' => 'pajak',
            'bea masuk, ppn & pph'           => 'pajak',
            'biaya iklan gmv max'            => 'iklan',
            'voucher gmv max'                => 'iklan',
            'biaya asuransi'                 => 'asuransi',
            'penggantian dana asuransi'      => 'asuransi',
            'premi'                          => 'asuransi',
            // Kolom "Ongkir" pada ekspor Tokopedia adalah SUBTOTAL dari seluruh
            // baris ongkir di bawahnya (terbukti: nilainya persis sama dengan
            // jumlah baris-baris tersebut), sehingga tidak boleh ikut dijumlah.
            'ongkir'                         => 'rincian',
            'biaya layanan logistik'         => 'pengiriman',
            'biaya layanan program bebas ongkir' => 'pengiriman',
            'biaya program hemat biaya kirim' => 'pengiriman',
            'biaya isi saldo otomatis (dari penghasilan)' => 'lainnya',

            // ---- Shopee ------------------------------------------------
            // "Harga Asli Produk" adalah harga sebelum diskon apa pun.
            'harga asli produk'   => 'total',
            'harga produk'        => 'total',
            'kompensasi'          => 'lainnya',
            // Kolom baru pada format Shopee terkini.
            'pph 22'                => 'pajak',
            'ams service fee'       => 'layanan',
            'return to seller fee'  => 'pengiriman',
            'fbs fee'               => 'lainnya',
            // Sesuai pengelompokan Shopee sendiri, diskon produk dan voucher yang
            // ditanggung penjual masuk bagian PENDAPATAN (pengurang), bukan
            // "2. Total Pengeluaran". Dengan begini biaya platform yang tampil
            // sama persis dengan Total Pengeluaran pada laporan Shopee.
            'total diskon produk'                      => 'potongan',
            'diskon produk dari shopee'                => 'potongan',
            'voucher disponsor oleh penjual'           => 'potongan',
            'voucher co-fund disponsor oleh penjual'   => 'potongan',
            'cashback koin disponsori penjual'         => 'potongan',
            'cashback koin co-fund disponsori penjual' => 'potongan',
            'jumlah pengembalian dana ke pembeli'      => 'refund',
            // Blok rincian pengembalian barang di kolom paling kanan: pecahan
            // dari pengembalian di atas, tidak masuk ringkasan resmi Shopee.
            'pengembalian dana ke pembeli'                           => 'rincian',
            'pro-rata koin yang ditukarkan untuk pengembalian barang' => 'rincian',
            'pro-rata voucher shopee untuk pengembalian barang'       => 'rincian',
            'biaya komisi ams'                      => 'komisi',
            'biaya administrasi (termasuk ppn 11%)' => 'administrasi',
            'biaya layanan'                         => 'layanan',
        ];

        // Kolom "Pro-rated ..." versi Inggris dari Shopee.
        $map['pro-rated bank payment channel promotion for return refund items']   = 'rincian';
        $map['pro-rated shopee payment channel promotion for return refund items'] = 'rincian';

        return $map;
    }

    // =================================================================
    // Util
    // =================================================================

    public static function normLabel(string $s): string
    {
        $s = str_replace("\xc2\xa0", ' ', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
        return mb_strtolower($s);
    }

    /**
     * Bangun peta "nama kolom" => index dari baris judul.
     * @param array<int,string> $headerRow
     * @return array<string,int>
     */
    public static function headerIndex(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $idx => $label) {
            $key = self::normLabel((string) $label);
            if ($key === '' || isset($map[$key])) {
                continue; // kolom pertama menang bila ada judul kembar
            }
            $map[$key] = $idx;
        }
        return $map;
    }

    public static function normStatus(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        if ($s === '') {
            return 'lainnya';
        }
        if (str_contains($s, 'batal') || str_contains($s, 'cancel')) {
            return 'batal';
        }

        // Shopee kini menulis kalimat, bukan status:
        //   "Pesanan diterima, namun Pembeli masih dapat mengajukan
        //    pengembalian hingga 2026-08-04."
        // Itu pesanan yang SUDAH diterima; pengembaliannya baru sebatas
        // kemungkinan yang belum tentu terjadi. Klausa itu dibuang lebih dulu
        // supaya kata "pengembalian" di dalamnya tidak membuat pesanan yang
        // sebenarnya selesai ikut terhitung sebagai retur - pada satu berkas
        // sebulan, itu sepertiga dari seluruh pesanan.
        if (str_contains($s, 'mengajukan pengembalian')
            || str_contains($s, 'request a return')
            || str_contains($s, 'request return')) {
            $s = trim((string) preg_replace('/[,;]?\s*(namun|tetapi|tapi|but|however)\b.*$/u', '', $s));
        }

        // Urutannya penting: "pengembalian diterima" harus tetap retur, jadi
        // pemeriksaan retur didahulukan atas kata "diterima".
        if (str_contains($s, 'pengembalian') || str_contains($s, 'retur') || str_contains($s, 'refund')) {
            return 'retur';
        }
        if (str_contains($s, 'selesai') || str_contains($s, 'complete')
            || str_contains($s, 'diterima') || str_contains($s, 'delivered')
            || str_contains($s, 'received')) {
            return 'selesai';
        }
        return 'proses';
    }
}
