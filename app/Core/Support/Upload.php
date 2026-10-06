<?php
declare(strict_types=1);

namespace App\Core\Support;

/** Safe image uploads into public/uploads/<folder>. Returns "folder/file.ext". */
final class Upload
{
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/gif' => 'gif'];

    public static function image(array $file, string $folder, int $maxKb = 2048, bool $allowSvg = false): string
    {
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException([$folder => 'The upload failed. Try a smaller file.']);
        }
        if ($file['size'] > $maxKb * 1024) {
            throw new ValidationException([$folder => 'Images must be '.round($maxKb / 1024, 1).' MB or smaller.']);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if ($mime === 'text/xml' || $mime === 'text/plain') {
            $mime = str_ends_with(strtolower($file['name']), '.svg') ? 'image/svg+xml' : $mime;
        }
        if (! isset(self::TYPES[$mime]) || ($mime === 'image/svg+xml' && ! $allowSvg)) {
            throw new ValidationException([$folder => 'Use a PNG, JPG or WebP image'.($allowSvg ? ' (or SVG)' : '').'.']);
        }
        if ($mime === 'image/svg+xml' && preg_match('/<script|on\w+\s*=|javascript:/i', (string) file_get_contents($file['tmp_name']))) {
            throw new ValidationException([$folder => 'This SVG contains scripts and was rejected.']);
        }

        $dir = BASE_PATH.'/public/uploads/'.$folder;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(12)).'.'.self::TYPES[$mime];
        move_uploaded_file($file['tmp_name'], $dir.'/'.$name);

        return $folder.'/'.$name;
    }

    public static function delete(?string $path): void
    {
        if ($path && ! str_contains($path, '..')) {
            @unlink(BASE_PATH.'/public/uploads/'.$path);
        }
    }
}
