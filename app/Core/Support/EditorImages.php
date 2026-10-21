<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Files behind the pictures of rich-text fields. The editor uploads a picture (EditorController) and puts its link
 * in the text; nothing is stored as base64. When a record is saved, its pictures are noted against it; a picture
 * no record uses any more is deleted with its file — at once when it is taken out of a text, after a day when it
 * was uploaded but the form was never saved.
 */
final class EditorImages
{
    /** @return string[] editor/<name>.<ext> of every picture in the text */
    public static function in(?string $html): array
    {
        preg_match_all('#uploads/(editor/[a-f0-9]{24}\.(?:png|jpg|webp|gif))#i', (string) $html, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** A new upload (not used anywhere yet). */
    public static function uploaded(string $file, ?int $userId): void
    {
        DB::exec('INSERT INTO editor_images (file, created_by) VALUES (?, ?) ON CONFLICT (file) DO NOTHING', [$file, $userId]);
    }

    /**
     * The field $ref (conn.table.id.field) now holds $html; it held $before. Pictures taken out are deleted when
     * nothing else uses them. $stillUsed tells whether a picture the registry does not know (uploaded before it
     * existed) is still in another record.
     */
    public static function saved(string $ref, ?string $html, ?string $before, callable $stillUsed): void
    {
        $now = self::in($html);
        $was = self::in($before);
        foreach ($now as $f) {
            DB::exec('INSERT INTO editor_images (file) VALUES (?) ON CONFLICT (file) DO NOTHING', [$f]);
            DB::exec('INSERT INTO editor_image_uses (file, ref) VALUES (?, ?) ON CONFLICT DO NOTHING', [$f, $ref]);
        }
        $gone = array_diff($was, $now);
        DB::exec('DELETE FROM editor_image_uses WHERE ref = ?'.($now ? ' AND file NOT IN ('.implode(',', array_fill(0, count($now), '?')).')' : ''), [$ref, ...$now]);
        foreach ($gone as $f) {
            if (! DB::scalar('SELECT 1 FROM editor_image_uses WHERE file = ? LIMIT 1', [$f]) && ! $stillUsed($f)) {
                self::remove($f);
            }
        }
    }

    /** Uploads older than a day that no record ever took (the form was closed without saving). */
    public static function sweep(): void
    {
        foreach (DB::select("SELECT i.file FROM editor_images i WHERE i.created_at < now() - interval '1 day'
                               AND NOT EXISTS (SELECT 1 FROM editor_image_uses u WHERE u.file = i.file) LIMIT 200") as $r) {
            self::remove($r['file']);
        }
    }

    private static function remove(string $file): void
    {
        if (preg_match('#^editor/[a-f0-9]{24}\.(png|jpg|webp|gif)$#', $file)) {
            Upload::delete($file);
        }
        DB::exec('DELETE FROM editor_images WHERE file = ?', [$file]);
    }
}
