<?php
declare(strict_types=1);

/**
 * Pembaca XLSX streaming tanpa dependensi eksternal (tanpa composer).
 *
 * Kenapa tidak pakai PhpSpreadsheet?
 *   1. Container webdevops/php-apache umumnya dipakai tanpa akses internet,
 *      sehingga `composer install` merepotkan.
 *   2. File ekspor Tokopedia "Semua pesanan" TIDAK VALID secara struktur:
 *      setiap sel dibungkus elemen <row> sendiri, contoh:
 *          <row r="1"><c r="A1"><v>Order ID</v></c></row>
 *          <row r="1"><c r="B1"><v>Order Status</v></c></row>
 *      Parser yang mengandalkan elemen <row> hanya akan membaca 1 kolom.
 *      Kelas ini mengabaikan elemen <row> dan mengelompokkan sel berdasarkan
 *      nomor baris pada atribut r= milik sel itu sendiri, sehingga file yang
 *      normal maupun yang rusak sama-sama terbaca utuh.
 *   3. Sheet Tokopedia berukuran 26 MB saat di-unzip; dibaca streaming agar
 *      memori tetap kecil.
 */
final class XlsxReader
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private string $path;
    /** @var array<string,string> nama sheet => path entry di dalam zip */
    private array $sheets = [];
    /** @var string[] */
    private array $sharedStrings = [];
    private bool $sharedLoaded = false;
    /** @var array<int,bool> index cellXf => apakah format tanggal */
    private array $dateStyles = [];
    private bool $stylesLoaded = false;
    /** @var string[] berkas sementara yang harus dihapus */
    private array $tempFiles = [];

    public function __construct(string $path)
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Berkas tidak dapat dibaca: {$path}");
        }
        $this->path = $path;
        $this->loadWorkbook();
    }

    public function __destruct()
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * Membaca sheet baris demi baris.
     *
     * @return Generator<int,array<int,string>> nomor baris Excel => [index kolom => nilai]
     */
    public function rows(string $sheetName, int $maxRows = 0): Generator
    {
        if (!isset($this->sheets[$sheetName])) {
            throw new RuntimeException("Sheet '{$sheetName}' tidak ditemukan.");
        }
        $this->loadSharedStrings();
        $this->loadStyles();

        $uri = $this->entryUri($this->sheets[$sheetName]);

        $reader = new XMLReader();
        if (@$reader->open($uri) === false) {
            throw new RuntimeException("Gagal membuka sheet '{$sheetName}'.");
        }

        $currentRow = -1;
        $buffer     = [];
        $emitted    = 0;

        try {
            if (!$reader->read()) {
                return;
            }
            while (true) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                    $ref = (string) $reader->getAttribute('r');
                    $type = $reader->getAttribute('t');
                    $style = $reader->getAttribute('s');
                    $inner = $reader->isEmptyElement ? '' : $reader->readInnerXml();

                    if ($ref !== '') {
                        [$col, $row] = self::refToPos($ref);

                        if ($row !== $currentRow) {
                            if ($currentRow > 0 && $buffer !== []) {
                                ksort($buffer);
                                yield $currentRow => $buffer;
                                $emitted++;
                                if ($maxRows > 0 && $emitted >= $maxRows) {
                                    return;
                                }
                            }
                            $currentRow = $row;
                            $buffer = [];
                        }

                        $value = $this->cellValue($inner, $type, $style);
                        if ($value !== null && $value !== '') {
                            $buffer[$col] = $value;
                        }
                    }

                    if (!$reader->next()) {
                        break;
                    }
                    continue;
                }

                if (!$reader->read()) {
                    break;
                }
            }

            if ($currentRow > 0 && $buffer !== []) {
                ksort($buffer);
                yield $currentRow => $buffer;
            }
        } finally {
            $reader->close();
        }
    }

    /** Ambil beberapa baris pertama, dipakai untuk deteksi jenis berkas. */
    public function peek(string $sheetName, int $rows = 12): array
    {
        $out = [];
        foreach ($this->rows($sheetName, $rows) as $n => $row) {
            $out[$n] = $row;
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    private function loadWorkbook(): void
    {
        $wbXml = $this->entryContents('xl/workbook.xml');
        if ($wbXml === null) {
            throw new RuntimeException('Berkas bukan XLSX yang valid (xl/workbook.xml tidak ada).');
        }
        $relXml = $this->entryContents('xl/_rels/workbook.xml.rels') ?? '';

        $rels = [];
        if ($relXml !== '') {
            $relDoc = @simplexml_load_string($relXml);
            if ($relDoc !== false) {
                foreach ($relDoc->Relationship as $rel) {
                    $rels[(string) $rel['Id']] = (string) $rel['Target'];
                }
            }
        }

        $wb = @simplexml_load_string($wbXml);
        if ($wb === false) {
            throw new RuntimeException('Gagal membaca struktur workbook.');
        }
        foreach ($wb->sheets->sheet as $sheet) {
            $name = (string) $sheet['name'];
            $rid  = (string) $sheet->attributes(self::NS_REL)['id'];
            $target = $rels[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            $target = ltrim($target, '/');
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $this->sheets[$name] = $target;
        }
    }

    private function loadSharedStrings(): void
    {
        if ($this->sharedLoaded) {
            return;
        }
        $this->sharedLoaded = true;

        $uri = $this->entryUriOrNull('xl/sharedStrings.xml');
        if ($uri === null) {
            return;
        }

        $reader = new XMLReader();
        if (@$reader->open($uri) === false) {
            return;
        }
        try {
            if (!$reader->read()) {
                return;
            }
            // Pola kursor sama seperti rows(): readInnerXml() membiarkan kursor
            // tetap di elemen <si>, jadi lanjutkan dengan next() lalu continue.
            // Memanggil read() setelah next() akan MELEWATI satu <si> dan
            // membuat seluruh indeks shared string bergeser.
            while (true) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $inner = $reader->isEmptyElement ? '' : $reader->readInnerXml();
                    $this->sharedStrings[] = self::collectText($inner);
                    if (!$reader->next()) {
                        break;
                    }
                    continue;
                }
                if (!$reader->read()) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function loadStyles(): void
    {
        if ($this->stylesLoaded) {
            return;
        }
        $this->stylesLoaded = true;

        $xml = $this->entryContents('xl/styles.xml');
        if ($xml === null) {
            return;
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return;
        }

        // Format tanggal bawaan Excel.
        $builtin = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];
        $custom  = [];
        if (isset($doc->numFmts)) {
            foreach ($doc->numFmts->numFmt as $fmt) {
                $id   = (int) $fmt['formatCode'] === 0 ? (int) $fmt['numFmtId'] : (int) $fmt['numFmtId'];
                $code = (string) $fmt['formatCode'];
                // Kode yang mengandung y/d/h dan bukan escape dianggap tanggal.
                $stripped = preg_replace('/\[[^\]]*\]|"[^"]*"|\\\\./', '', $code) ?? '';
                if (preg_match('/[ymdhs]/i', $stripped)) {
                    $custom[$id] = true;
                }
            }
        }

        if (isset($doc->cellXfs)) {
            $i = 0;
            foreach ($doc->cellXfs->xf as $xf) {
                $numFmtId = (int) $xf['numFmtId'];
                $this->dateStyles[$i] = in_array($numFmtId, $builtin, true) || isset($custom[$numFmtId]);
                $i++;
            }
        }
    }

    private function cellValue(string $inner, ?string $type, ?string $style): ?string
    {
        if ($inner === '') {
            return null;
        }

        if ($type === 's') {
            $raw = self::firstTag($inner, 'v');
            if ($raw === null) {
                return null;
            }
            $idx = (int) $raw;
            return $this->sharedStrings[$idx] ?? null;
        }

        if ($type === 'inlineStr') {
            return self::collectText($inner);
        }

        if ($type === 'e') { // error formula, mis. #N/A
            return null;
        }

        $raw = self::firstTag($inner, 'v');
        if ($raw === null) {
            // Rumus tanpa nilai ter-cache (<f> tanpa <v>): tidak ada nilai yang
            // bisa dipakai. Jangan kembalikan teks rumusnya sebagai data.
            if (str_contains($inner, '<f')) {
                return null;
            }
            // Sebagian penulis menaruh teks langsung tanpa <v>.
            return self::collectText($inner) ?: null;
        }
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');

        if ($type === 'b') {
            return $raw === '1' ? '1' : '0';
        }

        // Angka bergaya tanggal -> ubah dari serial Excel ke teks tanggal.
        if ($style !== null && is_numeric($raw) && ($this->dateStyles[(int) $style] ?? false)) {
            $converted = self::excelSerialToDate((float) $raw);
            if ($converted !== null) {
                return $converted;
            }
        }

        return $raw;
    }

    public static function excelSerialToDate(float $serial): ?string
    {
        if ($serial <= 0 || $serial > 2958465) { // > 9999-12-31
            return null;
        }
        // Epoch 1900 dengan bug tahun kabisat 1900 milik Excel.
        $days = (int) floor($serial);
        $frac = $serial - $days;
        if ($days > 59) {
            $days--; // kompensasi 29 Feb 1900 yang tidak pernah ada
        }
        $ts = mktime(0, 0, 0, 1, 1, 1900) + ($days - 1) * 86400;
        $seconds = (int) round($frac * 86400);
        $ts += $seconds;

        return $frac > 0
            ? date('Y-m-d H:i:s', $ts)
            : date('Y-m-d', $ts);
    }

    /** "BM12" => [64, 12] (index kolom 0-based, nomor baris) */
    public static function refToPos(string $ref): array
    {
        $col = 0;
        $row = 0;
        $len = strlen($ref);
        $i = 0;
        while ($i < $len) {
            $ch = $ref[$i];
            if ($ch >= 'A' && $ch <= 'Z') {
                $col = $col * 26 + (ord($ch) - 64);
            } elseif ($ch >= '0' && $ch <= '9') {
                $row = (int) substr($ref, $i);
                break;
            }
            $i++;
        }
        return [$col - 1, $row];
    }

    private static function firstTag(string $xml, string $tag): ?string
    {
        $open = '<' . $tag;
        $pos = strpos($xml, $open);
        if ($pos === false) {
            return null;
        }
        $gt = strpos($xml, '>', $pos);
        if ($gt === false) {
            return null;
        }
        if ($xml[$gt - 1] === '/') {
            return '';
        }
        $close = strpos($xml, '</' . $tag . '>', $gt);
        if ($close === false) {
            return null;
        }
        return substr($xml, $gt + 1, $close - $gt - 1);
    }

    /** Menggabungkan seluruh isi elemen <t> (rich text dipecah jadi banyak <t>). */
    private static function collectText(string $xml): string
    {
        if ($xml === '') {
            return '';
        }
        // Buang bagian ruby/phonetic agar teks tidak dobel.
        $xml = preg_replace('#<rPh\b.*?</rPh>#s', '', $xml) ?? $xml;
        if (preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $xml, $m) && $m[1] !== []) {
            return html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ---- akses isi zip -------------------------------------------------

    /** URI yang bisa dibuka XMLReader, atau null jika entry tidak ada. */
    private function entryUriOrNull(string $entry): ?string
    {
        if (!$this->entryExists($entry)) {
            return null;
        }
        return $this->entryUri($entry);
    }

    private function entryUri(string $entry): string
    {
        if (class_exists('ZipArchive')) {
            $uri = 'zip://' . $this->path . '#' . $entry;
            $fh = @fopen($uri, 'rb');
            if ($fh !== false) {
                fclose($fh);
                return $uri;
            }
        }
        return $this->extractToTemp($entry);
    }

    private function entryExists(string $entry): bool
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($this->path) === true) {
                $found = $zip->locateName($entry) !== false;
                $zip->close();
                return $found;
            }
        }
        return ZipStore::has($this->path, $entry);
    }

    private function entryContents(string $entry): ?string
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($this->path) === true) {
                $data = $zip->getFromName($entry);
                $zip->close();
                return $data === false ? null : $data;
            }
        }
        return ZipStore::read($this->path, $entry);
    }

    private function extractToTemp(string $entry): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Gagal membuat berkas sementara.');
        }
        $this->tempFiles[] = $tmp;

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($this->path) === true) {
                $in = $zip->getStream($entry);
                if ($in !== false) {
                    $out = fopen($tmp, 'wb');
                    while (!feof($in)) {
                        fwrite($out, (string) fread($in, 262144));
                    }
                    fclose($in);
                    fclose($out);
                    $zip->close();
                    return $tmp;
                }
                $zip->close();
            }
        }

        if (!ZipStore::extract($this->path, $entry, $tmp)) {
            throw new RuntimeException("Gagal mengekstrak '{$entry}' dari berkas XLSX.");
        }
        return $tmp;
    }
}

/**
 * Pembaca ZIP murni PHP (hanya butuh zlib) sebagai cadangan bila
 * ekstensi ext-zip tidak terpasang di container.
 */
final class ZipStore
{
    /** @return array<string,array{offset:int,csize:int,size:int,method:int}> */
    private static function index(string $path): array
    {
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return $cache[$path] = [];
        }

        // Cari End of Central Directory pada 64 KB terakhir.
        $size = filesize($path) ?: 0;
        $tail = min($size, 65557);
        fseek($fh, -$tail, SEEK_END);
        $buf = (string) fread($fh, $tail);
        $eocd = strrpos($buf, "PK\x05\x06");
        if ($eocd === false) {
            fclose($fh);
            return $cache[$path] = [];
        }
        $head = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoffset/vcomment', substr($buf, $eocd + 4, 18));
        $entries = [];

        fseek($fh, (int) $head['cdoffset']);
        $cd = (string) fread($fh, (int) $head['cdsize']);
        $p = 0;
        for ($i = 0; $i < (int) $head['total']; $i++) {
            if (substr($cd, $p, 4) !== "PK\x01\x02") {
                break;
            }
            $f = unpack(
                'vversion/vminver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vsize/vnamelen/vextralen/vcommentlen/vdisk/vinattr/Voutattr/Voffset',
                substr($cd, $p + 4, 42)
            );
            $name = substr($cd, $p + 46, (int) $f['namelen']);
            $entries[$name] = [
                'offset' => (int) $f['offset'],
                'csize'  => (int) $f['csize'],
                'size'   => (int) $f['size'],
                'method' => (int) $f['method'],
            ];
            $p += 46 + (int) $f['namelen'] + (int) $f['extralen'] + (int) $f['commentlen'];
        }
        fclose($fh);

        return $cache[$path] = $entries;
    }

    public static function has(string $path, string $entry): bool
    {
        return isset(self::index($path)[$entry]);
    }

    public static function read(string $path, string $entry): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zs_');
        if ($tmp === false || !self::extract($path, $entry, $tmp)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return null;
        }
        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data === false ? null : $data;
    }

    /** Ekstraksi streaming agar file 26 MB tidak dimuat sekaligus ke memori. */
    public static function extract(string $path, string $entry, string $destFile): bool
    {
        $meta = self::index($path)[$entry] ?? null;
        if ($meta === null) {
            return false;
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        fseek($fh, $meta['offset']);
        $local = (string) fread($fh, 30);
        if (substr($local, 0, 4) !== "PK\x03\x04") {
            fclose($fh);
            return false;
        }
        $lf = unpack('vversion/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vsize/vnamelen/vextralen', substr($local, 4, 26));
        fseek($fh, $meta['offset'] + 30 + (int) $lf['namelen'] + (int) $lf['extralen']);

        $out = fopen($destFile, 'wb');
        if ($out === false) {
            fclose($fh);
            return false;
        }

        $remaining = $meta['csize'];
        if ($meta['method'] === 0) {           // disimpan tanpa kompresi
            while ($remaining > 0) {
                $chunk = (string) fread($fh, (int) min(262144, $remaining));
                if ($chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
                $remaining -= strlen($chunk);
            }
        } elseif ($meta['method'] === 8) {     // deflate
            $ctx = inflate_init(ZLIB_ENCODING_RAW);
            if ($ctx === false) {
                fclose($fh);
                fclose($out);
                return false;
            }
            while ($remaining > 0) {
                $chunk = (string) fread($fh, (int) min(262144, $remaining));
                if ($chunk === '') {
                    break;
                }
                $remaining -= strlen($chunk);
                $part = inflate_add($ctx, $chunk, $remaining > 0 ? ZLIB_NO_FLUSH : ZLIB_FINISH);
                if ($part === false) {
                    fclose($fh);
                    fclose($out);
                    return false;
                }
                fwrite($out, $part);
            }
        } else {
            fclose($fh);
            fclose($out);
            return false;
        }

        fclose($fh);
        fclose($out);
        return true;
    }
}
