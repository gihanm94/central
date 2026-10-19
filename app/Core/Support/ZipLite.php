<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * The few ZipArchive calls the Excel writer needs (addFromString, addFile, close), for servers without PHP's zip
 * extension. Files are deflated when zlib is there, otherwise stored.
 */
final class ZipLite
{
    /** @var list<array{name: string, data: string}> */
    private array $files = [];

    public function __construct(private readonly string $path) {}

    public function addFromString(string $name, string $data): bool { $this->files[] = ['name' => $name, 'data' => $data]; return true; }

    public function addFile(string $file, string $name): bool { return $this->addFromString($name, (string) file_get_contents($file)); }

    public function close(): bool
    {
        $out = fopen($this->path, 'wb');
        if (! $out) {
            return false;
        }
        $central = '';
        $offset = 0;
        [$time, $date] = self::dos(time());
        foreach ($this->files as $f) {
            $crc = crc32($f['data']);
            $deflate = function_exists('gzdeflate');
            $body = $deflate ? (string) gzdeflate($f['data'], 6) : $f['data'];
            $method = $deflate ? 8 : 0;
            $name = $f['name'];
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($f['data']), strlen($name), 0).$name;
            fwrite($out, $local.$body);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($f['data']), strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($local) + strlen($body);
        }
        fwrite($out, $central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->files), count($this->files), strlen($central), $offset, 0));

        return fclose($out);
    }

    /** @return array{0: int, 1: int} DOS time and date */
    private static function dos(int $ts): array
    {
        $d = getdate($ts);

        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
