<?php
declare(strict_types=1);

/**
 * Konfigurasi aplikasi.
 * Semua nilai bisa di-override lewat environment variable pada container,
 * atau lewat berkas .env di root project.
 */
final class Config
{
    private static array $data = [];

    public static function load(): void
    {
        if (self::$data !== []) {
            return;
        }

        $envFile = dirname(__DIR__) . '/.env';
        $fromFile = [];
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $fromFile[trim($k)] = trim($v, " \t\"'");
            }
        }

        $get = static function (string $key, string $default) use ($fromFile): string {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                return $env;
            }
            return $fromFile[$key] ?? $default;
        };

        self::$data = [
            'db_host'     => $get('DB_HOST', 'mariadb'),
            'db_port'     => (int) $get('DB_PORT', '3306'),
            'db_name'     => $get('DB_NAME', 'ecommerce'),
            'db_user'     => $get('DB_USER', 'ecommerce'),
            'db_pass'     => $get('DB_PASS', ''),
            'app_name'    => $get('APP_NAME', 'E-Commerce Analytics'),
            'app_tz'      => $get('APP_TZ', 'Asia/Jakarta'),
            'storage_dir' => $get('STORAGE_DIR', dirname(__DIR__) . '/storage/uploads'),
            'keep_raw'    => $get('KEEP_RAW_JSON', '1') === '1',
            'keep_files'  => $get('KEEP_UPLOADED_FILES', '1') === '1',
            'batch_size'  => (int) $get('IMPORT_BATCH_SIZE', '400'),
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$data[$key] ?? $default;
    }
}
