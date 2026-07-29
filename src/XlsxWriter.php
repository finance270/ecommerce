<?php
declare(strict_types=1);

/**
 * Penulis XLSX minimal tanpa dependensi, dipakai membuat berkas template impor.
 *
 * Hanya mendukung satu sheet, teks inline, angka, judul kolom tebal, dan lebar
 * kolom - cukup untuk keperluan template dan tidak menambah beban aplikasi.
 */
final class XlsxWriter
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @param string[]              $header judul kolom
     * @param array<int,array<int,mixed>> $rows  isi baris
     * @param int[]                 $widths lebar kolom (karakter)
     * @param string[]              $notes  baris keterangan di atas judul kolom
     */
    public static function write(
        string $path,
        string $sheetName,
        array $header,
        array $rows,
        array $widths = [],
        array $notes = []
    ): void {
        $sheet = self::sheetXml($header, $rows, $widths, $notes);

        $parts = [
            '[Content_Types].xml'      => self::contentTypes(),
            '_rels/.rels'              => self::rootRels(),
            'xl/workbook.xml'          => self::workbook($sheetName),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml'            => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Gagal membuat berkas template.');
            }
            foreach ($parts as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            return;
        }

        self::writeZipManually($path, $parts);
    }

    // -----------------------------------------------------------------

    private static function sheetXml(array $header, array $rows, array $widths, array $notes): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::NS . '">';

        if ($widths !== []) {
            $out .= '<cols>';
            foreach ($widths as $i => $w) {
                $out .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $out .= '</cols>';
        }

        $out .= '<sheetData>';
        $r = 1;

        foreach ($notes as $note) {
            $out .= '<row r="' . $r . '">' . self::cell(0, $r, $note, 2) . '</row>';
            $r++;
        }
        if ($notes !== []) {
            $r++; // satu baris kosong pemisah
        }

        $out .= '<row r="' . $r . '">';
        foreach (array_values($header) as $i => $h) {
            $out .= self::cell($i, $r, $h, 1);
        }
        $out .= '</row>';
        $r++;

        foreach ($rows as $row) {
            $out .= '<row r="' . $r . '">';
            foreach (array_values($row) as $i => $v) {
                $out .= self::cell($i, $r, $v, 0);
            }
            $out .= '</row>';
            $r++;
        }

        return $out . '</sheetData></worksheet>';
    }

    private static function cell(int $col, int $row, mixed $value, int $style): string
    {
        $ref = self::colName($col) . $row;
        $s = $style > 0 ? ' s="' . $style . '"' : '';

        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
        }

        $text = (string) $value;
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . '</t></is></c>';
    }

    private static function colName(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $n--;
            $name = chr(65 + ($n % 26)) . $name;
            $n = intdiv($n, 26);
        }
        return $name;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        $name = htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . self::NS . '" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Gaya 0 = biasa, 1 = judul tebal, 2 = keterangan abu-abu. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . self::NS . '">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF6B7A90"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * Penulis ZIP sederhana (metode "stored") bila ekstensi zip tidak terpasang.
     * Berkas template kecil, jadi tanpa kompresi pun tidak masalah.
     */
    private static function writeZipManually(string $path, array $parts): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Gagal menulis berkas template.');
        }

        $central = '';
        $offset = 0;
        foreach ($parts as $name => $data) {
            $crc = crc32($data);
            $len = strlen($data);
            $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0) . $name;
            fwrite($fh, $local . $data);

            $central .= "PK\x01\x02" . pack(
                'vvvvvvVVVvvvvvVV',
                20, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset
            ) . $name;
            $offset += strlen($local) + $len;
        }

        $n = count($parts);
        fwrite($fh, $central);
        fwrite($fh, "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), $offset, 0));
        fclose($fh);
    }
}
