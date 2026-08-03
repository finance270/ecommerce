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

### c. Setel memori MariaDB (penting kalau data sudah banyak)

Bawaan MariaDB hanya menyimpan **128 MB** data di memori (`innodb_buffer_pool_size`). Setelah
beberapa bulan upload, tabel laporan jadi lebih besar dari itu sehingga setiap rekap harus
membaca disk dan halaman terasa lambat. Jalankan container MariaDB dengan:

```
--innodb-buffer-pool-size=512M
```

Naikkan sesuai RAM server (aman: sekitar setengah RAM). Pada `docker-compose.yml` yang
disertakan, setelan ini sudah ada.

### d. Buat database

Di phpMyAdmin, buat database `ecommerce` dengan collation `utf8mb4_unicode_ci`, lalu buat
user `ecommerce` dan beri hak akses penuh ke database tersebut.

### e. Beberapa perusahaan (opsional)

Satu pemasangan bisa melayani **beberapa PT sekaligus**, masing-masing dengan
**databasenya sendiri**. Data penjualannya terpisah total.

Ada dua cara. Yang disarankan adalah **akun pusat**, karena PT baru bisa ditambahkan
langsung dari aplikasi tanpa menyentuh berkas apa pun.

#### Cara 1 &mdash; akun pusat (disarankan)

Buka `daftar.php` sekali sebagai admin, lalu daftarkan satu email. Email itu menjadi
**pemilik aplikasi**, dan PT yang sudah ada otomatis ikut terdaftar atas namanya.

Setelah itu:

| Keperluan | Tempatnya |
| --- | --- |
| Menambah PT (database dibuat otomatis) | menu **Perusahaan** &rarr; Tambah PT |
| Mengubah nama PT dan nama databasenya | menu **Perusahaan** &rarr; kolom Perusahaan / Database |
| Menentukan siapa boleh membuka PT mana | menu **Perusahaan** &rarr; Pengguna |
| Menentukan tab apa yang dia lihat di dalam PT | menu **Pengguna** di PT tersebut |
| Berpindah PT | nama PT di kanan atas |

Pembagiannya sengaja begitu: database pusat (`CENTRAL_DB`, default `ecom_pusat`) hanya
menyimpan daftar akun dan daftar PT &mdash; **tidak ada data penjualan di sana**. Hak akses
per tab tetap tersimpan di database PT masing-masing, sehingga satu orang bisa jadi admin
penuh di PT A dan hanya melihat laba rugi di PT B, dengan satu email yang sama.

Peran di halaman Perusahaan:

| Peran | Artinya |
| --- | --- |
| `pemilik` | mengatur siapa saja yang boleh membuka PT itu |
| `admin` | memegang seluruh tab di dalam PT itu |
| `staf` | hanya tab yang dicentang di menu Pengguna |

Menambah PT hanya bisa dilakukan akun pemilik aplikasi (email pertama). Akun lain
dibuatkan olehnya dari halaman Perusahaan.

**Mengganti nama database.** MariaDB tidak punya perintah untuk itu, jadi aplikasi
memindahkan seluruh tabelnya ke database bernama baru lalu menghapus yang lama.
Pemindahannya hanya mengubah catatan &mdash; datanya tidak disalin &mdash; sehingga selesai
dalam hitungan detik berapa pun besarnya (95.000 pesanan + 771.000 baris biaya: 0,35 detik).
View dibuat ulang dari `schema.sql` karena definisinya menyebut nama database lama.
Database lama hanya dihapus setelah dipastikan kosong, dan nama tujuan ditolak bila
sudah dipakai. Tetap lakukan saat tidak ada yang sedang mengunggah berkas.

#### Cara 2 &mdash; berkas `config/tenants.json` (cara lama)

Tetap dilayani, dan masuknya memakai **username per database**. Buat
`config/tenants.json` (contohnya ada di `config/tenants.json.contoh`):

```json
[
  { "kode": "anomali", "nama": "Anomali Coffee", "db": "ecommerce" },
  { "kode": "ptkedua", "nama": "PT Kedua",       "db": "ecommerce_ptkedua" }
]
```

Bisa juga lewat environment: `TENANTS=anomali|Anomali Coffee|ecommerce;ptkedua|PT Kedua|ecommerce_ptkedua`.

Kalau berkas dan environment-nya tidak ada, aplikasi berjalan seperti biasa dengan satu database
dari `DB_NAME` &mdash; pemasangan lama tidak perlu diubah apa pun.

Tiap perusahaan punya tautannya sendiri lewat parameter `?db=`:

| Keperluan | Tautan |
| --- | --- |
| Masuk biasa | `.../login.php?db=ptkedua` |
| Pemasangan / migrasi | `.../setup.php?db=ptkedua` |
| Tautan direksi | `.../direksi.php?db=ptkedua` |

Databasenya **dibuat otomatis** saat pemasangan bila belum ada, jadi cukup buka
`setup.php?db=<kode>` sekali untuk tiap perusahaan.

> Berpindah perusahaan selalu **mengosongkan sesi**: id pengguna hanya berlaku di database
> asalnya, jadi membawanya ke database lain bisa membuat seseorang masuk sebagai orang yang
> sama sekali berbeda. Menambahkan `?db=` pada URL saat sedang masuk akan melempar ke halaman
> masuk perusahaan tujuan.

### f. Jalankan pemasangan

Buka `http://IP-SERVER:8080/setup.php`, isi username dan kata sandi administrator, klik
**Jalankan pemasangan**. Seluruh tabel dibuat otomatis.

> Alternatif: impor `database/schema.sql` lewat phpMyAdmin, lalu buka `setup.php` untuk
> membuat akun admin.

**Memperbarui aplikasi yang sudah jalan:** salin kode versi baru, lalu buka `setup.php` sekali
lagi dan klik **Jalankan pemasangan**. Halaman itu menambahkan kolom/index baru tanpa menghapus
data, dan memindahkan arsip baris asli ke tabel terpisah. Kalau ada perubahan yang diterapkan,
halaman akan memberi tahu — cukup unggah ulang berkas terkait supaya kolom baru terisi.

> Pada database yang sudah besar, langkah ini menyusun ulang tabel `orders` dan `settlements`
> sehingga **bisa berjalan beberapa menit**. Jalankan sekali saja dan tunggu sampai selesai.

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
| Shopee — **Laporan Penghasilan** | `Income` / `Penghasilan`, `Service Fee Details` / `Seller Fee` | Penghasilan per pesanan, biaya admin/layanan + rinciannya |

Bisa mengunggah beberapa berkas sekaligus.

> **Format Shopee berubah dari waktu ke waktu.** Sheet `Income` kini bernama `Penghasilan`,
> kolom `Harga Asli Produk` jadi `Harga Produk`, dan kolom `Total Penghasilan` dihapus &mdash;
> dana yang dilepas dihitung dari komponennya. Lembar itu juga memuat tiap pesanan **dua kali**:
> sekali sebagai pesanan (`Lihat berdasarkan` = `Order`) dan sekali dipecah per SKU (`Sku`).
> Hanya baris pesanan yang diambil; kalau keduanya ikut, seluruh nilai jadi dobel.
> Kedua format sama-sama dilayani, jadi berkas lama tetap bisa diunggah ulang.

Selain berkas platform, ada dua berkas yang Anda isi sendiri lewat **template** yang disediakan
aplikasi: **HPP per produk** (menu HPP) dan **beban operasional** (menu Beban). Lihat bagian
*HPP dan beban operasional* di bawah.

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
| **Laba & Biaya** | Ringkasan lengkap dari pendapatan kotor sampai **laba usaha** (biaya platform & beban operasional bisa dibuka rinciannya, bisa dicetak jadi PDF), jembatan angka per platform, rekap bulanan, **laba per produk setelah HPP**, struktur biaya per kategori, rincian tiap komponen biaya, penarikan dana |
| **HPP** | Impor HPP per produk per bulan + **pemantauan produk yang belum ada HPP** |
| **Beban** | Impor beban operasional per bulan (gaji, sewa, listrik, packaging, iklan, dll) |
| **Settlement** | Daftar settlement per pesanan beserta komponen biayanya |
| **Pengembalian** | Refund per bulan, per produk, dan per transaksi &mdash; laporan tersendiri |
| **Rekonsiliasi** | Pesanan selesai yang dananya belum cair (piutang platform), dan settlement yang berkas pesanannya belum diunggah |
| **Monitoring** | Periode mana yang datanya belum diperbarui, berkas terakhir diunggah, dan settlement yang berkas pesanannya belum masuk |
| **Riwayat Upload** | Catatan setiap berkas yang pernah diproses |
| **Pengguna** | *(admin saja)* Buat akun, atur tab yang boleh dibuka, atur hak atas data gaji, dan ambil **tautan direksi** |

Semua laporan bisa diekspor ke **CSV** (UTF-8 + pemisah `;`, langsung rapi di Excel Indonesia).

### Apa itu "pendapatan kotor"

Supaya Tokopedia dan Shopee bisa dibandingkan setara, **pendapatan kotor** selalu memakai nilai
penjualan **sebelum diskon apa pun**, lalu **dikurangi pengembalian dana** (lihat
*Pengembalian dana* di bawah):

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
  pendapatan kotor   (sudah dikurangi pengembalian)
- diskon & voucher yang ditanggung penjual
- biaya platform
+ penyesuaian
= dana diterima bersih
- HPP (harga pokok penjualan)
= laba kotor
- beban operasional
= laba usaha
```

HPP dan beban operasional dicocokkan pada **bulan settlement** yang sama, supaya biaya dan
pendapatannya berada pada periode yang sama.

### Pengembalian dana (refund)

Barang yang direfund **kembali ke penjual**, jadi penjualannya memang tidak pernah jadi.
Menampilkannya sebagai omzet penuh lalu menguranginya lagi di baris terpisah membuat omzet
terlihat lebih besar dari yang sebenarnya terjadi.

Karena itu **pendapatan kotor di seluruh laporan sudah bersih dari refund** — di ringkasan
laba rugi, jembatan per platform, rekap bulanan, laporan per produk, rincian produk, halaman
Settlement, dan semua ekspor CSV. Tidak ada lagi baris "pengembalian dana" sebagai pengurang,
karena nilainya sudah tidak pernah ikut dihitung sebagai pendapatan.

Rinciannya dipindah ke menu **Pengembalian** tersendiri:

- total refund, rasionya terhadap kotor **sebelum** refund, dan berapa pesanan yang terkena;
- refund per bulan per platform, dengan rasio terhadap kotor **seluruh bulan itu** (bukan hanya
  transaksi yang kena refund — kalau begitu rasionya akan terbaca seolah hampir seluruh bulan
  dikembalikan);
- produk yang paling banyak dikembalikan, dialokasikan memakai porsi subtotal sebelum diskon;
- daftar transaksi refund terbesar, masing-masing bisa dibuka ke rincian pesanannya.

Rasio terhadap kotor sengaja memakai pembanding **sebelum** refund, karena itulah dasar yang
benar untuk mengukur seberapa besar tingkat pengembalian.

### HPP dan beban operasional

Supaya laporan menjadi laba-rugi yang utuh, ada dua data yang Anda isi sendiri karena tidak ada
di berkas ekspor platform:

**HPP (harga pokok penjualan)** &mdash; menu **HPP**. Diisi per produk per bulan lewat impor:

1. Unduh template (Excel atau CSV). Template **sudah terisi daftar produk yang benar-benar
   terjual**, lengkap dengan HPP yang sudah pernah diisi untuk bulan itu.
2. Isi kolom *HPP per Unit*. Baris yang dikosongkan dilewati, jadi boleh dicicil.
3. Unggah kembali.

Pencocokan memakai **nama produk + variasi**, bukan SKU &mdash; pada berkas ekspor Tokopedia dan
Shopee kolom SKU penjual sebagian besar kosong (pada data contoh: Shopee 1,2%, Tokopedia 44%).
Karena itu jangan mengubah kolom *Nama Produk* dan *Variasi* pada template.

**Beban operasional** &mdash; menu **Beban**. Gaji, sewa, listrik, packaging, iklan, dan
lainnya, dicatat per bulan. Kategori bebas Anda tentukan sendiri. Baris dikunci per
*bulan + kategori + keterangan*, jadi berkas yang sama boleh diunggah ulang tanpa dobel.

Keduanya menerima **.xlsx maupun .csv** (pemisah `;` atau `,`), dan angka boleh ditulis dengan
titik ribuan seperti `17.500.000`.

### Monitoring kelengkapan data

Menu **Monitoring** menjawab pertanyaan "periode mana yang belum saya update":

- **Berkas terakhir diunggah** untuk tiap jenis (4 berkas platform + HPP + beban), lengkap
  dengan umurnya. Lewat seminggu ditandai, lewat dua minggu ditandai lebih keras.
- **Kelengkapan per bulan**: jumlah pesanan, jumlah settlement, persentase *alokasi produk*,
  kelengkapan HPP, dan apakah beban operasional sudah diisi.
- **Settlement yang belum ada data pesanannya** — inilah penyebab angka seperti
  "99,8% pesanan yang bisa dipecah ke produk". Daftarnya bisa difilter per bulan dan diekspor,
  jadi Anda tahu persis berkas periode mana yang perlu diunggah.

Persentase kelengkapan dihitung dari **jumlah pesanan**, bukan nilainya, karena nilai settlement
bisa negatif (pembalikan/refund) sehingga persentase berbasis nilai dapat melewati 100% dan
membingungkan.

### Uji kewajaran HPP

Untuk produk yang HPP-nya **sudah** diisi, menu **HPP** menguji kewajarannya:

Rantai nilainya berlaku seragam di **Laba & Biaya**, **simulasi harga**, **uji kewajaran HPP**,
**rincian produk**, dan **ekspor CSV** — semuanya memakai `Reports::rantaiLaba()` yang sama,
sehingga satu produk tidak pernah menunjukkan angka berbeda antar halaman:

```
  harga jual terdaftar                    100%     % dari harga jual
- pengembalian dana (bila ada)
- potongan & diskon ditanggung penjual              % dari harga jual
= harga setelah dikurang diskon                     harga jual − diskon      ← dasar hitung pajak
- biaya platform                                    % dari harga jual
= dana diterima bersih                              setelah diskon − biaya platform
- PPN 11%                                           11% × harga setelah dikurang diskon
- pajak e-commerce 0,5%                             0,5% × harga setelah dikurang diskon
= penjualan bersih                                  dana diterima − PPN − pajak   ← dasar hitung marjin
- HPP                                               % dari penjualan bersih
= laba kotor                                        % dari penjualan bersih
- beban operasional                                 (hanya di Laba & Biaya)
= laba usaha                                        % dari penjualan bersih
```

Yang perlu diperhatikan:

- **Pajak dihitung dari harga setelah dikurang diskon**, bukan dari harga jual — diskon mengurangi
  dasar pengenaannya lebih dulu.
- **Marjin memakai penyebut penjualan bersih**, dan **labanya juga sudah setelah pajak**. Baris HPP
  dan laba diukur terhadap penjualan bersih; baris lain terhadap harga jual. Kolom *Catatan / acuan*
  di tiap tabel menyebutkan dasarnya, jadi tidak perlu menebak.
- Pada **Laba & Biaya**, *dana diterima bersih* tetap sama persis dengan angka resmi platform —
  penyesuaian dan selisih pencatatan ikut diperhitungkan, jadi jembatan angkanya tetap berimbang.

> Marjin dengan dasar ini **lebih kecil** daripada sebelumnya, karena pajak ikut dipotong dan
> penyebutnya lebih besar. Kalau rentang wajar Anda dulu dikalibrasi terhadap dasar lama, angkanya
> perlu diturunkan — ketiga ambangnya memang bisa diubah dari halaman.

Yang dianggap wajar adalah sebuah **rentang**, bukan sekadar batas atas. Nilai awalnya
**60%–80%**, angka yang sehat untuk produk kopi bubuk/biji:

| Status | Kondisi | Artinya |
| --- | --- | --- |
| Wajar | marjin 60%–80% | sehat, tidak ada indikasi salah isi |
| Marjin tipis | di bawah 60% | harga jual terlalu rendah atau HPP terlalu tinggi |
| Perlu dicek | di atas 80% | HPP kemungkinan terlalu kecil atau belum lengkap |
| Sangat tidak wajar | marjin ≥ 100% | HPP nyaris nol dibanding pendapatan, hampir pasti salah |
| Jual rugi | marjin negatif | HPP melebihi dana yang diterima |

Ketiga ambang (60%, 80%, 100%) bisa diubah langsung dari halaman karena tiap jenis produk
berbeda. Tabelnya menampilkan **HPP per unit** berdampingan dengan **pendapatan bersih per unit**,
sehingga salah isi angka langsung kelihatan.

Baris yang statusnya bukan "Wajar" punya tombol **Simulasi harga** di kolom paling kanan.

> Karena nilai 0 pada template diabaikan (lihat di bawah), HPP yang tersimpan selalu lebih besar
> dari nol sehingga marjin tidak pernah persis 100%. Klasifikasinya memakai angka yang
> dibulatkan ke 1 desimal — sama seperti yang ditampilkan — supaya HPP sebesar Rp 1 pada produk
> ratusan ribu tetap tertangkap sebagai "sangat tidak wajar".

### Rincian per produk

**Nama produk pada tabel uji kewajaran bisa diklik** dan membuka halaman rincian produk tersebut.
Isinya:

- **Rantai nilai** dari pendapatan kotor sampai laba bersih — potongan, biaya platform, lalu
  HPP — masing-masing disertai **porsinya terhadap pendapatan kotor** dan
  **nilai per unit**, jadi langsung terbayang berapa persen yang benar-benar tersisa jadi laba.
- **Pecahan per platform** dan **per bulan settlement**: pesanan, qty, kotor, potongan, dana
  bersih, HPP, dan laba, masing-masing dengan porsinya terhadap total.
- **Daftar pesanan** yang memuat produk itu. Tiap barisnya bisa dibuka untuk melihat rincian
  pesanan utuhnya, sama seperti dari tab Pesanan.

Nilai pada halaman ini adalah **bagian produk tersebut saja** dari tiap pesanan, dialokasikan
memakai porsi subtotal sebelum diskon — cara yang sama dengan laporan laba per produk, sehingga
angkanya konsisten antar halaman.

Dua hal yang sengaja tidak dipaksakan supaya angkanya tidak menyesatkan:

- Porsi hanya ditampilkan kalau seluruh baris pada kolom itu **searah**. Kolom laba bisa
  bercampur — ada bulan untung, ada bulan rugi — dan totalnya adalah selisihnya. Kalau porsinya
  dipaksakan, bulan yang untung akan tampak menyumbang minus dan bulan yang paling rugi tampak
  menyumbang 225%. Dalam keadaan begitu kolom porsinya dikosongkan.
- Bulan yang sebagian unitnya **belum punya HPP** ditandai (`n unit tanpa HPP`) dan marjinnya
  diberi label `semu`, karena bagian itu dihitung HPP = 0 sehingga marjinnya tampak lebih besar.

Daftar pesanan memuat nomor pesanan dan nama pembeli — itu isi tab Pesanan — jadi bagian tersebut
mengikuti hak akses tab **Pesanan**, bukan hak akses HPP. Pengguna yang hanya diberi tab HPP tetap
bisa melihat ringkasan dan pecahannya, tetapi tidak daftar pesanannya.

### Simulasi harga jual

Produk yang marjinnya di luar rentang wajar punya tombol **Simulasi harga**, yang membuka
**tab baru** berisi halaman tersendiri (`simulasi.php`) — terpisah supaya bisa dipakai fokus dan
dicetak apa adanya. Halaman ini menjawab satu pertanyaan: *berapa harga jual yang diperlukan
supaya marjin bersih setelah HPP sesuai target?*

Dasar hitungnya adalah **harga jual terdaftar**, yaitu `subtotal_before_disc` pada baris produk —
harga yang benar-benar Anda pasang di Tokopedia/Shopee. Ini **bukan** pendapatan kotor hasil
settlement: pendapatan kotor di laporan sudah dikurangi pengembalian dana, jadi kalau dipakai
di sini harga yang muncul akan lebih rendah daripada harga di etalase.

Persentase pengurangnya diambil dari **pesanan terakhir yang sudah selesai dan lengkap biayanya**.
Pesanan yang batal atau yang biayanya belum tercatat tidak dipakai, karena tidak mewakili tarif
apa pun. **HPP** diambil dari **bulan terakhir yang terisi**.

Histori terakhir **tiap platform** ditampilkan berdampingan — nomor pesanan, tanggal, harga jual
per unit, persentase potongan, dan persentase biaya platform. Bila platform dibiarkan "Semua
platform", yang dipakai sebagai dasar simulasi adalah yang **harga jualnya paling tinggi**; baris
lain punya tombol **Pakai ini** untuk berpindah.

Halaman menyebutkan nomor pesanan, tanggal dana dilepas, dan bulan HPP yang dipakai, sehingga
angkanya bisa ditelusuri.

Rantai nilainya, semua diukur terhadap harga jual:

```
  harga jual terdaftar (termasuk PPN)   100%
- PPN 11% keluaran
= peredaran bruto (DPP, tanpa PPN)
- PPh Pasal 22 e-commerce 0,5% × DPP
- pengembalian dana (refund)
- potongan & diskon                     ← bisa dibuka rinciannya
- biaya platform                        ← bisa dibuka rinciannya
± penyesuaian & selisih
= dana bersih setelah pajak
- HPP per unit
= laba bersih  →  marjin
```

### Pajak pada simulasi

**PPN 11%.** Harga di etalase dianggap **sudah termasuk PPN**, jadi bagian PPN-nya bukan
pendapatan penjual melainkan titipan yang disetor ke negara. DPP dihitung mundur
(`harga ÷ 1,11`). Isi **0** kalau bukan Pengusaha Kena Pajak.

Ada juga pilihan **HPP termasuk PPN masukan yang bisa dikreditkan** — bagi PKP, PPN yang dibayar
saat membeli barang bisa dikreditkan sehingga modal efektifnya `HPP ÷ 1,11`. Nilai awalnya
**dimatikan**, karena tidak semua pemasok menerbitkan faktur pajak; lebih aman menganggap tidak
bisa dikreditkan lalu dinyalakan bila memang bisa.

**PPh Pasal 22 e-commerce 0,5% — PMK 37/2025.** Marketplace ditunjuk memungut PPh Pasal 22 dari
pedagang dalam negeri:

| Hal | Ketentuan |
| --- | --- |
| Tarif | 0,5% |
| Dasar pengenaan | peredaran bruto **tidak termasuk PPN/PPnBM** — karena itu dikalikan DPP, bukan harga jual |
| Dipungut oleh | marketplace, saat pembayaran diterima (bukan disetor sendiri seperti dulu) |
| Sifat | bukan pajak baru dan bukan tambahan beban: jadi pengurang PPh Final terutang, atau kredit pajak di SPT Tahunan |
| Bebas | orang pribadi dengan peredaran bruto sampai Rp 500 juta setahun, dengan menyampaikan surat pernyataan ke marketplace |
| Pemungut pertama | Tokopedia, Shopee, Lazada, Blibli — efektif **1 Agustus 2026** |

Walau bisa dikreditkan, kasnya tetap keluar lebih dulu sehingga tetap diperhitungkan saat
menentukan harga. Isi **0** kalau Anda termasuk yang dibebaskan.

> Kalau pesanan acuan **sudah** dipungut PPh oleh marketplace (data setelah Agustus 2026),
> nilainya sudah termasuk di biaya platform. Aplikasi mendeteksinya dan menolkan tarif PPh
> otomatis supaya tidak terhitung dua kali.

Karena pajak memotong lebih dulu, marjin setelah pajak selalu lebih kecil daripada marjin di
**Uji kewajaran HPP** (yang murni dari data settlement). Supaya tidak membingungkan, halaman
simulasi menyebut keduanya — misalnya *"Marjin 65,0% berada di rentang wajar. Sebelum pajak
marjinnya 70,7% — itu angka yang dipakai uji kewajaran HPP."*

**Rincian potongan dan biaya platform** bisa dibuka per komponen dengan nama asli dari berkas
platform (biaya komisi, biaya layanan, biaya administrasi, ongkir, dan seterusnya). Tanda nilainya
dipertahankan: sebagian komponen ongkir justru **menambah** (diganti platform atau dibayar
pembeli), sehingga jumlah rinciannya selalu sama persis dengan baris ringkasannya.

**Semua angka bisa diubah manual** — harga jual, marjin target, persentase potongan, persentase
biaya platform, dan HPP per unit:

- isi **harga jual** → marjin yang didapat langsung terlihat;
- isi **marjin yang diinginkan** → harga jual yang diperlukan langsung dihitung
  (dibulatkan ke atas per Rp 100);
- ubah **persentase atau HPP** kalau Anda tahu tarifnya akan berubah — seluruh hitungan
  menyesuaikan, termasuk baris rinciannya.

Begitu ada yang diubah, halaman memberi catatan bahwa angkanya **tidak lagi mengikuti histori**
(catatan ini ikut tercetak, supaya lembar PDF-nya tidak disalahpahami sebagai data asli).
Tombol **Reset ke histori terakhir** mengembalikan semuanya.

Rumusnya: `harga = HPP ÷ (1 − marjin) ÷ porsi dana bersih`. Karena potongan dan biaya platform
dianggap tetap sebagai persentase, menaikkan harga juga menaikkan komisi — itu sebabnya menaikkan
harga tidak menaikkan marjin seluruhnya.

**Cetak / simpan PDF** tersedia di halaman itu. Yang tercetak persis yang terlihat: kalau rincian
sedang dibuka, rinciannya ikut tercetak; kalau ditutup, hanya ringkasannya. Menu, tombol, kolom
isian, dan penyaring tidak ikut tercetak.

> Kalau platform dibiarkan "Semua platform", pesanan terakhir bisa berasal dari platform mana pun.
> Pilih salah satu platform untuk tarif yang benar-benar pas untuk platform itu.
>
> Produk yang **belum punya HPP** tetap bisa disimulasikan — isi saja HPP per unitnya manual.
> Angka ini panduan, bukan janji — harga baru bisa mengubah jumlah penjualan.

### Nilai 0 pada template dianggap belum diisi

Baik pada template HPP maupun beban operasional, baris yang diisi **0** diperlakukan sama dengan
baris kosong: dilewati, tidak disimpan. Kalau 0 ikut tersimpan, produknya akan terlihat "sudah
ada HPP" padahal labanya dihitung seolah tanpa modal — persis kesalahan yang ingin dicegah oleh
pemantauan di atas.

Aturan ini berlaku juga saat membaca data: baris bernilai 0 tidak pernah ikut dijumlahkan di
laporan mana pun. Baris 0 yang terlanjur tersimpan sebelum aturan ini ada akan **dihapus otomatis**
saat migrasi dijalankan, supaya isi database sama persis dengan yang tampil di laporan.

### Pemantauan HPP yang belum diisi

Menu **HPP** menampilkan kelengkapan per bulan &mdash; berapa produk terjual, berapa yang belum
ada HPP-nya, dan persentase kelengkapannya. Daftar produk yang belum ada HPP diurutkan dari
**nilai penjualan terbesar**, jadi yang paling berpengaruh ke laba bisa dikerjakan lebih dulu.

Selama HPP belum diisi, produk tersebut dihitung **HPP = 0** sehingga labanya tampak lebih besar
dari kenyataan. Halaman Laba &amp; Biaya memberi peringatan jelas kalau ini terjadi, jadi Anda
tidak akan salah membaca angka tanpa sadar.

### Laba per produk

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

### Hak akses pengguna

Menu **Pengguna** (hanya terlihat oleh admin) mengatur tiga hal untuk tiap akun.

**1. Tab mana yang boleh dibuka.** Centang tab yang boleh diakses. Tab yang tidak dicentang hilang
dari menu **dan** halamannya menolak dibuka kalau alamatnya diketik langsung — jadi ini
pengamanan sungguhan, bukan sekadar menyembunyikan menu. Ekspor CSV ikut aturan yang sama:
laporan hanya bisa diunduh oleh yang boleh membuka tabnya.

**2. Hak atas data gaji.** Khusus menu Beban, tiap akun dapat salah satu dari:

| Pilihan | Artinya |
| --- | --- |
| **Semua kategori** | Melihat dan mengunggah seluruh beban, termasuk gaji |
| **Hanya kategori gaji** | Hanya melihat dan mengunggah gaji; kategori lain tidak tampak dan ditolak saat diunggah |
| **Tanpa kategori gaji** | Melihat dan mengunggah semua kecuali gaji; baris gaji ditolak saat diunggah |

Aturan ini berlaku menyeluruh — daftar beban, rekap per kategori, total di Laba &amp; Biaya, dan
ekspor CSV semuanya ikut tersaring, jadi angka gaji tidak bisa terbaca lewat jalur lain. Kalau ada
beban yang disembunyikan, halaman Laba &amp; Biaya memberi tahu bahwa **laba usaha yang tampil belum
final**, supaya tidak ada yang salah mengambil kesimpulan.

Kategori dianggap gaji bila namanya memuat salah satu kata: `gaji`, `upah`, `salary`, `payroll`,
`thr`, `tunjangan`. Karena itu beri nama kategori dengan jelas — tulis "Gaji Karyawan", bukan
"Beban Personalia" yang tidak akan terdeteksi.

**3. Tautan khusus direksi.** Menu Pengguna menampilkan tautan `direksi.php?db=<kode>` yang bisa
dibagikan ke direksi. Pembukanya hanya diminta **kata sandi**, tanpa username, dan hanya bisa
membuka tab yang dicentang pada akun `direksi` &mdash; bawaannya hanya **Laba & Biaya**.

Akun ini punya pengaman tersendiri: tidak bisa dipakai di halaman masuk biasa, tidak bisa
dihapus (hanya dinonaktifkan), dan tidak bisa dijadikan admin &mdash; tautannya terbuka dengan
kata sandi pendek, jadi tidak boleh membuka seluruh aplikasi. Kata sandi awal dari pemasangan
adalah `123`; **ganti dulu sebelum tautannya dibagikan**.

Pada pemasangan beberapa perusahaan, tautan ini **berbeda untuk tiap PT** dan kata sandinya
tersimpan di database masing-masing, jadi sandi direksi PT A tidak berlaku di PT B.

**4. Menghapus data hanya untuk admin.** Pengguna biasa tetap bisa **mengunggah ulang untuk
menimpa** data yang salah seperti sebelumnya, tetapi tombol hapus hanya muncul untuk admin — dan
permintaan hapus dari akun non-admin ditolak di server, bukan cuma disembunyikan tombolnya.

Admin terakhir tidak bisa diturunkan perannya atau dinonaktifkan, jadi aplikasi tidak akan pernah
terkunci tanpa admin.

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
| `users` | Pengguna aplikasi + hak akses (`permissions` berisi daftar tab, `salary_access` mengatur data gaji) |

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
