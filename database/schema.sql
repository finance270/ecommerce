-- =====================================================================
--  E-Commerce Analytics (Tokopedia + Shopee)
--  Skema database untuk MariaDB 11.4
--  Semua tabel idempotent: aman dijalankan berulang kali.
-- =====================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- Pengguna aplikasi
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(128) NULL,
  role          ENUM('admin','staff','viewer') NOT NULL DEFAULT 'staff',
  -- Daftar tab yang boleh dibuka (JSON array). NULL/kosong = belum diberi hak.
  -- Peran admin selalu punya seluruh akses dan mengabaikan kolom ini.
  permissions   TEXT         NULL,
  -- Akses kategori gaji pada menu Beban:
  --   all  = seluruh kategori
  --   only = HANYA kategori gaji
  --   none = seluruh kategori KECUALI gaji
  salary_access ENUM('all','only','none') NOT NULL DEFAULT 'all',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Riwayat upload berkas ekspor
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploads (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_name  VARCHAR(255) NOT NULL,
  stored_name    VARCHAR(255) NULL,
  file_hash      CHAR(64)     NOT NULL,
  size_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  platform       VARCHAR(16)  NULL,
  dataset        VARCHAR(32)  NULL,
  period_from    DATE         NULL,
  period_to      DATE         NULL,
  status         ENUM('pending','success','partial','failed') NOT NULL DEFAULT 'pending',
  rows_read      INT UNSIGNED NOT NULL DEFAULT 0,
  rows_inserted  INT UNSIGNED NOT NULL DEFAULT 0,
  rows_updated   INT UNSIGNED NOT NULL DEFAULT 0,
  rows_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
  rows_skipped   INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms    INT UNSIGNED NOT NULL DEFAULT 0,
  message        TEXT         NULL,
  detail_json    LONGTEXT     NULL,
  uploaded_by    INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_uploads_hash (file_hash),
  KEY idx_uploads_created (created_at),
  KEY idx_uploads_dataset (platform, dataset)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ORDERS : satu baris per pesanan (header)
-- Nilai level-pesanan pada file ekspor berulang di tiap baris SKU,
-- sehingga dipisah ke sini agar agregasi tidak dobel hitung.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform                 VARCHAR(16)  NOT NULL,
  order_id                 VARCHAR(64)  NOT NULL,
  channel                  VARCHAR(32)  NULL,
  status_raw               VARCHAR(64)  NULL,
  status_norm              ENUM('selesai','batal','proses','retur','lainnya') NOT NULL DEFAULT 'lainnya',
  substatus                VARCHAR(64)  NULL,
  order_type               VARCHAR(32)  NULL,
  return_status            VARCHAR(64)  NULL,
  cancel_by                VARCHAR(64)  NULL,
  cancel_reason            VARCHAR(255) NULL,

  created_at_pf            DATETIME     NULL,
  order_date               DATE         NULL,
  paid_at                  DATETIME     NULL,
  rts_at                   DATETIME     NULL,
  shipped_at               DATETIME     NULL,
  delivered_at             DATETIME     NULL,
  completed_at             DATETIME     NULL,
  cancelled_at             DATETIME     NULL,

  order_amount             DECIMAL(18,2) NOT NULL DEFAULT 0,
  items_subtotal_before    DECIMAL(18,2) NOT NULL DEFAULT 0,
  items_subtotal_after     DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_seller          DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_platform        DECIMAL(18,2) NOT NULL DEFAULT 0,
  voucher_seller           DECIMAL(18,2) NOT NULL DEFAULT 0,
  voucher_platform         DECIMAL(18,2) NOT NULL DEFAULT 0,
  coin_cashback            DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_fee_original    DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_fee_after_disc  DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_disc_seller     DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_disc_platform   DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_paid_buyer      DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_return_fee      DECIMAL(18,2) NOT NULL DEFAULT 0,
  buyer_service_fee        DECIMAL(18,2) NOT NULL DEFAULT 0,
  handling_fee             DECIMAL(18,2) NOT NULL DEFAULT 0,
  insurance_fee            DECIMAL(18,2) NOT NULL DEFAULT 0,
  refund_amount            DECIMAL(18,2) NOT NULL DEFAULT 0,

  total_qty                INT NOT NULL DEFAULT 0,
  total_qty_returned       INT NOT NULL DEFAULT 0,
  line_count               INT NOT NULL DEFAULT 0,
  weight_gram              INT NOT NULL DEFAULT 0,

  payment_method           VARCHAR(64)  NULL,
  shipping_provider        VARCHAR(64)  NULL,
  shipping_option          VARCHAR(64)  NULL,
  tracking_no              VARCHAR(64)  NULL,
  fulfillment_type         VARCHAR(64)  NULL,
  warehouse_name           VARCHAR(128) NULL,
  package_id               VARCHAR(64)  NULL,
  invoice_no               VARCHAR(64)  NULL,

  buyer_username           VARCHAR(128) NULL,
  recipient                VARCHAR(128) NULL,
  phone                    VARCHAR(64)  NULL,
  province                 VARCHAR(96)  NULL,
  city                     VARCHAR(96)  NULL,
  district                 VARCHAR(96)  NULL,
  village                  VARCHAR(96)  NULL,
  postal_code              VARCHAR(16)  NULL,
  address                  VARCHAR(512) NULL,
  buyer_message            VARCHAR(512) NULL,
  seller_note              VARCHAR(512) NULL,

  row_hash                 CHAR(40)     NOT NULL,
  upload_id                BIGINT UNSIGNED NULL,
  first_seen_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_platform_order (platform, order_id),
  KEY idx_orders_date (order_date),
  KEY idx_orders_status (platform, status_norm, order_date),
  KEY idx_orders_channel (channel),
  KEY idx_orders_province (province),
  KEY idx_orders_upload (upload_id),
  KEY idx_orders_alloc (platform, order_id, items_subtotal_before)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ORDER_ITEMS : satu baris per SKU dalam pesanan
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_pk              BIGINT UNSIGNED NOT NULL,
  platform              VARCHAR(16)  NOT NULL,
  order_id              VARCHAR(64)  NOT NULL,
  line_key              VARCHAR(80)  NOT NULL,
  line_no               SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  sku_id                VARCHAR(64)  NULL,
  seller_sku            VARCHAR(128) NULL,
  parent_sku            VARCHAR(255) NULL,
  product_name          VARCHAR(512) NULL,
  variation             VARCHAR(255) NULL,
  category              VARCHAR(128) NULL,

  qty                   INT NOT NULL DEFAULT 0,
  qty_returned          INT NOT NULL DEFAULT 0,
  unit_price            DECIMAL(18,2) NOT NULL DEFAULT 0,
  unit_price_after_disc DECIMAL(18,2) NOT NULL DEFAULT 0,
  subtotal_before_disc  DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_platform     DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_seller       DECIMAL(18,2) NOT NULL DEFAULT 0,
  subtotal_after_disc   DECIMAL(18,2) NOT NULL DEFAULT 0,
  refund_amount         DECIMAL(18,2) NOT NULL DEFAULT 0,
  buyer_service_fee     DECIMAL(18,2) NOT NULL DEFAULT 0,
  insurance_fee         DECIMAL(18,2) NOT NULL DEFAULT 0,
  weight_gram           INT NOT NULL DEFAULT 0,

  order_date            DATE NULL,
  status_norm           ENUM('selesai','batal','proses','retur','lainnya') NOT NULL DEFAULT 'lainnya',
  -- Kunci pencocokan HPP: sha1(nama produk|variasi) yang sudah dinormalkan.
  cost_key              CHAR(40) NULL,

  row_hash              CHAR(40) NOT NULL,
  upload_id             BIGINT UNSIGNED NULL,
  first_seen_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_items_line (platform, order_id, line_key),
  KEY idx_items_order (order_pk),
  KEY idx_items_sku (platform, sku_id),
  KEY idx_items_product (product_name(120)),
  KEY idx_items_date (order_date, status_norm),
  KEY idx_items_cost_key (cost_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SETTLEMENTS : rekap penghasilan / dana dilepas per pesanan
-- Tokopedia: satu pesanan bisa punya >1 baris (settlement + reversal),
-- karena itu kunci unik memakai trx_key.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settlements (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform          VARCHAR(16)  NOT NULL,
  trx_key           VARCHAR(160) NOT NULL,
  order_id          VARCHAR(64)  NOT NULL,
  related_order_id  VARCHAR(64)  NULL,
  trx_type          VARCHAR(96)  NULL,
  source_channel    VARCHAR(32)  NULL,
  currency          CHAR(3)      NOT NULL DEFAULT 'IDR',

  order_time        DATETIME     NULL,
  settlement_time   DATETIME     NULL,
  settlement_date   DATE         NULL,

  gross_amount      DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_seller   DECIMAL(18,2) NOT NULL DEFAULT 0,
  -- Jumlah seluruh komponen berkategori 'potongan' (diskon & voucher yang
  -- ditanggung penjual). Disimpan agar laporan tidak perlu menjumlah ulang
  -- tabel settlement_fees setiap kali.
  total_potongan    DECIMAL(18,2) NOT NULL DEFAULT 0,
  refund_amount     DECIMAL(18,2) NOT NULL DEFAULT 0,
  buyer_payment     DECIMAL(18,2) NOT NULL DEFAULT 0,
  adjustment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_fee         DECIMAL(18,2) NOT NULL DEFAULT 0,
  net_amount        DECIMAL(18,2) NOT NULL DEFAULT 0,

  fee_komisi        DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_layanan       DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_administrasi  DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_pembayaran    DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_proses        DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_pengiriman    DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_afiliasi      DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_iklan         DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_kampanye      DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_pajak         DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_asuransi      DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_fulfillment   DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_promosi       DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee_lainnya       DECIMAL(18,2) NOT NULL DEFAULT 0,

  payment_method    VARCHAR(64)  NULL,
  courier           VARCHAR(96)  NULL,
  buyer_username    VARCHAR(128) NULL,
  weight_gram       INT NOT NULL DEFAULT 0,

  row_hash          CHAR(40) NOT NULL,
  upload_id         BIGINT UNSIGNED NULL,
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_settlements_trx (platform, trx_key),
  KEY idx_settlements_order (platform, order_id),
  KEY idx_settlements_date (settlement_date),
  KEY idx_settlements_pf_date (platform, settlement_date),
  -- Index penutup untuk agregasi per pesanan (alokasi laba per produk):
  -- seluruh kolom yang dibutuhkan ada di index, jadi tabelnya tidak disentuh.
  KEY idx_settlements_alloc (platform, order_id, settlement_date, gross_amount,
                             total_potongan, refund_amount, total_fee, net_amount),
  KEY idx_settlements_type (platform, trx_type),
  KEY idx_settlements_upload (upload_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SETTLEMENT_FEES : rincian setiap komponen biaya (audit trail akuntansi)
-- Tidak ada satu pun kolom biaya dari file asli yang hilang.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settlement_fees (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_id  BIGINT UNSIGNED NOT NULL,
  platform       VARCHAR(16)  NOT NULL,
  order_id       VARCHAR(64)  NOT NULL,
  settlement_date DATE        NULL,
  fee_code       VARCHAR(120) NOT NULL,
  fee_label      VARCHAR(255) NOT NULL,
  fee_category   VARCHAR(24)  NOT NULL DEFAULT 'lainnya',
  amount         DECIMAL(18,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee (settlement_id, fee_code),
  KEY idx_fee_category (fee_category, settlement_date),
  KEY idx_fee_platform (platform, settlement_date),
  KEY idx_fee_date (settlement_date, platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- WITHDRAWALS : riwayat penarikan dana ke rekening bank
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS withdrawals (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform      VARCHAR(16)  NOT NULL,
  reference_id  VARCHAR(64)  NOT NULL,
  trx_type      VARCHAR(64)  NULL,
  requested_at  DATETIME     NULL,
  succeeded_at  DATETIME     NULL,
  withdraw_date DATE         NULL,
  amount        DECIMAL(18,2) NOT NULL DEFAULT 0,
  status        VARCHAR(48)  NULL,
  bank_account  VARCHAR(128) NULL,
  row_hash      CHAR(40)     NOT NULL,
  upload_id     BIGINT UNSIGNED NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_withdrawal (platform, reference_id),
  KEY idx_withdrawal_date (withdraw_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Arsip baris asli (jejak audit).
-- Dipisah dari tabel utama supaya baris orders/settlements tetap ramping:
-- kolom JSON berukuran 2-3 KB per baris membuat setiap perhitungan laporan
-- harus membaca ratusan MB tanpa perlu.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_raw (
  order_pk BIGINT UNSIGNED NOT NULL,
  raw_json LONGTEXT NULL,
  PRIMARY KEY (order_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settlement_raw (
  settlement_id BIGINT UNSIGNED NOT NULL,
  raw_json      LONGTEXT NULL,
  PRIMARY KEY (settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Kamus biaya: label asli platform -> kategori akuntansi
-- Diisi otomatis saat import; bisa diubah manual lewat phpMyAdmin.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fee_dictionary (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform    VARCHAR(16)  NOT NULL,
  fee_code    VARCHAR(120) NOT NULL,
  fee_label   VARCHAR(255) NOT NULL,
  category    VARCHAR(24)  NOT NULL DEFAULT 'lainnya',
  is_locked   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dict (platform, fee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Migrasi yang sudah dijalankan
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- VIEW: rekap harian penjualan (dipakai dashboard & laporan performa)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_daily_sales AS
SELECT
  o.platform,
  o.channel,
  o.order_date,
  COUNT(*)                                                       AS total_orders,
  SUM(o.status_norm = 'selesai')                                 AS orders_selesai,
  SUM(o.status_norm = 'batal')                                   AS orders_batal,
  SUM(CASE WHEN o.status_norm = 'selesai' THEN o.items_subtotal_after ELSE 0 END) AS gmv_bersih,
  SUM(CASE WHEN o.status_norm = 'selesai' THEN o.items_subtotal_before ELSE 0 END) AS gmv_kotor,
  SUM(CASE WHEN o.status_norm = 'selesai' THEN o.total_qty ELSE 0 END)             AS qty_terjual
FROM orders o
GROUP BY o.platform, o.channel, o.order_date;

-- ---------------------------------------------------------------------
-- VIEW: rekap harian settlement (dipakai laporan laba rugi)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_daily_settlement AS
SELECT
  s.platform,
  s.settlement_date,
  COUNT(*)               AS total_trx,
  SUM(s.gross_amount)    AS pendapatan_kotor,
  SUM(s.total_potongan)  AS potongan,
  SUM(s.refund_amount)   AS pengembalian,
  SUM(s.adjustment_amount) AS penyesuaian,
  SUM(s.total_fee)       AS total_biaya,
  SUM(s.net_amount)      AS dana_diterima,
  SUM(s.fee_komisi)     AS fee_komisi,
  SUM(s.fee_layanan)    AS fee_layanan,
  SUM(s.fee_administrasi) AS fee_administrasi,
  SUM(s.fee_pembayaran) AS fee_pembayaran,
  SUM(s.fee_proses)     AS fee_proses,
  SUM(s.fee_pengiriman) AS fee_pengiriman,
  SUM(s.fee_afiliasi)   AS fee_afiliasi,
  SUM(s.fee_iklan)      AS fee_iklan,
  SUM(s.fee_kampanye)   AS fee_kampanye,
  SUM(s.fee_pajak)      AS fee_pajak,
  SUM(s.fee_asuransi)   AS fee_asuransi,
  SUM(s.fee_fulfillment) AS fee_fulfillment,
  SUM(s.fee_promosi)    AS fee_promosi,
  SUM(s.fee_lainnya)    AS fee_lainnya
FROM settlements s
GROUP BY s.platform, s.settlement_date;

-- ---------------------------------------------------------------------
-- HPP (Harga Pokok Penjualan) per produk per bulan.
-- Dicocokkan lewat nama produk + variasi karena pada ekspor Tokopedia dan
-- Shopee kolom SKU penjual sebagian besar kosong.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_cost (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_ym     CHAR(7)      NOT NULL,               -- 'YYYY-MM'
  cost_key      CHAR(40)     NOT NULL,               -- sha1(nama|variasi) ternormalisasi
  product_name  VARCHAR(512) NOT NULL,
  variation     VARCHAR(255) NULL,
  sku           VARCHAR(128) NULL,                   -- keterangan saja, tidak dipakai mencocokkan
  cost_per_unit DECIMAL(18,2) NOT NULL DEFAULT 0,
  note          VARCHAR(255) NULL,
  row_hash      CHAR(40)     NOT NULL,
  upload_id     BIGINT UNSIGNED NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cost (period_ym, cost_key),
  KEY idx_cost_period (period_ym),
  KEY idx_cost_key (cost_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Beban operasional per bulan (gaji, sewa, listrik, iklan, dll).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS operating_expense (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_ym     CHAR(7)      NOT NULL,
  category      VARCHAR(64)  NOT NULL,
  description   VARCHAR(255) NOT NULL DEFAULT '',
  amount        DECIMAL(18,2) NOT NULL DEFAULT 0,
  expense_key   CHAR(40)     NOT NULL,               -- sha1(kategori|keterangan)
  row_hash      CHAR(40)     NOT NULL,
  upload_id     BIGINT UNSIGNED NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense (period_ym, expense_key),
  KEY idx_expense_period (period_ym),
  KEY idx_expense_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
