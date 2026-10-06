<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Support\DB;
use App\Core\Support\ValidationException;

/**
 * Files on CRM records. They are kept in storage/attachments (not reachable from the web)
 * under a random name and only handed out by the download route after the record check.
 */
final class Attachments
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** extension => allowed mime types (finfo is the judge, the browser's claim is ignored) */
    private const TYPES = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'txt'  => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    public static function extensions(): array { return array_keys(self::TYPES); }

    public static function dir(): string
    {
        $dir = BASE_PATH.'/storage/attachments';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** $_FILES[$key] with one or many files → list of single-file arrays (empty slots dropped). */
    public static function incoming(string $key): array
    {
        $f = $_FILES[$key] ?? null;
        if (! $f) {
            return [];
        }
        if (! is_array($f['name'])) {
            return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }

        return $out;
    }

    /** Throws a ValidationException if the file may not be stored. @return array [cleanName, extension, mime] */
    public static function check(array $file): array
    {
        $name = self::cleanName((string) $file['name']);
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException(['files' => __('":name" could not be uploaded. Try a smaller file.', ['name' => $name])]);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['files' => __('":name" is larger than :mb MB.', ['name' => $name, 'mb' => self::MAX_BYTES / 1048576])]);
        }
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
        if (! isset(self::TYPES[$ext]) || ! in_array($mime, self::TYPES[$ext], true)) {
            throw new ValidationException(['files' => __('":name" is not an allowed file type. Allowed: :types.', ['name' => $name, 'types' => implode(', ', self::extensions())])]);
        }

        return [$name, $ext, $mime];
    }

    /** @return array saved attachment row */
    public static function store(array $file, string $type, int $id, int $userId): array
    {
        [$name, $ext, $mime] = self::check($file);
        $stored = bin2hex(random_bytes(16)).'.'.$ext;
        if (! move_uploaded_file($file['tmp_name'], self::dir().'/'.$stored)) {
            throw new ValidationException(['files' => __('":name" could not be saved.', ['name' => $name])]);
        }
        $row = ['entity_type' => $type, 'entity_id' => $id, 'original_name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => (int) $file['size'], 'uploaded_by' => $userId];
        $row['id'] = (int) DB::insert('attachments', $row, 'crm');

        return $row;
    }

    public static function forRecord(string $type, int $id): array
    {
        $rows  = DB::select('SELECT * FROM attachments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC', [$type, $id], 'crm');
        $names = Access::names('users', array_column($rows, 'uploaded_by'));
        foreach ($rows as &$r) {
            $r['uploader'] = $names[$r['uploaded_by']] ?? '—';
        }

        return $rows;
    }

    public static function send(array $row): never
    {
        $path = self::dir().'/'.basename($row['stored_name']);
        if (! is_file($path)) {
            abort(404, __('The file is missing on the server.'));
        }
        $inline = str_starts_with((string) $row['mime'], 'image/') || $row['mime'] === 'application/pdf';
        header('Content-Type: '.($inline ? $row['mime'] : 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: '.($inline && isset($_GET['view']) ? 'inline' : 'attachment').'; filename="'.addcslashes(self::asciiName($row['original_name']), '"\\').'"; filename*=UTF-8\'\''.rawurlencode($row['original_name']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        readfile($path);
        exit;
    }

    public static function remove(array $row): void
    {
        DB::exec('DELETE FROM attachments WHERE id = ?', [$row['id']], 'crm');
        @unlink(self::dir().'/'.basename($row['stored_name']));
    }

    /** Remove every file of a record (when it is deleted for good). */
    public static function removeAll(string $type, int $id): void
    {
        foreach (DB::select('SELECT * FROM attachments WHERE entity_type = ? AND entity_id = ?', [$type, $id], 'crm') as $row) {
            self::remove($row);
        }
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\\\\\/]+/', '', basename(str_replace('\\', '/', $name))));

        return mb_substr($name !== '' ? $name : 'file', 0, 200);
    }

    private static function asciiName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'file';
    }
}
