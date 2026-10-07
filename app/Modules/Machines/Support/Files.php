<?php
declare(strict_types=1);

namespace App\Modules\Machines\Support;

use App\Core\Support\ValidationException;

/**
 * Documents of a machine (manuals, work instructions, certificates, warranty papers).
 * Stored outside public/ in storage/machines under a random name; the database keeps a JSON list
 * [{"f": "<random>.pdf", "n": "Manual.pdf", "s": 12345}] and people download through /machines/file/<random>.pdf (signed-in only).
 */
final class Files
{
    public const MAX_BYTES = 15 * 1048576;
    private const EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'zip'];

    public static function extensions(): array { return self::EXT; }

    public static function dir(): string
    {
        $d = BASE_PATH.'/storage/machines';
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    /** Save every file of a multiple-file input ($_FILES-style array) and return their descriptions. */
    public static function saveMany(?array $input, string $field = 'files'): array
    {
        $out = [];
        if (! $input || ! isset($input['name'])) {
            return $out;
        }
        $names = (array) $input['name'];
        foreach ($names as $i => $name) {
            $err = is_array($input['error']) ? $input['error'][$i] : $input['error'];
            if ($err === UPLOAD_ERR_NO_FILE || $name === '' || $name === null) {
                continue;
            }
            $tmp  = is_array($input['tmp_name']) ? $input['tmp_name'][$i] : $input['tmp_name'];
            $size = (int) (is_array($input['size']) ? $input['size'][$i] : $input['size']);
            $out[] = self::saveOne(['name' => $name, 'tmp_name' => $tmp, 'error' => $err, 'size' => $size], $field);
        }

        return $out;
    }

    public static function saveOne(array $file, string $field = 'files'): array
    {
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException([$field => __('The upload of ":f" failed. Try a smaller file.', ['f' => $file['name'] ?? ''])]);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new ValidationException([$field => __('":f" is larger than :mb MB.', ['f' => $file['name'], 'mb' => self::MAX_BYTES / 1048576])]);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXT, true)) {
            throw new ValidationException([$field => __('":f": this file type is not allowed (:types).', ['f' => $file['name'], 'types' => implode(', ', self::EXT)])]);
        }
        $stored = bin2hex(random_bytes(12)).'.'.$ext;
        move_uploaded_file($file['tmp_name'], self::dir().'/'.$stored);

        return ['f' => $stored, 'n' => mb_substr(preg_replace('/[\r\n\/\\\\]+/', '_', (string) $file['name']), 0, 160), 's' => (int) $file['size']];
    }

    /** JSON column → list. */
    public static function decode(?string $json): array
    {
        $list = json_decode((string) $json, true);

        return is_array($list) ? array_values(array_filter($list, fn ($x) => is_array($x) && ! empty($x['f']))) : [];
    }

    public static function encode(array $list): ?string
    {
        return $list ? json_encode(array_values($list), JSON_UNESCAPED_UNICODE) : null;
    }

    /** Keep the files the form still lists (names in $keep) and add new ones. */
    public static function merge(?string $current, array $keep, array $added): ?string
    {
        $kept = array_values(array_filter(self::decode($current), fn ($x) => in_array($x['f'], $keep, true)));
        foreach (self::decode($current) as $x) {
            if (! in_array($x['f'], $keep, true)) {
                @unlink(self::dir().'/'.basename($x['f']));
            }
        }

        return self::encode(array_merge($kept, $added));
    }

    public static function path(string $stored): ?string
    {
        $p = self::dir().'/'.basename($stored);

        return preg_match('/^[a-f0-9]{24}\.[a-z0-9]{2,5}$/', basename($stored)) && is_file($p) ? $p : null;
    }

    /** Original name of a stored file (searched in the given JSON columns). */
    public static function label(string $stored, array $jsonColumns): ?string
    {
        foreach ($jsonColumns as $json) {
            foreach (self::decode($json) as $x) {
                if ($x['f'] === $stored) {
                    return $x['n'];
                }
            }
        }

        return null;
    }
}
