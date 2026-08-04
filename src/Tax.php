<?php
declare(strict_types=1);

/**
 * Tarif pajak yang dipakai simulasi harga jual.
 *
 * Angka di sini hanya NILAI AWAL - seluruhnya bisa diubah di halaman simulasi,
 * karena tarif dan status tiap penjual berbeda. Aplikasi ini bukan pengganti
 * konsultan pajak; yang dihitung di sini semata untuk memperkirakan harga.
 *
 * ------------------------------------------------------------------
 * PPN 11%
 * ------------------------------------------------------------------
 * Harga jual di etalase Tokopedia/Shopee umumnya SUDAH termasuk PPN, jadi
 * bagian PPN-nya bukan pendapatan penjual melainkan titipan yang disetor ke
 * negara. Dasar pengenaannya dihitung mundur:
 *
 *     DPP = harga jual / (1 + tarif PPN)
 *     PPN = harga jual - DPP
 *
 * Bagi Pengusaha Kena Pajak, PPN yang dibayar saat membeli barang (PPN
 * masukan) bisa dikreditkan sehingga modal efektifnya berkurang. Nilai awalnya
 * dimatikan karena tidak semua pemasok menerbitkan faktur pajak - lebih aman
 * menganggap tidak bisa dikreditkan lalu dinyalakan bila memang bisa.
 *
 * ------------------------------------------------------------------
 * PPh Pasal 22 e-commerce 0,5% - PMK 37/2025
 * ------------------------------------------------------------------
 * Marketplace ditunjuk memungut PPh Pasal 22 sebesar 0,5% dari peredaran
 * bruto pedagang dalam negeri. Pokok-pokoknya:
 *
 *   - Tarif 0,5%, dasar pengenaannya peredaran bruto TIDAK TERMASUK PPN dan
 *     PPnBM. Karena itu di aplikasi ini 0,5% dikalikan DPP, bukan harga jual.
 *     Rumus DJP untuk pengusaha kena pajak:
 *
 *         PPh 22 = (harga jual - diskon penjual) / (1 + tarif PPN) x 0,5%
 *
 *     dan untuk non-PKP tanpa pembagian itu. Diskon yang ditanggung
 *     marketplace maupun ongkos kirim tidak mengurangi dasarnya.
 *
 *     CATATAN LAPANGAN: pada berkas penghasilan Shopee Agustus 2026, pajak
 *     yang benar-benar dipungut = 0,5% x harga produk PERSIS, tanpa dibagi
 *     1,11 - artinya penjualnya diperlakukan sebagai non-PKP. Angka di
 *     aplikasi ini memakai rumus PKP, jadi akan lebih kecil daripada yang
 *     dipotong platform bila status PKP-nya berbeda.
 *   - Dipungut saat pembayaran diterima marketplace, bukan disetor sendiri
 *     oleh penjual seperti sebelumnya.
 *   - Bukan pajak baru dan bukan tambahan beban: nilainya diperhitungkan
 *     sebagai pengurang PPh Final terutang, atau menjadi kredit pajak pada
 *     SPT Tahunan. Meski begitu kasnya tetap berkurang lebih awal, jadi tetap
 *     diperhitungkan saat menentukan harga.
 *   - Orang pribadi dengan peredaran bruto sampai Rp 500 juta setahun tidak
 *     dipungut, dengan menyampaikan surat pernyataan ke marketplace.
 *   - DJP menunjuk Tokopedia, Shopee, Lazada, dan Blibli sebagai pemungut
 *     pertama, berlaku efektif 1 Agustus 2026.
 *
 * Sumber: PMK 37/2025 dan siaran pers DJP 1 Juli 2026.
 */
final class Tax
{
    /** Tarif PPN atas penjualan (persen). */
    public const PPN_PERSEN = 11.0;

    /** Tarif PPh Pasal 22 e-commerce (persen dari peredaran bruto tanpa PPN). */
    public const PPH_PERSEN = 0.5;

    /** Sejak kapan marketplace mulai memungut PPh Pasal 22. */
    public const PPH_MULAI = '2026-08-01';

    /** Batas peredaran bruto orang pribadi yang tidak dipungut PPh Pasal 22. */
    public const PPH_BEBAS_OMZET = 500_000_000;

    /** Marketplace yang sudah ditunjuk sebagai pemungut. */
    public const PEMUNGUT = ['tokopedia', 'shopee', 'lazada', 'blibli'];

    /** Apakah pemungutan PPh sudah berlaku pada tanggal tertentu. */
    public static function pphBerlaku(?string $tanggal = null): bool
    {
        return ($tanggal ?? date('Y-m-d')) >= self::PPH_MULAI;
    }
}
