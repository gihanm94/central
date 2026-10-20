<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\CurrentUser;

/**
 * Signatures and signed PDFs.
 *  - One signature image per person (storage/signatures, never public): uploaded or drawn by Management → Member signatures.
 *    Images are cleaned on the way in: re-drawn as PNG (no hidden data), a white paper background turned transparent,
 *    trimmed to the ink and kept at a sensible size.
 *  - Signed PDFs (storage/pdf-signs): the original and the signed copy of every signing, with fingerprints and placements.
 *    People see their own; Management and administrators see all of them.
 */
final class Signatures
{
    public const MAX_PDF = 20 * 1024 * 1024;          // per file
    private const SIG_DIR = '/storage/signatures';
    private const PDF_DIR = '/storage/pdf-signs';

    /**
     * Largest PDF that can be signed here: 20 MB, or less when PHP's upload limits are lower
     * (the original and the signed copy travel in one request, so each gets half of post_max_size).
     */
    public static function maxPdf(): int
    {
        $bytes = function (string $v): int {
            $v = trim($v); $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
        };
        $up = $bytes((string) ini_get('upload_max_filesize')) ?: self::MAX_PDF;
        $post = $bytes((string) ini_get('post_max_size')) ?: self::MAX_PDF * 2;

        return max(512 * 1024, min(self::MAX_PDF, $up, (int) floor($post / 2) - 64 * 1024));
    }

    /** Management and administrators manage signatures and read the signing log. */
    public static function canAudit(CurrentUser $u): bool
    {
        return $u->isAdmin() || $u->can('management', 'view');
    }

    private static function dir(string $sub): string
    {
        $dir = BASE_PATH.$sub;
        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        if (! is_file(BASE_PATH.'/storage/.htaccess')) {      // storage is outside the web root; this is a second lock
            @file_put_contents(BASE_PATH.'/storage/.htaccess', "Require all denied\n");
        }

        return $dir;
    }

    /* ------------------------------------------------------------ signatures */

    public static function of(int $userId): ?array
    {
        return DB::first('SELECT * FROM user_signatures WHERE user_id = ?', [$userId]);
    }

    /** Absolute path of a person's signature PNG, or null. */
    public static function imagePath(int $userId): ?string
    {
        $row = self::of($userId);
        if (! $row || ! preg_match('/^[a-f0-9]{24}\.png$/', (string) $row['file'])) {
            return null;
        }
        $p = self::dir(self::SIG_DIR).'/'.$row['file'];

        return is_file($p) ? $p : null;
    }

    /**
     * Save a signature from image bytes (an uploaded PNG/JPG/WebP, or the PNG a signature pad drew).
     * @throws ValidationException when the image cannot be used
     */
    public static function save(int $userId, string $bytes, string $source, int $by): void
    {
        if ($bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            throw new ValidationException(['signature' => __('Use an image of 5 MB or smaller.')]);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new ValidationException(['signature' => __('Use a PNG, JPG or WebP image.')]);
        }
        if ($info[0] * $info[1] > 25_000_000) {
            throw new ValidationException(['signature' => __('This image is too large. Use a smaller photo.')]);
        }
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new ValidationException(['signature' => __('This image could not be read.')]);
        }
        $png = self::clean($src, $source === 'upload');
        imagedestroy($src);
        if ($png === null) {
            throw new ValidationException(['signature' => __('No signature found in the image. Use a dark signature on a light background.')]);
        }

        $name = bin2hex(random_bytes(12)).'.png';
        file_put_contents(self::dir(self::SIG_DIR).'/'.$name, $png);
        $old = self::of($userId);
        DB::exec('INSERT INTO user_signatures (user_id, file, source, updated_by, updated_at) VALUES (?, ?, ?, ?, now())
                  ON CONFLICT (user_id) DO UPDATE SET file = EXCLUDED.file, source = EXCLUDED.source, updated_by = EXCLUDED.updated_by, updated_at = now()',
            [$userId, $name, $source === 'drawn' ? 'drawn' : 'upload', $by]);
        if ($old && preg_match('/^[a-f0-9]{24}\.png$/', (string) $old['file'])) {
            @unlink(self::dir(self::SIG_DIR).'/'.$old['file']);
        }
    }

    public static function remove(int $userId): bool
    {
        $old = self::of($userId);
        if (! $old) {
            return false;
        }
        DB::exec('DELETE FROM user_signatures WHERE user_id = ?', [$userId]);
        if (preg_match('/^[a-f0-9]{24}\.png$/', (string) $old['file'])) {
            @unlink(self::dir(self::SIG_DIR).'/'.$old['file']);
        }

        return true;
    }

    /**
     * Re-draw as a transparent PNG trimmed to the ink, at most 1200 × 500 px.
     * $paper: a photo or scan, so light pixels become transparent; a drawn pad image keeps its own transparency.
     */
    private static function clean(\GdImage $src, bool $paper): ?string
    {
        // work on at most 1600 px (a phone photo is far bigger than a signature needs)
        $sw = imagesx($src);
        $sh = imagesy($src);
        $k = min(1.0, 1600 / max($sw, $sh));
        $w = max(1, (int) round($sw * $k));
        $h = max(1, (int) round($sh * $k));
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagealphablending($img, true);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagealphablending($img, false);

        // ink = pixels dark enough and not transparent; on paper, everything light turns transparent
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
        $clear = imagecolorallocatealpha($img, 255, 255, 255, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                if ($paper) {
                    if ($lum > 200) {
                        imagesetpixel($img, $x, $y, $clear);
                        continue;
                    }
                    if ($lum > 140) {                        // soft edge: partly transparent
                        $alpha = (int) round(($lum - 140) / 60 * 127);
                        imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $alpha));
                    }
                } elseif ($a >= 120) {
                    continue;
                }
                if ($x < $minX) { $minX = $x; }
                if ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; }
                if ($y > $maxY) { $maxY = $y; }
            }
        }
        if ($maxX < 0) {
            imagedestroy($img);

            return null;
        }
        $pad = 6;
        $minX = max(0, $minX - $pad); $minY = max(0, $minY - $pad);
        $cw = min($w, $maxX + $pad + 1) - $minX; $ch = min($h, $maxY + $pad + 1) - $minY;
        $scale = min(1.0, 1200 / $cw, 500 / $ch);
        $ow = max(1, (int) round($cw * $scale)); $oh = max(1, (int) round($ch * $scale));
        $out = imagecreatetruecolor($ow, $oh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $img, 0, 0, $minX, $minY, $ow, $oh, $cw, $ch);
        imagedestroy($img);
        ob_start();
        imagepng($out, null, 9);
        imagedestroy($out);

        return (string) ob_get_clean();
    }

    /* ---------------------------------------------------------- signed PDFs */

    private static function isPdf(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        $head = $fh ? (string) fread($fh, 1024) : '';
        if ($fh) { fclose($fh); }

        return str_contains($head, '%PDF-');
    }

    /**
     * Keep one signing: both files (checked to be PDFs), fingerprints and where the signatures went.
     * @param array $original, $signed  uploaded files (Request::file)
     * @param list<array{page:int,x:float,y:float,w:float,h:float}> $placements
     */
    public static function record(CurrentUser $u, array $original, array $signed, array $placements, int $pages): int
    {
        foreach (['original' => $original, 'signed' => $signed] as $k => $f) {
            if (in_array($f['error'] ?? 1, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new ValidationException(['file' => __('PDF files must be :size or smaller.', ['size' => Files::size(self::maxPdf())])]);
            }
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file($f['tmp_name'])) {
                throw new ValidationException(['file' => __('The upload failed. Try a smaller file.')]);
            }
            if ($f['size'] > self::maxPdf()) {
                throw new ValidationException(['file' => __('PDF files must be :size or smaller.', ['size' => Files::size(self::maxPdf())])]);
            }
            if (! self::isPdf($f['tmp_name'])) {
                throw new ValidationException(['file' => __('This is not a PDF file.')]);
            }
        }
        if (! $placements) {
            throw new ValidationException(['file' => __('Place your signature on the document first.')]);
        }

        $dir = self::dir(self::PDF_DIR.'/'.date('Y'));
        $key = bin2hex(random_bytes(12));
        $orig = date('Y').'/'.$key.'-original.pdf';
        $sign = date('Y').'/'.$key.'-signed.pdf';
        move_uploaded_file($original['tmp_name'], $dir.'/'.$key.'-original.pdf');
        move_uploaded_file($signed['tmp_name'], $dir.'/'.$key.'-signed.pdf');
        $title = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $original['name']) ?? ''), 0, 200) ?: 'document.pdf';

        $id = (int) DB::insert('pdf_signatures', [
            'user_id' => $u->id, 'user_name' => $u->name, 'department_id' => $u->department_id, 'title' => $title,
            'original_file' => $orig, 'signed_file' => $sign,
            'original_sha256' => hash_file('sha256', $dir.'/'.$key.'-original.pdf'), 'signed_sha256' => hash_file('sha256', $dir.'/'.$key.'-signed.pdf'),
            'original_size' => (int) filesize($dir.'/'.$key.'-original.pdf'), 'signed_size' => (int) filesize($dir.'/'.$key.'-signed.pdf'),
            'pages' => max(0, min(100000, $pages)), 'placements' => json_encode($placements),
            'ip_address' => Request::ip(), 'user_agent' => Request::userAgent(),
        ]);
        Activity::log('created', 'pdf_signature', $id, $title, ['Signed the PDF ":label"', ['label' => $title]],
            ['signatures' => count($placements), 'pages' => $pages, 'sha256' => DB::scalar('SELECT signed_sha256 FROM pdf_signatures WHERE id = ?', [$id])], null, notify: false);

        return $id;
    }

    /** One signing this person may open: their own, or any for Management / administrators. */
    public static function find(CurrentUser $u, int $id): ?array
    {
        $row = DB::first('SELECT s.*, u.avatar, d.name AS department_name FROM pdf_signatures s LEFT JOIN users u ON u.id = s.user_id
                          LEFT JOIN departments d ON d.id = s.department_id WHERE s.id = ?', [$id]);
        if (! $row || ((int) $row['user_id'] !== $u->id && ! self::canAudit($u))) {
            return null;
        }

        return $row;
    }

    public static function filePath(array $row, string $kind): ?string
    {
        $rel = $kind === 'original' ? $row['original_file'] : $row['signed_file'];
        if (! preg_match('#^\d{4}/[a-f0-9]{24}-(original|signed)\.pdf$#', (string) $rel)) {
            return null;
        }
        $p = BASE_PATH.self::PDF_DIR.'/'.$rel;

        return is_file($p) ? $p : null;
    }

    /**
     * The signing log. $onlyUser: one person's own list.
     * @param array{q?:string, user?:int, from?:string, to?:string, page?:int} $f
     */
    public static function list(array $f, ?int $onlyUser = null, int $per = 25): array
    {
        $w = ['TRUE'];
        $p = [];
        if ($onlyUser !== null) { $w[] = 's.user_id = ?'; $p[] = $onlyUser; }
        elseif (! empty($f['user'])) { $w[] = 's.user_id = ?'; $p[] = (int) $f['user']; }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)).'%';
            $w[] = '(s.title ILIKE ? OR s.user_name ILIKE ?)';
            array_push($p, $like, $like);
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$k] ?? ''))) {
                $w[] = $k === 'to' ? 's.created_at < ?::date + 1' : 's.created_at >= ?::date';
                $p[] = $f[$k];
            }
        }
        $where = implode(' AND ', $w);
        $page = max(1, (int) ($f['page'] ?? 1));
        $total = (int) DB::scalar("SELECT count(*) FROM pdf_signatures s WHERE {$where}", $p);
        $rows = DB::select("SELECT s.id, s.user_id, s.user_name, s.title, s.pages, jsonb_array_length(s.placements) AS signs, s.signed_size, s.created_at,
                u.avatar, d.name AS department_name
            FROM pdf_signatures s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
            WHERE {$where} ORDER BY s.created_at DESC, s.id DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $p);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))];
    }
}
