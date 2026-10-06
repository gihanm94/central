<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Sandboxed file operations for the admin file manager. Everything happens inside one of a few named roots;
 * a path is a relative path inside a root and can never leave it (no "..", no symlink out, no hidden dot-files, no executable uploads).
 */
final class Files
{
    public const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'htaccess', 'htpasswd', 'ini', 'cgi', 'pl', 'sh', 'bat', 'cmd', 'exe', 'dll', 'so'];

    /** @return array<string, array{label: string, dir: string}> */
    public static function roots(): array
    {
        $b = BASE_PATH;

        return [
            'uploads'     => ['label' => __('Uploads (public)'), 'dir' => $b.'/public/uploads'],
            'attachments' => ['label' => __('Attachments'), 'dir' => $b.'/storage/attachments'],
            'inet'        => ['label' => __('E-tax files'), 'dir' => $b.'/storage/inet'],
            'billing'     => ['label' => __('Billing notes'), 'dir' => $b.'/storage/billing'],
            'mail'        => ['label' => __('Mail log'), 'dir' => $b.'/storage/mail'],
        ];
    }

    public static function root(string $key): string
    {
        $r = self::roots()[$key] ?? throw new \RuntimeException(__('Unknown folder.'));
        is_dir($r['dir']) || @mkdir($r['dir'], 0775, true);

        return realpath($r['dir']) ?: throw new \RuntimeException(__('The folder is not available.'));
    }

    /** "a/b" → clean segments, or throw. */
    public static function clean(string $rel): string
    {
        $parts = [];
        foreach (preg_split('#[\\\\/]+#', trim($rel, "/\\ ")) ?: [] as $p) {
            if ($p === '' || $p === '.') { continue; }
            if ($p === '..' || str_starts_with($p, '.') || preg_match('/[\x00-\x1f]/', $p)) { throw new \RuntimeException(__('That path is not allowed.')); }
            $parts[] = $p;
        }

        return implode('/', $parts);
    }

    /** Absolute path of a relative path; it must exist unless $mayCreate, and must stay inside the root. */
    public static function abs(string $rootKey, string $rel, bool $mayCreate = false): string
    {
        $root = self::root($rootKey);
        $rel  = self::clean($rel);
        $path = $rel === '' ? $root : $root.'/'.$rel;
        if (! $mayCreate || file_exists($path)) {
            $real = realpath($path);
            if ($real === false || ($real !== $root && ! str_starts_with($real, $root.'/'))) { throw new \RuntimeException(__('Not found.')); }

            return $real;
        }
        $parent = realpath(dirname($path));                                 // a new thing: its folder must exist inside the root
        if ($parent === false || ($parent !== $root && ! str_starts_with($parent, $root.'/'))) { throw new \RuntimeException(__('Not found.')); }

        return $parent.'/'.basename($path);
    }

    public static function name(string $n): string
    {
        $n = trim(str_replace(["\0", '/', '\\'], '', $n));
        if ($n === '' || $n === '.' || $n === '..' || str_starts_with($n, '.') || mb_strlen($n) > 180) { throw new \RuntimeException(__('Use a plain name (no slashes, not starting with a dot).')); }
        if (in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), self::BLOCKED, true)) { throw new \RuntimeException(__('Files of this type are not allowed.')); }

        return $n;
    }

    /** One folder, sorted and paged; or a recursive name search. @return array{rows: array, total: int} */
    public static function list(string $rootKey, string $rel, string $q, string $sort, string $dir, int $page, int $per): array
    {
        $root = self::root($rootKey);
        $base = self::abs($rootKey, $rel);
        $rows = [];
        $add = function (string $full) use ($root, &$rows) {
            $name = basename($full);
            if ($name[0] === '.' || is_link($full)) { return; }
            $isDir = is_dir($full);
            $rows[] = ['name' => $name, 'rel' => ltrim(substr($full, strlen($root)), '/'), 'dir' => $isDir, 'size' => $isDir ? null : (int) @filesize($full), 'mtime' => (int) @filemtime($full),
                'ext' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'parent' => ltrim(substr(dirname($full), strlen($root)), '/')];
        };
        if ($q !== '') {                                                    // search below the current folder (stops at 20 000 entries)
            $needle = mb_strtolower($q);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            $n = 0;
            foreach ($it as $f) {
                if (++$n > 20000) { break; }
                if (str_contains(mb_strtolower($f->getFilename()), $needle)) { $add($f->getPathname()); }
            }
        } else {
            foreach (scandir($base) ?: [] as $f) { if ($f !== '.' && $f !== '..') { $add($base.'/'.$f); } }
        }
        $key = in_array($sort, ['name', 'size', 'mtime', 'ext'], true) ? $sort : 'name';
        $mul = $dir === 'desc' ? -1 : 1;
        usort($rows, function ($a, $b) use ($key, $mul) {
            if ($a['dir'] !== $b['dir']) { return $a['dir'] ? -1 : 1; }                                           // folders first
            $c = $key === 'name' ? strnatcasecmp($a['name'], $b['name']) : (($a[$key] ?? 0) <=> ($b[$key] ?? 0));

            return $c === 0 ? strnatcasecmp($a['name'], $b['name']) : $c * $mul;
        });

        return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows)];
    }

    /** All folders below the root (for the "move to" list), capped. @return string[] relative paths, '' = the root itself */
    public static function folders(string $rootKey, int $cap = 400): array
    {
        $root = self::root($rootKey);
        $out = [''];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if ($f->isDir() && ! $f->isLink() && $f->getFilename()[0] !== '.') { $out[] = ltrim(substr($f->getPathname(), strlen($root)), '/'); }
            if (count($out) >= $cap) { break; }
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    public static function delete(string $abs): void
    {
        if (is_dir($abs) && ! is_link($abs)) {
            foreach (scandir($abs) ?: [] as $f) { if ($f !== '.' && $f !== '..') { self::delete($abs.'/'.$f); } }
            @rmdir($abs);
        } else { @unlink($abs); }
    }

    public static function size(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) { if ($b < 1024 || $i === 3) { return ($i ? number_format($b, $b < 10 ? 1 : 0) : (string) $b).' '.$u; } $b /= 1024; }

        return '';
    }
}
