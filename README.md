# E-Commerce Analytics — Tokopedia & Shopee

Aplikasi PHP untuk mengolah berkas **hasil ekspor Tokopedia dan Shopee** menjadi laporan
akuntansi, keuangan, dan performa penjualan. Dirancang untuk dipakai rutin: berkas ekspor
diunggah ulang setiap minggu, **data lama tidak dobel, data baru bertambah, data yang berubah
ikut terkoreksi**.

Dibuat untuk stack yang sudah Anda pakai di CasaOS:

| Komponen   | Image                          |
| ---------- | ------------------------------ |
| Aplikasi   | `webdevops/php-apache:8.2`     |
| Database   | `lscr.io/linuxserver/mariadb:11.4.8` |
| Admin DB   | `phpmyadmin:latest`            |

Tanpa Composer dan tanpa akses internet — seluruh kode berjalan dengan ekstensi PHP bawaan
(`pdo_mysql`, `zip`/`zlib`, `xmlreader`, `mbstring`).

---

## 1. Cara Pasang

### a. Salin kode ke server

Letakkan folder project di host, misalnya `/DATA/AppData/ecommerce`.

### b. Atur container aplikasi

Yang **wajib** disetel pada container `webdevops/php-apache:8.2`:

| Environment              | Nilai              | Keterangan |
| ------------------------ | ------------------ | ---------- |
| `WEB_DOCUMENT_ROOT`      | `/app/public`      | **Wajib.** Kalau salah, aplikasi tidak terbuka |
| `DB_HOST`                | `mariadb`          | nama container MariaDB |
| `DB_PORT`                | `3306`             | |
| `DB_NAME`                | `ecommerce`        | |
| `DB_USER`                | `ecommerce`        | |
| `DB_PASS`                | (kata sandi Anda)  | |
| `PHP_UPLOAD_MAX_FILESIZE`| `64M`              | berkas Tokopedia bisa besar |
| `PHP_POST_MAX_SIZE`      | `64M`              | |
| `PHP_MEMORY_LIMIT`       | `768M`             | |
| `PHP_MAX_EXECUTION_TIME` | `900`              | |

Mount folder project ke `/app` di dalam container.

Bila lebih suka pakai compose, tersedia `docker-compose.yml` yang sudah berisi ketiga
container di atas — ubah dulu kata sandinya, lalu:

```bash
docker compose up -d
```

### c. Buat database

Di phpMyAdmin, buat database `ecommerce` dengan collation `utf8mb4_unicode_ci`, lalu buat
user `ecommerce` dan beri hak akses penuh ke database tersebut.

### d. Jalankan pemasangan

Buka `http://IP-SERVER:8080/setup.php`, isi username dan kata sandi administrator, klik
**Jalankan pemasangan**. Seluruh tabel dibuat otomatis.

> Alternatif: impor `database/schema.sql` lewat phpMyAdmin, lalu buka `setup.php` untuk
> membuat akun admin.

**Memperbarui aplikasi yang sudah jalan:** salin kode versi baru, lalu buka `setup.php` sekali
lagi dan klik **Jalankan pemasangan**. Halaman itu menambahkan kolom baru yang belum ada tanpa
menghapus data (`CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` yang dicek dulu). Kalau ada kolom
baru yang ditambahkan, halaman akan memberi tahu — cukup unggah ulang berkas terkait supaya
kolom itu terisi.

Setelah itu masuk lewat `http://IP-SERVER:8080/login.php`.

---

## 2. Berkas yang Didukung

Unggah berkas `.xlsx` **asli** dari platform (jangan dibuka-dan-disimpan-ulang di Excel).
Jenis berkas dikenali otomatis.

| Berkas ekspor | Sheet | Yang diambil |
| --- | --- | --- |
| Tokopedia — **Semua Pesanan** | `OrderSKUList` | Pesanan + rincian SKU, status, alamat, ongkir, kurir |
| Tokopedia — **Transaksi/Penghasilan** | `Detail pesanan`, `Riwayat penarikan` | Settlement per pesanan, seluruh komponen biaya, penarikan dana ke bank |
| Shopee — **Order** | `orders` | Pesanan + rincian produk, status, alamat, ongkir |
| Shopee — **Laporan Penghasilan** | `Income`, `Service Fee Details` | Penghasilan per pesanan, biaya admin/layanan + rinciannya |

Bisa mengunggah beberapa berkas sekaligus.

---

## 3. Cara Kerja Anti-Dobel

Ini bagian terpenting untuk rutinitas upload mingguan.

**a. Kunci alami + `UNIQUE` di database.** Setiap baris punya identitas yang stabil antar
ekspor, dan kunci itu dipasang `UNIQUE` sehingga baris kembar **mustahil** masuk:

| Data | Kunci unik |
| --- | --- |
| Pesanan | `platform` + `order_id` |
| Baris produk | `platform` + `order_id` + `line_key` (SKU/varian) |
| Settlement | `platform` + `trx_key` (`order_id` + jenis transaksi + tanggal dana dilepaskan) |
| Penarikan dana | `platform` + `reference_id` |

Settlement sengaja memakai kunci gabungan karena **satu pesanan bisa punya lebih dari satu
baris settlement** — misalnya pembayaran awal lalu pembalikan (refund) pada tanggal berbeda.
Kalau hanya memakai nomor pesanan, baris refund justru akan hilang.

**b. Sidik jari baris (`row_hash`).** Setiap baris menyimpan SHA-1 dari seluruh nilainya.
Saat berkas diunggah ulang:

- isi **sama** → baris dilewati, database tidak disentuh sama sekali;
- isi **berubah** (mis. status jadi *Dibatalkan*, atau ada refund) → baris **di-UPDATE**;
- baris **baru** → ditambahkan.

Hasilnya, berkas periode panjang (mis. Januari–Juni) aman diunggah setiap minggu.

**c. Nilai agregat dihitung ulang, bukan ditambahkan.** Total qty dan omzet per pesanan
dihitung ulang dari baris produknya dengan satu perintah SQL setelah import, sehingga tidak
akan pernah menggelembung walau berkas diunggah berkali-kali.

Setiap hasil upload ditampilkan apa adanya: berapa **baru**, berapa **diperbarui**, berapa
**sudah sama**. Kalau Anda mengunggah berkas yang sama dua kali, angka "baru" dan "diperbarui"
akan **0**.

---

## 4. Isi Aplikasi

| Halaman | Kegunaan |
| --- | --- |
| **Dashboard** | Omzet, pesanan, rata-rata per pesanan, tingkat pembatalan, dana diterima, tren harian |
| **Upload Data** | Unggah berkas + laporan hasil import per sheet |
| **Pesanan** | Cari/filter seluruh pesanan, buka detail per pesanan |
| **Produk** | Produk & varian terlaris, qty terjual, omzet, retur |
| **Performa** | Rekap mingguan & bulanan + pertumbuhan, metode bayar, kurir, provinsi |
| **Laba & Biaya** | Ringkasan seluruh pengurang dari pendapatan kotor sampai dana diterima, jembatan angka per platform, rekap bulanan, **laba bersih per produk**, struktur biaya per kategori, rincian tiap komponen biaya, penarikan dana |
| **Settlement** | Daftar settlement per pesanan beserta komponen biayanya |
| **Rekonsiliasi** | Pesanan selesai yang dananya belum cair (piutang platform), dan settlement yang berkas pesanannya belum diunggah |
| **Riwayat Upload** | Catatan setiap berkas yang pernah diproses |

Semua laporan bisa diekspor ke **CSV** (UTF-8 + pemisah `;`, langsung rapi di Excel Indonesia).

### Apa itu "pendapatan kotor"

Supaya Tokopedia dan Shopee bisa dibandingkan setara, **pendapatan kotor** selalu memakai nilai
penjualan **sebelum diskon apa pun**:

| Platform | Kolom yang dipakai |
| --- | --- |
| Tokopedia | `Subtotal sebelum diskon` |
| Shopee | `Harga Asli Produk` |

Kolom `Total Pendapatan` milik Tokopedia **tidak** dipakai sebagai angka kotor, karena kolom itu
sudah dikurangi diskon penjual dan pengembalian dana. Terbukti dari data:

```
Subtotal sebelum diskon                              901.139.200
Diskon penjual                                      -130.880.352
Subtotal pengembalian dana setelah diskon penjual     -28.803.999
= Total Pendapatan                                   741.454.849
```

Dengan memakai `Subtotal sebelum diskon`, diskon yang Anda tanggung tampil sebagai baris
tersendiri di halaman **Laba & Biaya**, bukan tersembunyi di dalam angka kotor.

Hal yang sama berlaku untuk Shopee: diskon produk dan voucher yang disponsori penjual
dikelompokkan sebagai **potongan pendapatan** (mengikuti pengelompokan Shopee sendiri pada
bagian *1. Total Pendapatan*), bukan sebagai biaya platform. Karena itu angka **Biaya platform**
yang tampil sama persis dengan *2. Total Pengeluaran* pada laporan Shopee.

Jembatan angkanya selalu berimbang, dan **seluruh pengurang ditampilkan** di halaman
**Laba & Biaya** — pada ringkasan, pada jembatan per platform, maupun pada rekap bulanan:

```
pendapatan kotor + diskon & voucher penjual + pengembalian dana
+ biaya platform + penyesuaian + selisih pencatatan = dana diterima bersih
```

### Laba bersih per produk

Platform hanya memberi angka settlement **per pesanan**, tidak per produk. Untuk mengetahui
bersih tiap produk, aplikasi membagi nilai settlement ke setiap baris produk sesuai porsinya:

```
porsi produk = nilai produk sebelum diskon / total nilai pesanan sebelum diskon
```

Angka per produk karenanya berupa **alokasi**, bukan angka resmi platform per produk — tapi
totalnya tetap sama persis dengan total settlement pesanan yang ikut terhitung, jadi tidak ada
nilai yang bocor atau tercipta.

Karena butuh isi pesanan, produk hanya bisa dihitung untuk pesanan yang **berkas pesanan dan
berkas penghasilannya sudah sama-sama diunggah**. Halaman menampilkan berapa persen dana bersih
yang berhasil dipecah ke produk, supaya Anda tahu kalau angkanya belum mencakup semua.

### Dua sudut pandang tanggal

Ini penting agar angka tidak salah tafsir:

- **Performa penjualan** memakai **tanggal pesanan** (`orders`).
- **Akuntansi & keuangan** memakai **tanggal dana dilepaskan** (`settlements`), karena itulah
  saat kas benar-benar diterima.

Karena itu kedua kelompok laporan dipisah dan tidak dicampur dalam satu angka.

---

## 5. Struktur Database

| Tabel | Isi |
| --- | --- |
| `orders` | Satu baris per pesanan (header) |
| `order_items` | Satu baris per SKU dalam pesanan |
| `settlements` | Settlement/penghasilan per pesanan + 14 kolom kategori biaya |
| `settlement_fees` | **Setiap** komponen biaya dengan nama asli platform (jejak audit) |
| `withdrawals` | Penarikan dana ke rekening bank |
| `fee_dictionary` | Pemetaan nama biaya platform → kategori akuntansi |
| `uploads` | Riwayat setiap berkas yang diproses |
| `users` | Pengguna aplikasi |

Nilai level-pesanan pada berkas ekspor **berulang di setiap baris SKU**. Karena itu data
dipecah menjadi `orders` + `order_items`; kalau disimpan datar dalam satu tabel, omzet
pesanan multi-produk akan terhitung berlipat.

### Mengubah kategori biaya

Kategori akuntansi bisa disesuaikan lewat phpMyAdmin di tabel `fee_dictionary`. Ubah kolom
`category`, lalu set `is_locked = 1` supaya tidak tertimpa saat import berikutnya.

---

## 6. Catatan Teknis

**Berkas ekspor Tokopedia tidak valid secara struktur XLSX.** Pada sheet `OrderSKUList`,
setiap sel dibungkus elemen `<row>` sendiri:

```xml
<row r="1"><c r="A1"><v>Order ID</v></c></row>
<row r="1"><c r="B1"><v>Order Status</v></c></row>
```

Pustaka pembaca Excel pada umumnya (termasuk PhpSpreadsheet dan openpyxl) mengandalkan elemen
`<row>`, sehingga **hanya membaca 1 kolom** dari berkas ini. Aplikasi ini memakai pembaca
sendiri (`src/XlsxReader.php`) yang mengabaikan elemen `<row>` dan mengelompokkan sel
berdasarkan nomor baris pada atribut `r=` milik sel — berkas normal maupun yang rusak sama-sama
terbaca utuh.

Pembacaan dilakukan secara *streaming*: sheet Tokopedia berukuran 26 MB saat di-unzip terbaca
penuh (6.672 baris × 65 kolom) dalam ±1,5 detik dengan pemakaian memori hanya ±2 MB.

**Angka sudah diverifikasi terhadap laporan resmi platform.** Seluruh total hasil import cocok
persis (sampai rupiah) dengan angka pada sheet ringkasan bawaan berkas ekspor — `Laporan`
milik Tokopedia dan `Summary` milik Shopee.

**Kolom subtotal dikecualikan agar tidak dobel hitung.** Beberapa kolom pada berkas platform
sebenarnya adalah subtotal atau pecahan dari kolom lain. Kolom seperti ini diberi kategori
`rincian` (atau `informasi`) sehingga tetap tersimpan untuk penelusuran, tapi tidak ikut
dijumlahkan sebagai biaya:

- `Ongkir` (Tokopedia) — nilainya persis sama dengan jumlah seluruh baris ongkir di bawahnya;
- `Biaya komisi sebelum diskon` — rincian pembentuk `Biaya komisi platform`;
- `Subtotal setelah diskon penjual` = `Subtotal sebelum diskon` + `Diskon penjual`;
- `Subtotal pengembalian dana sebelum diskon penjual` + `Pengembalian dana diskon penjual`
  = versi `setelah diskon` yang dipakai;
- blok rincian pembayaran pembeli (Tokopedia) dan rincian pengembalian barang (Shopee) —
  keterangan, bukan bagian perhitungan settlement penjual.

Hasilnya, jumlah seluruh kategori biaya **sama persis** dengan kolom total biaya resmi
platform untuk kedua platform.

**Format angka & tanggal** ditangani otomatis: rupiah gaya Shopee (`75.000`), rupiah polos
gaya Tokopedia (`-63750`), berat `500 gr` maupun kolom `Weight(kg)`, serta tanggal
`30/06/2026 22:06:49`, `2026-01-01 00:57`, `2026/03/30`, dan `2026-01-31`.

---

## 7. Pemakaian Rutin

1. Ekspor berkas dari Seller Center Tokopedia dan Shopee seperti biasa.
2. Buka **Upload Data**, seret semua berkas sekaligus, klik **Unggah & Proses**.
3. Periksa ringkasan hasil: berapa baru / diperbarui / sudah sama.
4. Buka **Dashboard**, **Performa**, dan **Laba & Biaya** untuk analisis.
5. Cek **Rekonsiliasi** untuk melihat penjualan yang dananya belum cair.

Tidak perlu menghapus data lama sebelum mengunggah — justru jangan, karena aplikasi
mengandalkan data lama untuk mendeteksi perubahan.

---

## 8. Masalah Umum

| Gejala | Penyebab & solusi |
| --- | --- |
| Halaman blank / 404 | `WEB_DOCUMENT_ROOT` belum diset ke `/app/public` |
| "Tidak bisa terhubung ke database" | Cek `DB_HOST` (nama container MariaDB), user & kata sandi |
| "Ukuran berkas melebihi batas PHP" | Naikkan `PHP_UPLOAD_MAX_FILESIZE` dan `PHP_POST_MAX_SIZE` |
| "Jenis berkas tidak dikenali" | Berkas bukan ekspor asli, atau sudah diedit ulang di Excel. Bisa juga pilih jenis berkas manual di halaman upload |
| Proses berhenti di tengah | Naikkan `PHP_MAX_EXECUTION_TIME`. Import bersifat idempotent — cukup unggah ulang, prosesnya melanjutkan tanpa menduplikasi |
