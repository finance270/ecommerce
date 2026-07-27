<?php
declare(strict_types=1);

/**
 * Normalisasi nilai mentah dari berkas ekspor.
 *
 * Catatan format yang ditemukan pada berkas asli:
 *   - Shopee menulis rupiah dengan pemisah ribuan titik: "75.000", "161.904"
 *   - Tokopedia menulis rupiah polos: "-63750"
 *   - Berat Shopee: "500 gr"; berat Tokopedia: kolom "Weight(kg)" nilai "1.299"
 *   - Tanggal: "30/06/2026 22:06:49", "2026-01-01 00:57", "2026/03/30", "2026-01-31"
 */
final class Value
{
    private const EMPTY_TOKENS = ['', '-', '/', 'n/a', 'null', '#n/a'];

    public static function text(mixed $v, int $maxLen = 0): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        $s = str_replace("\xc2\xa0", ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        if ($s === '' || in_array(mb_strtolower($s), ['-', '/'], true)) {
            return null;
        }
        if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }
        return $s;
    }

    /**
     * Nilai uang rupiah. Titik diperlakukan sebagai pemisah ribuan bila
     * polanya kelompok 3 digit ("75.000" => 75000).
     */
    public static function money(mixed $v): float
    {
        $s = self::cleanNumeric($v);
        if ($s === null) {
            return 0.0;
        }

        $neg = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $neg = true;
            $s = substr($s, 1, -1);
        }
        if (str_starts_with($s, '-')) {
            $neg = true;
            $s = ltrim($s, '-');
        }
        $s = ltrim($s, '+');

        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {          // 1.234.567,89
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {    // 1,234,567.89
            $s = str_replace(',', '', $s);
        } elseif (preg_match('/^\d+,\d+$/', $s)) {                     // 1234,56
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^\d+\.\d{3}$/', $s)) {                  // 75.000 => ribuan
            $s = str_replace('.', '', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        if (!is_numeric($s)) {
            $s = preg_replace('/[^0-9.]/', '', $s) ?? '0';
        }
        $n = (float) $s;
        return $neg ? -$n : $n;
    }

    /** Angka desimal biasa (titik = koma desimal), mis. berat "1.299" kg. */
    public static function decimal(mixed $v): float
    {
        $s = self::cleanNumeric($v);
        if ($s === null) {
            return 0.0;
        }
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9.\-]/', '', $s) ?? '';
        if ($s === '' || !is_numeric($s)) {
            return 0.0;
        }
        return (float) $s;
    }

    public static function int(mixed $v): int
    {
        return (int) round(self::decimal($v));
    }

    /** Berat menjadi gram. $defaultUnit dipakai bila nilai tidak menyertakan satuan. */
    public static function grams(mixed $v, string $defaultUnit = 'g'): int
    {
        $s = self::text($v);
        if ($s === null) {
            return 0;
        }
        $lower = mb_strtolower($s);
        $num = self::decimal($lower);
        if ($num === 0.0) {
            return 0;
        }
        if (str_contains($lower, 'kg')) {
            return (int) round($num * 1000);
        }
        if (preg_match('/\b(gr|gram|g)\b/', $lower)) {
            return (int) round($num);
        }
        return (int) round($defaultUnit === 'kg' ? $num * 1000 : $num);
    }

    private const DATE_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
        'Y/m/d H:i:s', 'Y/m/d H:i', 'Y/m/d',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
        'd/m/y H:i', 'd/m/y',
    ];

    /** Kembalikan 'Y-m-d H:i:s' atau null. */
    public static function dateTime(mixed $v): ?string
    {
        $s = self::text($v);
        if ($s === null) {
            return null;
        }
        $s = str_replace(['T', 'WIB', 'wib'], [' ', '', ''], $s);
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        // Angka murni: kemungkinan serial Excel yang belum sempat dikonversi.
        if (preg_match('/^\d+(\.\d+)?$/', $s) && (float) $s > 20000 && (float) $s < 2958465) {
            $conv = XlsxReader::excelSerialToDate((float) $s);
            if ($conv !== null) {
                $s = $conv;
            }
        }

        foreach (self::DATE_FORMATS as $fmt) {
            $dt = DateTime::createFromFormat('!' . $fmt, $s);
            $err = DateTime::getLastErrors();
            $bad = is_array($err) && (($err['error_count'] ?? 0) > 0 || ($err['warning_count'] ?? 0) > 0);
            if ($dt instanceof DateTime && !$bad) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    public static function dateOnly(mixed $v): ?string
    {
        $dt = self::dateTime($v);
        return $dt === null ? null : substr($dt, 0, 10);
    }

    /** Kode stabil untuk nama kolom biaya. */
    public static function slug(string $label, int $maxLen = 110): string
    {
        $s = mb_strtolower(trim($label));
        $s = str_replace(['%', '&', '+'], [' persen ', ' dan ', ' plus '], $s);
        $s = preg_replace('/[^a-z0-9]+/u', '_', $s) ?? $s;
        $s = trim($s, '_');
        if ($s === '') {
            $s = 'kolom';
        }
        return mb_substr($s, 0, $maxLen);
    }

    private static function cleanNumeric(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = trim((string) $v);
        $s = str_replace(["\xc2\xa0", ' ', 'Rp', 'RP', 'rp', 'IDR', 'idr'], '', $s);
        if ($s === '' || in_array(mb_strtolower($s), self::EMPTY_TOKENS, true)) {
            return null;
        }
        return $s;
    }
}
